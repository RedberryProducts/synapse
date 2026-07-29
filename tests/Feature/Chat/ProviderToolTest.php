<?php

use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Redberry\Synapse\Chat\ProviderToolRecorder;
use Redberry\Synapse\Models\SynapseConversation;
use Redberry\Synapse\Models\SynapseToolInvocation;

/*
| Provider-native tools run inside the provider and never fire a Laravel event —
| the stream is the only place they exist. `Agent::fake()` yields text and tool
| calls but never a ProviderToolEvent, so the recorder is driven directly with
| the shapes the real gateways emit.
|
| The SDK passes the provider's vocabulary through unnormalized, and it differs
| per gateway: Anthropic says `started` / `result_received` / `completed`, while
| OpenAI and xAI derive the status from their event names — an open set.
*/

function providerEvent(string $itemId, string $type, string $status, array $data = []): ProviderToolEvent
{
    return new ProviderToolEvent('evt_'.uniqid(), $itemId, $type, $data, $status, time());
}

function recordProviderTool(ProviderToolEvent ...$events): SynapseToolInvocation
{
    $conversation = SynapseConversation::query()->create([
        'agent_class' => 'App\\Agents\\SearchAgent',
        'title' => 'Provider tool run',
    ]);

    $recorder = new ProviderToolRecorder($conversation->id);

    foreach ($events as $event) {
        $recorder->record($event, 'inv_1');
    }

    return SynapseToolInvocation::query()->sole();
}

it('opens a pending card when a provider tool starts', function () {
    $invocation = recordProviderTool(
        providerEvent('srvtoolu_1', 'server_tool_use', 'started', ['name' => 'web_search']),
    );

    expect($invocation->type)->toBe('provider_tool')
        ->and($invocation->status)->toBe('pending')
        // The provider's own word for it survives normalization.
        ->and($invocation->provider_status)->toBe('started')
        ->and($invocation->finished_at)->toBeNull();
});

it('resolves the same card when the result arrives', function () {
    $invocation = recordProviderTool(
        providerEvent('srvtoolu_1', 'server_tool_use', 'started', ['name' => 'web_search']),
        providerEvent('srvtoolu_1', 'web_search_tool_result', 'completed', ['content' => ['a result']]),
    );

    expect($invocation->status)->toBe('success')
        ->and($invocation->provider_status)->toBe('completed')
        ->and($invocation->result)->toBe(['content' => ['a result']])
        ->and($invocation->finished_at)->not->toBeNull();
});

it('takes the tool name from the payload, not the block type', function () {
    // Anthropic reports the generic `server_tool_use`; the real name is in the
    // payload. Reading `type` would label every Anthropic card identically.
    $invocation = recordProviderTool(
        providerEvent('srvtoolu_1', 'server_tool_use', 'started', ['name' => 'web_search']),
    );

    expect($invocation->name)->toBe('web_search');
});

it('falls back to the block type when the payload has no name', function () {
    // OpenAI and xAI item types are already specific.
    $invocation = recordProviderTool(
        providerEvent('item_1', 'web_search_call', 'in_progress'),
    );

    expect($invocation->name)->toBe('web_search_call');
});

it('treats an unrecognized status as still running', function () {
    // A status Synapse has never seen must not be reported as a result that
    // never arrived — pending is recoverable, a wrong terminal state is not.
    $invocation = recordProviderTool(
        providerEvent('item_1', 'code_interpreter_call', 'interpreting'),
    );

    expect($invocation->status)->toBe('pending')
        ->and($invocation->provider_status)->toBe('interpreting');
});

it('records a failure with the provider reason', function () {
    $invocation = recordProviderTool(
        providerEvent('item_1', 'web_search_call', 'in_progress'),
        providerEvent('item_1', 'web_search_call', 'failed', ['error' => 'Upstream search unavailable']),
    );

    expect($invocation->status)->toBe('error')
        ->and($invocation->error)->toBe('Upstream search unavailable');
});

it('explains a failure the provider gave no reason for', function () {
    $invocation = recordProviderTool(
        providerEvent('item_1', 'web_search_call', 'failed'),
    );

    expect($invocation->error)->toContain('failed');
});

it('writes a second card when the provider ids do not line up', function () {
    // Anthropic keys the opening block on content_block.id and the result on
    // tool_use_id, which only match when the provider sends one. Two truthful
    // cards beat one card wearing another call's result.
    $conversation = SynapseConversation::query()->create([
        'agent_class' => 'App\\Agents\\SearchAgent',
        'title' => 'Mismatched ids',
    ]);

    $recorder = new ProviderToolRecorder($conversation->id);

    $recorder->record(providerEvent('srvtoolu_1', 'server_tool_use', 'started', ['name' => 'web_search']), 'inv_1');
    $recorder->record(providerEvent('other_id', 'web_search_tool_result', 'completed'), 'inv_1');

    expect(SynapseToolInvocation::query()->count())->toBe(2)
        ->and(SynapseToolInvocation::query()->pluck('status')->all())->toBe(['pending', 'success']);
});
