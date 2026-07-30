<?php

use Laravel\Ai\Responses\Data\ToolCall;
use Redberry\Synapse\Discovery\AgentDiscovery;
use Redberry\Synapse\Synapse;
use Workbench\App\Agents\SupportAgent;

/*
| A conversation outlives the agent that produced it: delete or rename the class
| and the rows remain. They are Synapse's own records, so History keeps listing
| them and the thread stays readable — it just cannot be continued.
|
| The agent is "removed" here by pointing discovery at a directory that does not
| contain it, which is what a deleted class looks like to Synapse.
*/

beforeEach(function () {
    Synapse::auth(fn (): bool => true);

    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
});

function orphan(): string
{
    fakeAgent(SupportAgent::class, [
        new ToolCall(id: 'call_1', name: 'SearchProductsTool', arguments: ['query' => 'hoodie']),
        'Found it.',
    ]);

    $id = chatConversationId(sendMessage('workbench.app.agents.support-agent', 'Find me a hoodie'));

    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Tools']]);

    // Discovery is a per-request singleton, so it has already scanned. Dropping
    // the instance is what the *next* request would do — which is when a
    // deleted class actually disappears.
    app()->forgetInstance(AgentDiscovery::class);

    return $id;
}

it('still lists a conversation whose agent is gone', function () {
    $id = orphan();

    $row = collect(test()->getJson('/synapse/api/conversations')->json('data'))
        ->firstWhere('id', $id);

    expect($row)->not->toBeNull()
        // The display name comes from the stored class, so the row renders
        // whether or not that class still exists.
        ->and($row['agent_name'])->toBe('SupportAgent')
        ->and($row['agent_available'])->toBeFalse();
});

it('replays the full thread for an agent that is gone', function () {
    $id = orphan();

    $conversation = test()->getJson("/synapse/api/conversations/{$id}")->assertOk()->json();

    // The replay reads rows, so it never needed the agent to exist.
    expect($conversation['agent_available'])->toBeFalse()
        ->and(array_column($conversation['messages'], 'role'))->toBe(['user', 'assistant'])
        ->and($conversation['tool_invocations'])->toHaveCount(1);
});

it('cannot be filtered by an agent that is no longer discovered', function () {
    orphan();

    // Correct: you cannot filter by something the app no longer knows about.
    // The row is still there unfiltered, which is what matters.
    expect(test()->getJson('/synapse/api/conversations?agents[]=workbench.app.agents.support-agent')->json('data'))
        ->toBe([]);

    expect(test()->getJson('/synapse/api/conversations')->json('data'))->toHaveCount(1);
});

it('can still be renamed and deleted', function () {
    $id = orphan();

    test()->patchJson("/synapse/api/conversations/{$id}", ['title' => 'Archived'])->assertOk();
    test()->deleteJson("/synapse/api/conversations/{$id}")->assertNoContent();

    expect(test()->getJson('/synapse/api/conversations')->json('data'))->toBe([]);
});
