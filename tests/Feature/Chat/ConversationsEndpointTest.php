<?php

use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Redberry\Synapse\Models\SynapseConversation;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Models\SynapseToolInvocation;
use Redberry\Synapse\Synapse;
use Workbench\App\Agents\SupportAgent;

beforeEach(function () {
    Synapse::auth(fn (): bool => true);

    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
});

it('replays a conversation in order', function () {
    fakeAgent(SupportAgent::class, ['First answer.', 'Second answer.']);

    $id = chatConversationId(sendMessage('workbench.app.agents.support-agent', 'First question'));
    sendMessage('workbench.app.agents.support-agent', 'Second question', $id);

    $response = test()->getJson("/synapse/api/conversations/{$id}");

    $response->assertOk();

    expect(array_column($response->json('messages'), 'role'))
        ->toBe(['user', 'assistant', 'user', 'assistant'])
        ->and(array_column($response->json('messages'), 'content'))
        ->toBe(['First question', 'First answer.', 'Second question', 'Second answer.']);
});

it('reports the agent it belongs to', function () {
    fakeAgent(SupportAgent::class, ['Answer.']);

    $id = chatConversationId(sendMessage('workbench.app.agents.support-agent', 'Question'));

    test()->getJson("/synapse/api/conversations/{$id}")
        ->assertJsonPath('agent_class', SupportAgent::class)
        // The slug is what the playground route needs to reopen it.
        ->assertJsonPath('agent_slug', 'workbench.app.agents.support-agent');
});

it('totals the tokens across the thread', function () {
    fakeAgent(SupportAgent::class, [
        new TextResponse('One.', new Usage(promptTokens: 100, completionTokens: 20), new Meta('openai', 'gpt-5.6-luna')),
        new TextResponse('Two.', new Usage(promptTokens: 42, completionTokens: 9), new Meta('openai', 'gpt-5.6-luna')),
    ]);

    $id = chatConversationId(sendMessage('workbench.app.agents.support-agent', 'First'));
    sendMessage('workbench.app.agents.support-agent', 'Second', $id);

    test()->getJson("/synapse/api/conversations/{$id}")
        ->assertJsonPath('totals.prompt_tokens', 142)
        ->assertJsonPath('totals.completion_tokens', 29)
        ->assertJsonPath('totals.total_tokens', 171);
});

it('includes the error rows so a refresh still shows what went wrong', function () {
    fakeAgent(SupportAgent::class, [
        fn () => throw new RuntimeException('Provider exploded'),
    ]);

    $id = chatConversationId(sendMessage('workbench.app.agents.support-agent', 'Anything'));

    $messages = test()->getJson("/synapse/api/conversations/{$id}")->json('messages');

    expect(array_column($messages, 'role'))->toBe(['user', 'error'])
        ->and($messages[1]['metadata']['exception_class'])->toBe(RuntimeException::class);
});

it('carries per-message usage and duration', function () {
    fakeAgent(SupportAgent::class, [
        new TextResponse('Answer.', new Usage(promptTokens: 11, completionTokens: 3), new Meta('openai', 'gpt-5.6-luna')),
    ]);

    $id = chatConversationId(sendMessage('workbench.app.agents.support-agent', 'Question'));

    $assistant = test()->getJson("/synapse/api/conversations/{$id}")->json('messages.1');

    expect($assistant['usage']['prompt_tokens'])->toBe(11)
        ->and($assistant['usage']['completion_tokens'])->toBe(3)
        ->and($assistant['duration_ms'])->toBeInt()
        ->and($assistant['meta']['model'])->toBe('gpt-5.6-luna');
});

it('404s for an unknown conversation', function () {
    test()->getJson('/synapse/api/conversations/does-not-exist')->assertNotFound();
});

it('deletes a conversation and everything hanging off it', function () {
    fakeAgent(SupportAgent::class, ['Answer.']);

    $id = chatConversationId(sendMessage('workbench.app.agents.support-agent', 'Question'));

    SynapseToolInvocation::query()->create([
        'conversation_id' => $id,
        'invocation_id' => 'inv_1',
        'tool_invocation_id' => 'tool_1',
        'type' => 'tool',
        'name' => 'SearchProductsTool',
        'arguments' => [],
        'status' => 'success',
    ]);

    test()->deleteJson("/synapse/api/conversations/{$id}")->assertNoContent();

    expect(SynapseConversation::query()->count())->toBe(0)
        ->and(SynapseMessage::query()->count())->toBe(0)
        ->and(SynapseToolInvocation::query()->count())->toBe(0);
});

it('keeps both endpoints behind the gate', function () {
    Synapse::auth(fn (): bool => false);

    test()->getJson('/synapse/api/conversations/anything')->assertForbidden();
    test()->deleteJson('/synapse/api/conversations/anything')->assertForbidden();
});
