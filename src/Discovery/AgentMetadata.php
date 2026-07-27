<?php

namespace Redberry\Synapse\Discovery;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\CircularDependencyException;
use Laravel\Ai\Attributes\Model as ModelAttribute;
use Laravel\Ai\Attributes\Provider as ProviderAttribute;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Attributes\UseSmartestModel;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Enums\Lab;
use ReflectionClass;
use Throwable;

/**
 * Reads the card-level metadata for one agent class.
 *
 * Resolution order mirrors the SDK's Promptable::getProvidersAndModels():
 * provider()/model() methods take precedence over the #[Provider]/#[Model]
 * attributes.
 */
class AgentMetadata
{
    public function __construct(
        protected ToolClassifier $tools,
        protected InstantiationDiagnostic $diagnostic,
    ) {}

    /**
     * @param  class-string  $class
     */
    public function for(string $class): DiscoveredAgent
    {
        try {
            $agent = app($class);
        } catch (BindingResolutionException|CircularDependencyException $e) {
            // The container itself failed — we know this is a wiring problem, so
            // the constructor is inspected to say which parameter and how to fix it.
            return DiscoveredAgent::unavailable(
                $class,
                $e->getMessage(),
                'binding',
                $this->diagnostic->unresolvable($class),
            );
        } catch (Throwable $e) {
            // Anything else: the constructor body threw, a type error, etc.
            return DiscoveredAgent::unavailable($class, $e->getMessage(), 'exception');
        }

        try {
            $reflection = new ReflectionClass($agent);
            $model = $this->model($agent, $reflection);

            return new DiscoveredAgent(
                slug: AgentSlug::make($class),
                name: class_basename($class),
                class: $class,
                provider: $this->provider($agent, $reflection) ?? $this->defaultProvider(),
                model: $model,
                modelTier: $model === null ? $this->modelTier($reflection) : 'default',
                tools: $this->tools->classify($agent),
                capabilities: $this->capabilities($agent),
            );
        } catch (Throwable $e) {
            return DiscoveredAgent::unavailable($class, $e->getMessage(), 'exception');
        }
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     */
    protected function provider(object $agent, ReflectionClass $reflection): ?string
    {
        if (method_exists($agent, 'provider')) {
            return $this->normalizeProvider($agent->provider());
        }

        $attributes = $reflection->getAttributes(ProviderAttribute::class);

        return $attributes === []
            ? null
            : $this->normalizeProvider($attributes[0]->newInstance()->value);
    }

    /**
     * A provider may be a Lab enum, a plain string, or a failover list. The card
     * shows the primary one.
     */
    protected function normalizeProvider(mixed $provider): ?string
    {
        if (is_array($provider)) {
            $provider = $provider === [] ? null : reset($provider);
        }

        return match (true) {
            $provider instanceof Lab => $provider->value,
            is_string($provider) => $provider,
            default => null,
        };
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     */
    protected function model(object $agent, ReflectionClass $reflection): ?string
    {
        if (method_exists($agent, 'model')) {
            $model = $agent->model();

            return is_string($model) ? $model : null;
        }

        $attributes = $reflection->getAttributes(ModelAttribute::class);

        return $attributes === [] ? null : $attributes[0]->newInstance()->value;
    }

    /**
     * The tier the SDK would fall back to when no model is declared.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    protected function modelTier(ReflectionClass $reflection): string
    {
        return match (true) {
            $reflection->getAttributes(UseSmartestModel::class) !== [] => 'smartest',
            $reflection->getAttributes(UseCheapestModel::class) !== [] => 'cheapest',
            default => 'default',
        };
    }

    protected function defaultProvider(): ?string
    {
        $default = config('ai.default');

        return is_string($default) ? $default : null;
    }

    /**
     * @return array<string, bool>
     */
    protected function capabilities(object $agent): array
    {
        return [
            'conversational' => $agent instanceof Conversational,
            'remembers_conversations' => $agent instanceof RemembersConversations,
            'has_tools' => $agent instanceof HasTools,
            'has_structured_output' => $agent instanceof HasStructuredOutput,
            'has_middleware' => $agent instanceof HasMiddleware,
            'can_act_as_tool' => $agent instanceof CanActAsTool,
        ];
    }
}
