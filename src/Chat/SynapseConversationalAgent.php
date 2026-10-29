<?php

namespace Redberry\Synapse\Chat;

use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasSkills;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\ToolChoice;
use ReflectionMethod;
use Stringable;

/**
 * Wraps playground agents so Synapse controls history without using the SDK
 * ConversationStore. Stateless agents receive no prior turns.
 *
 * Only the `messages()` behaviour may differ from the wrapped agent — everything
 * else has to run exactly as it would unwrapped. That is harder than it looks:
 * the SDK resolves an agent's provider, model, timeout, model tier and every
 * generation option by reflecting on the *instance*, so each of those call sites
 * would otherwise see this class instead of the real agent and silently fall
 * back to framework defaults. Each is forwarded below.
 *
 * Two interfaces are deliberately NOT implemented:
 *
 * - `HasStructuredOutput` — `StreamsText::stream()` throws for any agent that
 *   implements it, so carrying it here would break every conversational stream.
 *   Structured agents use a schema-forwarding subclass and `prompt()` instead.
 * - `RemembersConversations` — the SDK checks both the contract and the
 *   trait. Leaving both off keeps the SDK's `RememberConversation` middleware
 *   and the developer's own store out
 *   of the picture, which is the whole point of supplying history ourselves.
 */
class SynapseConversationalAgent implements Agent, Conversational, HasMiddleware, HasProviderOptions, HasSkills, HasTools
{
    /*
    | The aliases keep the trait's own implementations reachable after the
    | overrides below, as a fallback for the rare agent that satisfies the Agent
    | contract without using Promptable.
    */
    use Promptable {
        getProvidersAndModels as protected promptableProvidersAndModels;
        getTimeout as protected promptableTimeout;
        getDefaultModelFor as protected promptableDefaultModelFor;
        resolveAgentTools as protected promptableAgentTools;
    }

    /**
     * The wrapped agent's own generation options, resolved once.
     */
    protected TextGenerationOptions $options;

    protected ?ApprovalContinuation $approvalContinuation = null;

    /**
     * @param  Message[]  $history
     */
    public function __construct(
        protected Agent $agent,
        protected array $history = [],
    ) {
        $this->options = TextGenerationOptions::forAgent($agent);
    }

    /**
     * Wrap the given agent, preserving the `#[Strict]` attribute when present.
     *
     * `Strict::isAppliedTo()` reads the attribute straight off the instance with
     * no method fallback, so this setting needs an annotated subclass instead
     * of a forwarding method.
     *
     * @param  Message[]  $history
     */
    public static function for(Agent $agent, array $history = []): self
    {
        if ($agent instanceof HasStructuredOutput) {
            return Strict::isAppliedTo($agent)
                ? new StrictSynapseStructuredAgent($agent, $history)
                : new SynapseStructuredAgent($agent, $history);
        }

        return Strict::isAppliedTo($agent)
            ? new StrictSynapseConversationalAgent($agent, $history)
            : new self($agent, $history);
    }

    /**
     * The agent being wrapped.
     */
    public function wrapped(): Agent
    {
        return $this->agent;
    }

    public function instructions(): Stringable|string
    {
        return $this->agent->instructions();
    }

    /**
     * @return Message[]
     */
    public function messages(): iterable
    {
        return $this->history;
    }

    public function tools(): iterable
    {
        return $this->agent instanceof HasTools ? $this->agent->tools() : [];
    }

    public function continuingApproval(ApprovalContinuation $continuation): static
    {
        $this->approvalContinuation = $continuation;

        return $this;
    }

    public function middleware(): array
    {
        $middleware = $this->agent instanceof HasMiddleware ? $this->agent->middleware() : [];

        if ($this->options->cacheInstructions !== null || $this->options->cacheToolDefinitions !== null) {
            array_unshift($middleware, new ForwardCacheOptions(
                $this->options->cacheInstructions,
                $this->options->cacheToolDefinitions,
            ));
        }

        if ($this->approvalContinuation !== null) {
            array_unshift($middleware, new RecordApprovalResults($this->approvalContinuation));
        }

        return $middleware;
    }

    public function skills(): iterable
    {
        return $this->agent instanceof HasSkills ? $this->agent->skills() : [];
    }

    /**
     * @return array<int, Agent|Tool|ProviderTool>|null
     */
    protected function resolveAgentTools(): ?array
    {
        return $this->promptableAgentTools() ?? $this->callOnAgent('resolveAgentTools', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return $this->agent instanceof HasProviderOptions
            ? $this->agent->providerOptions($provider)
            : [];
    }

    /*
    | Generation options. TextGenerationOptions::forAgent() checks for a method
    | before falling back to the attribute for these settings. Attribute-only
    | cache settings are forwarded separately by ForwardCacheOptions.
    */

    public function maxSteps(): ?int
    {
        return $this->options->maxSteps;
    }

    public function maxTokens(): ?int
    {
        return $this->options->maxTokens;
    }

    public function temperature(): ?float
    {
        return $this->options->temperature;
    }

    public function topP(): ?float
    {
        return $this->options->topP;
    }

    /**
     * `ToolChoice::from()` accepts an instance, so the resolved object passes
     * straight through.
     */
    public function toolChoice(): ?ToolChoice
    {
        return $this->options->toolChoice;
    }

    /*
    | Provider / model / timeout / tier. These live on the Promptable trait the
    | wrapped agent also uses, so rather than reimplementing the SDK's precedence
    | rules we run its own methods against the real agent.
    */

    /**
     * @return array<string, string|null>
     */
    protected function getProvidersAndModels(Lab|array|string|null $provider, ?string $model): array
    {
        /** @var array<string, string|null> */
        return $this->callOnAgent('getProvidersAndModels', [$provider, $model])
            ?? $this->promptableProvidersAndModels($provider, $model);
    }

    protected function getTimeout(?int $timeout): int
    {
        return (int) ($this->callOnAgent('getTimeout', [$timeout])
            ?? $this->promptableTimeout($timeout));
    }

    protected function getDefaultModelFor(TextProvider $provider): string
    {
        return (string) ($this->callOnAgent('getDefaultModelFor', [$provider])
            ?? $this->promptableDefaultModelFor($provider));
    }

    /**
     * Invoke one of the wrapped agent's protected resolution methods against the
     * wrapped agent itself, or null when it does not define one.
     *
     * @param  array<int, mixed>  $arguments
     */
    protected function callOnAgent(string $method, array $arguments): mixed
    {
        if (! method_exists($this->agent, $method)) {
            return null;
        }

        return (new ReflectionMethod($this->agent, $method))
            ->invoke($this->agent, ...$arguments);
    }
}
