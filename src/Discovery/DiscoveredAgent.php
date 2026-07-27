<?php

namespace Redberry\Synapse\Discovery;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A single agent as the dashboard sees it.
 *
 * @implements Arrayable<string, mixed>
 */
class DiscoveredAgent implements Arrayable
{
    /**
     * @param  array<int, array{name: string, type: string}>  $tools
     * @param  array<string, bool>  $capabilities
     * @param  array<int, array{parameter: string, type: string|null, reason: string}>  $unresolvable
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $class,
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly string $modelTier = 'default',
        public readonly array $tools = [],
        public readonly array $capabilities = [],
        public readonly bool $available = true,
        public readonly ?string $error = null,
        public readonly ?string $errorKind = null,
        public readonly array $unresolvable = [],
    ) {}

    /**
     * Build the payload for an agent that could not be instantiated.
     *
     * @param  'binding'|'exception'  $errorKind  Whether the container failed to
     *                                            wire the agent, or something else threw.
     * @param  array<int, array{parameter: string, type: string|null, reason: string}>  $unresolvable
     */
    public static function unavailable(
        string $class,
        string $error,
        string $errorKind,
        array $unresolvable = [],
    ): self {
        return new self(
            slug: AgentSlug::make($class),
            name: class_basename($class),
            class: $class,
            available: false,
            error: $error,
            errorKind: $errorKind,
            unresolvable: $unresolvable,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'class' => $this->class,
            'provider' => $this->provider,
            'model' => $this->model,
            'model_tier' => $this->modelTier,
            'tools' => $this->tools,
            'capabilities' => $this->capabilities,
            'available' => $this->available,
            'error' => $this->error,
            'error_kind' => $this->errorKind,
            'unresolvable' => $this->unresolvable,
        ];
    }
}
