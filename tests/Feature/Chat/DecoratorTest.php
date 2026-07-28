<?php

use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Redberry\Synapse\Chat\StrictSynapseConversationalAgent;
use Redberry\Synapse\Chat\SynapseConversationalAgent;
use Workbench\App\Agents\ConfiguredAgent;
use Workbench\App\Agents\StrictAgent;
use Workbench\App\Agents\SupportAgent;
use Workbench\App\Agents\WeatherAgent;

/*
| The decorator may change exactly one thing about an agent: the messages it
| receives. Everything else must run identically to the unwrapped agent.
|
| That is not automatic. The SDK resolves provider, model, timeout, model tier
| and every generation option by reflecting on the agent *instance*, so each of
| those call sites sees this wrapper's class rather than the real agent's. If a
| forward is missed, the playground silently runs a different model or different
| settings than the Info panel reports — the worst possible failure for a tool
| whose entire value is telling you the truth about your agent.
|
| These tests are the net under that.
*/

/**
 * Every generation option the SDK reads, as it resolves them for an agent.
 *
 * @return array<string, mixed>
 */
function generationOptionsFor(object $agent): array
{
    $options = TextGenerationOptions::forAgent($agent);

    return [
        'maxSteps' => $options->maxSteps,
        'maxTokens' => $options->maxTokens,
        'temperature' => $options->temperature,
        'topP' => $options->topP,
        'toolChoice' => $options->toolChoice?->mode,
        'toolChoiceName' => $options->toolChoice?->toolName,
    ];
}

/**
 * Reach one of the agent's protected resolution methods the way the SDK does.
 */
function resolveOnAgent(object $agent, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod($agent, $method))->invoke($agent, ...$arguments);
}

it('resolves every generation option exactly as the unwrapped agent does', function () {
    $agent = new ConfiguredAgent;

    expect(generationOptionsFor(SynapseConversationalAgent::for($agent)))
        ->toBe(generationOptionsFor($agent));
});

it('keeps the provider and model the agent declares', function () {
    $agent = new SupportAgent;

    expect(resolveOnAgent(SynapseConversationalAgent::for($agent), 'getProvidersAndModels', null, null))
        ->toBe(resolveOnAgent($agent, 'getProvidersAndModels', null, null))
        ->toBe(['openai' => 'gpt-5.6-luna']);
});

it('keeps the agent timeout rather than falling back to the SDK default', function () {
    $agent = new ConfiguredAgent;

    $wrapped = resolveOnAgent(SynapseConversationalAgent::for($agent), 'getTimeout', null);

    expect($wrapped)->toBe(resolveOnAgent($agent, 'getTimeout', null))
        // Anything but the SDK's own 60s fallback proves the forward works.
        ->not->toBe(60);
});

it('preserves the strict attribute across the wrap', function () {
    $agent = new StrictAgent;

    expect(Strict::isAppliedTo($agent))->toBeTrue()
        ->and(Strict::isAppliedTo(SynapseConversationalAgent::for($agent)))->toBeTrue();
});

it('uses the strict variant only for strict agents', function () {
    expect(SynapseConversationalAgent::for(new StrictAgent))
        ->toBeInstanceOf(StrictSynapseConversationalAgent::class);

    expect(SynapseConversationalAgent::for(new SupportAgent))
        ->not->toBeInstanceOf(StrictSynapseConversationalAgent::class);
});

it('forwards instructions, tools, middleware and provider options', function () {
    $agent = new ConfiguredAgent;
    $wrapper = SynapseConversationalAgent::for($agent);

    expect((string) $wrapper->instructions())->toBe((string) $agent->instructions())
        ->and(count([...$wrapper->tools()]))->toBe(count([...$agent->tools()]))
        ->and($wrapper->middleware())->toBe($agent->middleware())
        ->and($wrapper->providerOptions('openai'))->toBe($agent->providerOptions('openai'));
});

it('returns empty capabilities for an agent that has none', function () {
    // WeatherAgent has tools but no middleware and no provider options; the
    // empty returns must be indistinguishable from not implementing the
    // interface at all, which is what `filled()` in the gateways relies on.
    $wrapper = SynapseConversationalAgent::for(new WeatherAgent);

    expect($wrapper->middleware())->toBe([])
        ->and($wrapper->providerOptions('openai'))->toBe([]);
});

it('supplies Synapse history instead of the agent own messages', function () {
    $history = [new Message('user', 'Earlier question')];

    $wrapper = SynapseConversationalAgent::for(new SupportAgent, $history);

    expect([...$wrapper->messages()])->toBe($history)
        ->and([...(new SupportAgent)->messages()])->toBe([]);
});

it('carries the contracts the SDK branches on', function () {
    $wrapper = SynapseConversationalAgent::for(new SupportAgent);

    expect($wrapper)->toBeInstanceOf(Conversational::class)
        ->toBeInstanceOf(HasTools::class)
        ->toBeInstanceOf(HasMiddleware::class)
        ->toBeInstanceOf(HasProviderOptions::class);
});

it('never claims structured output', function () {
    // StreamsText::stream() throws for any HasStructuredOutput agent, so a
    // decorator carrying the contract would break every conversational stream.
    expect(SynapseConversationalAgent::for(new SupportAgent))
        ->not->toBeInstanceOf(HasStructuredOutput::class);
});

it('does not pull in the SDK conversation middleware', function () {
    // gatherMiddlewareFor() looks for the RemembersConversations *trait*.
    // Leaving it off is what keeps the developer's own ConversationStore out of
    // the picture while Synapse supplies history directly.
    expect(class_uses_recursive(SynapseConversationalAgent::for(new SupportAgent)))
        ->not->toContain(RemembersConversations::class);
});

it('exposes the agent it wraps', function () {
    $agent = new SupportAgent;

    expect(SynapseConversationalAgent::for($agent)->wrapped())->toBe($agent);
});
