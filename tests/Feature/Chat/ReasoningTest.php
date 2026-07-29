<?php

use Redberry\Synapse\Chat\ReasoningBuffer;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Synapse;
use Workbench\App\Agents\ExtractorAgent;
use Workbench\App\Agents\SupportAgent;

/*
| Reasoning lives only in the stream: `StreamedAgentResponse->text` is
| `TextDelta::combine()`, which excludes it, and nothing else retains the
| deltas. Synapse gathers them as they pass and stores them on the assistant
| row, so a replayed conversation shows the thinking that was watched rather
| than quietly dropping it.
|
| `Agent::fake()` never produces ReasoningDelta events, so the buffer is
| exercised directly and the persistence through the endpoint.
*/

beforeEach(function () {
    Synapse::auth(fn (): bool => true);

    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
});

it('joins the deltas in order', function () {
    $buffer = new ReasoningBuffer;

    $buffer->append('First I check ');
    $buffer->append('the catalog.');

    expect($buffer->text())->toBe('First I check the catalog.');
});

it('reports nothing when the model did no thinking', function () {
    // A model that never reasons must not leave an empty pane behind.
    expect((new ReasoningBuffer)->text())->toBeNull();
});

it('leaves the meta clean for a run without reasoning', function () {
    fakeAgent(SupportAgent::class, ['Plain answer.']);

    sendMessage('workbench.app.agents.support-agent', 'Anything');

    expect(SynapseMessage::query()->where('role', 'assistant')->sole()->meta)
        ->not->toHaveKey('reasoning');
});

it('persists the structured payload, not just its text', function () {
    fakeAgent(ExtractorAgent::class, [
        ['name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
    ]);

    $id = chatConversationId(
        sendMessage('workbench.app.agents.extractor-agent', 'Ada Lovelace, ada@example.com')
    );

    // `text` holds the JSON string, so content round-trips — but the parsed
    // payload would be lost, and replay would fall back to raw text.
    expect(SynapseMessage::query()->where('role', 'assistant')->sole()->meta['structured'])
        ->toBe(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

    expect(test()->getJson("/synapse/api/conversations/{$id}")->json('messages.1.meta.structured'))
        ->toBe(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
});
