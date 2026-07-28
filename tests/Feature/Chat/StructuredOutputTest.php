<?php

use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Synapse;
use Workbench\App\Agents\ExtractorAgent;

/*
| `StreamsText::stream()` opens with:
|
|     if ($agent instanceof HasStructuredOutput) {
|         throw new InvalidArgumentException('Streaming structured output is not currently supported.');
|     }
|
| so these agents can never take the streaming path. Synapse detects the
| contract and calls prompt() instead, pushing the finished answer through the
| same protocol the client already understands. Epic 5 renders the JSON card.
*/

beforeEach(function () {
    Synapse::auth(fn (): bool => true);

    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
});

it('answers instead of throwing the streaming limitation', function () {
    fakeAgent(ExtractorAgent::class, [
        ['name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
    ]);

    $response = sendMessage('workbench.app.agents.extractor-agent', 'Ada Lovelace, ada@example.com');

    expect(chatPartTypes($response))->not->toContain('error')
        ->and(chatPartTypes($response))->toContain('text-delta');
});

it('carries the structured payload to the browser', function () {
    fakeAgent(ExtractorAgent::class, [
        ['name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
    ]);

    $part = chatPart(
        sendMessage('workbench.app.agents.extractor-agent', 'Ada Lovelace, ada@example.com'),
        'data-structured-output',
    );

    expect($part['data'])->toBe(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
});

it('persists the turn like any other', function () {
    fakeAgent(ExtractorAgent::class, [
        ['name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
    ]);

    sendMessage('workbench.app.agents.extractor-agent', 'Ada Lovelace, ada@example.com');

    expect(SynapseMessage::query()->where('role', 'assistant')->sole()->content)
        ->toContain('Ada Lovelace');
});
