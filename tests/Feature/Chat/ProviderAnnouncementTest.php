<?php

use Laravel\Ai\Responses\Data\ToolCall;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Synapse;
use Workbench\App\Agents\SupportAgent;

/*
| A provider-native tool card carries a `provider /` prefix. On replay that
| comes from the assistant message's stored meta — the provider the SDK
| reported. The live stream had no equivalent, so `useConversation` hardcoded
| `null` and the same call rendered differently during the run than after a
| refresh, which is exactly the attribution the ⚡ card exists to give.
|
| Reading it off the agent's configuration instead would be wrong in the one
| case that matters: after a failover the run is served by a different provider
| than the agent names.
*/

beforeEach(function () {
    Synapse::auth(fn (): bool => true);

    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
});

it('announces the provider the run resolved to', function () {
    fakeAgent(SupportAgent::class, ['Answered.']);

    $response = sendMessage('workbench.app.agents.support-agent', 'Hello');

    expect(chatPart($response, 'data-synapse-provider')['data']['provider'])->toBe('openai');
});

it('announces the provider before any tool part', function () {
    fakeAgent(SupportAgent::class, [
        new ToolCall(id: 'call_1', name: 'SearchProductsTool', arguments: ['query' => 'hoodie']),
        'Found three matches.',
    ]);

    $types = chatPartTypes(sendMessage('workbench.app.agents.support-agent', 'Find me a hoodie'));

    // Guard the guard: a fixture with no tool parts would make the loop below
    // assert nothing at all.
    expect(array_filter($types, fn (string $type): bool => str_starts_with($type, 'tool-')))
        ->not->toBeEmpty();

    // The client folds the provider into a tool card as the card is created, so
    // an announcement arriving afterwards would be too late to matter.
    $announced = array_search('data-synapse-provider', $types, true);

    expect($announced)->not->toBeFalse();

    foreach ($types as $index => $type) {
        if (str_starts_with($type, 'tool-') || $type === 'data-provider-tool') {
            expect($index)->toBeGreaterThan($announced);
        }
    }
});

it('names the provider that ran, not the one the agent is configured with', function () {
    fakeAgent(SupportAgent::class, ['Answered.']);

    $response = sendMessage('workbench.app.agents.support-agent', 'Hello');

    // The stored meta is what replay reads. Live and replay disagreeing about
    // the same call is the bug this closes, so they are asserted together.
    $stored = SynapseMessage::query()
        ->where('role', 'assistant')
        ->sole()
        ->meta['provider'] ?? null;

    expect(chatPart($response, 'data-synapse-provider')['data']['provider'])->toBe($stored);
});
