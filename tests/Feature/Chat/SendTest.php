<?php

use Redberry\Synapse\Models\SynapseConversation;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Synapse;
use Workbench\App\Agents\SupportAgent;
use Workbench\App\Agents\WeatherAgent;

beforeEach(function () {
    Synapse::auth(fn (): bool => true);

    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
});

it('streams an answer as vercel protocol parts', function () {
    fakeAgent(SupportAgent::class, ['Certainly, here is the answer.']);

    $response = sendMessage('workbench.app.agents.support-agent', 'How do I reset a password?');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/event-stream');

    expect(chatPartTypes($response))
        ->toContain('data-synapse-start')
        ->toContain('start')
        ->toContain('text-start')
        ->toContain('text-delta')
        ->toContain('finish')
        ->toContain('data-synapse-end');

    // The terminator is the last thing on the wire.
    expect($response->streamedContent())->toEndWith("data: [DONE]\n\n");
});

it('announces the conversation before any model output', function () {
    fakeAgent(SupportAgent::class, ['Hi.']);

    $response = sendMessage('workbench.app.agents.support-agent', 'Hello');

    $first = chatParts($response)[0];

    expect($first['type'])->toBe('data-synapse-start')
        ->and($first['data']['conversationId'])->toBe(SynapseConversation::query()->sole()->id)
        ->and($first['data']['userMessageId'])->not->toBeEmpty();
});

it('emits exactly one start part even across steps', function () {
    fakeAgent(WeatherAgent::class, ['Sunny.']);

    $response = sendMessage('workbench.app.agents.weather-agent', 'Weather in Tbilisi?');

    expect(array_count_values(chatPartTypes($response))['start'])->toBe(1);
});

it('holds the finish part until the end of the stream', function () {
    fakeAgent(SupportAgent::class, ['Done.']);

    $types = chatPartTypes(sendMessage('workbench.app.agents.support-agent', 'Anything else?'));

    expect(array_slice($types, -2))->toBe(['finish', 'data-synapse-end']);
});

it('reuses an existing conversation when one is given', function () {
    fakeAgent(SupportAgent::class, ['One.', 'Two.']);

    $conversationId = chatConversationId(
        sendMessage('workbench.app.agents.support-agent', 'First question')
    );

    sendMessage('workbench.app.agents.support-agent', 'Second question', $conversationId);

    expect(SynapseConversation::query()->count())->toBe(1)
        ->and(SynapseMessage::query()->where('conversation_id', $conversationId)->count())->toBe(4);
});

it('titles the conversation from the first message', function () {
    fakeAgent(SupportAgent::class, ['Sure.']);

    sendMessage('workbench.app.agents.support-agent', 'How do I reset a password?');

    expect(SynapseConversation::query()->sole()->title)->toBe('How do I reset a password?');
});

it('keeps running after the browser disconnects', function () {
    // A real mid-stream disconnect can't be simulated through the test harness,
    // so this guards the one line that makes the run survive one: without it PHP
    // kills the script on the first write to a dead connection, and the turn is
    // lost with no assistant row and no error row to explain the gap.
    ignore_user_abort(false);

    fakeAgent(SupportAgent::class, ['Finished regardless.']);

    sendMessage('workbench.app.agents.support-agent', 'Start and walk away');

    expect(ignore_user_abort())->toBe(1);

    ignore_user_abort(false);
});

it('rejects a message for an unknown agent', function () {
    test()->post('/synapse/api/chat/nope.not.here/send', ['message' => 'Hello'])
        ->assertNotFound();
});

it('requires a message', function () {
    test()->postJson('/synapse/api/chat/workbench.app.agents.support-agent/send', [])
        ->assertStatus(422);
});

it('is closed when the gate denies', function () {
    Synapse::auth(fn (): bool => false);

    test()->post('/synapse/api/chat/workbench.app.agents.support-agent/send', ['message' => 'Hello'])
        ->assertForbidden();
});
