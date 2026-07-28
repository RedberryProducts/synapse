<?php

namespace Redberry\Synapse\Chat;

use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\ToolChoice;
use ReflectionMethod;
use Stringable;

/**
 * Wraps a Conversational agent so the SDK receives Synapse's own thread history
 * instead of the agent's ConversationStore.
 *
 * Only the `messages()` behaviour may differ from the wrapped agent — everything
 * else has to run exactly as it would unwrapped. That is harder than it looks:
 * the SDK resolves an agent's provider, model, timeout, model tier and every
 * generation option by reflecting on the *instance*, so each of those call sites
 * would otherwise see this class instead of the real agent and silently fall
 * back to framework defaults. Each is forwarded below; see
 * plans/epic-03-chat-mvp/PLAN.md for the full call-site table.
 *
 * Two interfaces are deliberately NOT implemented:
 *
 * - `HasStructuredOutput` — `StreamsText::stream()` throws for any agent that
 *   implements it, so carrying it here would break every conversational stream.
 *   Structured agents are invoked undecorated via `prompt()` instead.
 * - `RemembersConversations` — `GeneratesText::gatherMiddlewareFor()` looks for
 *   the trait with `class_uses_recursive()`. Leaving it off is what keeps the
 *   SDK's `RememberConversation` middleware and the developer's own store out
 *   of the picture, which is the whole point of supplying history ourselves.
 */
class SynapseConversationalAgent implements Agent, Conversational, HasMiddleware, HasProviderOptions, HasTools
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
    }

    /**
     * The wrapped agent's own generation options, resolved once.
     */
    protected TextGenerationOptions $options;

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
     * no method fallback, so it is the one setting a forwarding method cannot
     * carry — it needs an annotated subclass instead.
     *
     * @param  Message[]  $history
     */
    public static function for(Agent $agent, array $history = []): self
    {
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

    public function middleware(): array
    {
        return $this->agent instanceof HasMiddleware ? $this->agent->middleware() : [];
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
    | before falling back to the attribute, so forwarding the resolved values as
    | methods is enough — this class never needs the attributes themselves.
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
