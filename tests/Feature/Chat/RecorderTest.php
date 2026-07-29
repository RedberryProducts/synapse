<?php

use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Redberry\Synapse\Chat\MessageHistory;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Models\SynapseToolInvocation;
use Redberry\Synapse\Synapse;
use Workbench\App\Agents\FlakyToolAgent;
use Workbench\App\Agents\SupportAgent;

beforeEach(function () {
    Synapse::auth(fn (): bool => true);

    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
});

it('records a tool call from pending through to success', function () {
    fakeAgent(SupportAgent::class, [
        new ToolCall(id: 'call_1', name: 'SearchProductsTool', arguments: ['query' => 'hoodie']),
        'Found three matches.',
    ]);

    sendMessage('workbench.app.agents.support-agent', 'Find me a hoodie');

    $invocation = SynapseToolInvocation::query()->sole();

    expect($invocation->type)->toBe('tool')
        ->and($invocation->name)->toBe('SearchProductsTool')
        ->and($invocation->arguments)->toBe(['query' => 'hoodie'])
        ->and($invocation->status)->toBe('success')
        ->and($invocation->result)->toContain('Sony WH-1000')
        ->and($invocation->started_at)->not->toBeNull()
        ->and($invocation->finished_at)->not->toBeNull()
        ->and($invocation->duration_ms)->toBeGreaterThanOrEqual(0);
});

it('attaches the row to the assistant turn it produced', function () {
    fakeAgent(SupportAgent::class, [
        new ToolCall(id: 'call_1', name: 'SearchProductsTool', arguments: ['query' => 'hoodie']),
        'Found three matches.',
    ]);

    sendMessage('workbench.app.agents.support-agent', 'Find me a hoodie');

    $assistant = SynapseMessage::query()->where('role', 'assistant')->sole();

    expect(SynapseToolInvocation::query()->sole()->message_id)->toBe($assistant->id);
});

it('records one row per call when a turn uses several tools', function () {
    fakeAgent(SupportAgent::class, [
        new ToolCall(id: 'call_1', name: 'SearchProductsTool', arguments: ['query' => 'hoodie']),
        new ToolCall(id: 'call_2', name: 'SearchProductsTool', arguments: ['query' => 'scarf']),
        'Found both.',
    ]);

    sendMessage('workbench.app.agents.support-agent', 'Find a hoodie and a scarf');

    $invocations = SynapseToolInvocation::query()->orderBy('started_at')->orderBy('id')->get();

    expect($invocations)->toHaveCount(2)
        ->and($invocations->pluck('arguments.query')->all())->toBe(['hoodie', 'scarf'])
        ->and($invocations->pluck('status')->unique()->all())->toBe(['success']);
});

it('leaves a throwing tool as a failed row rather than a pending one', function () {
    // ToolInvoked never fires for a tool that throws — executeTool() has no
    // catch — so the invocation-level catch-all is what closes this row out.
    fakeAgent(FlakyToolAgent::class, [
        new ToolCall(id: 'call_1', name: 'BrokenLedgerTool', arguments: ['entry' => '42']),
    ]);

    sendMessage('workbench.app.agents.flaky-tool-agent', 'Look up entry 42');

    $invocation = SynapseToolInvocation::query()->sole();

    expect($invocation->status)->toBe('error')
        ->and($invocation->error)->toBe('Ledger service unavailable')
        ->and($invocation->finished_at)->not->toBeNull()
        ->and($invocation->result)->toBeNull();
});

it('pairs a failed tool card with an error message in the thread', function () {
    fakeAgent(FlakyToolAgent::class, [
        new ToolCall(id: 'call_1', name: 'BrokenLedgerTool', arguments: ['entry' => '42']),
    ]);

    sendMessage('workbench.app.agents.flaky-tool-agent', 'Look up entry 42');

    // The card says which tool; the error card says what went wrong. Both.
    expect(SynapseToolInvocation::query()->sole()->status)->toBe('error')
        ->and(SynapseMessage::query()->where('role', 'error')->count())->toBe(1);
});

it('emits the tool parts on the stream', function () {
    fakeAgent(SupportAgent::class, [
        new ToolCall(id: 'call_1', name: 'SearchProductsTool', arguments: ['query' => 'hoodie']),
        'Found three matches.',
    ]);

    $response = sendMessage('workbench.app.agents.support-agent', 'Find me a hoodie');

    $types = chatPartTypes($response);

    expect($types)->toContain('tool-input-available')
        ->toContain('tool-output-available');

    // The call has to reach the browser before its result, or the UI would have
    // nothing to resolve.
    expect(array_search('tool-input-available', $types, true))
        ->toBeLessThan(array_search('tool-output-available', $types, true));
});

it('orders a failed tool call before the error that ended the run', function () {
    fakeAgent(FlakyToolAgent::class, [
        new ToolCall(id: 'call_1', name: 'BrokenLedgerTool', arguments: ['entry' => '42']),
    ]);

    $id = chatConversationId(sendMessage('workbench.app.agents.flaky-tool-agent', 'Look up entry 42'));

    $conversation = test()->getJson("/synapse/api/conversations/{$id}")->json();

    // Both tables use uuid7 keys, so merging them by id is true chronology:
    // the call was recorded when it started, the error when the run died.
    // The client sorts on exactly this, so the ids have to line up.
    expect($conversation['tool_invocations'][0]['id'])
        ->toBeGreaterThan($conversation['messages'][0]['id'])
        ->toBeLessThan($conversation['messages'][1]['id']);

    expect($conversation['messages'][1]['role'])->toBe('error');
});

it('replays a real tool turn from the message row, not the tool table', function () {
    fakeAgent(SupportAgent::class, [
        new ToolCall(id: 'call_1', name: 'SearchProductsTool', arguments: ['query' => 'hoodie']),
        'Found three matches.',
    ]);

    $id = chatConversationId(sendMessage('workbench.app.agents.support-agent', 'Find me a hoodie'));

    $history = app(MessageHistory::class)->for($id);

    // One assistant row expands into the SDK's own assistant → tool-result →
    // assistant sequence, rebuilt from its stored tool_calls/tool_results JSON.
    // The `synapse_tool_invocations` row is a Synapse observation and plays no
    // part in what the agent sees.
    expect($history)->toHaveCount(4)
        ->and($history[1])->toBeInstanceOf(AssistantMessage::class)
        ->and($history[2])->toBeInstanceOf(ToolResultMessage::class)
        ->and($history[3]->content)->toBe('Found three matches.')
        ->and(SynapseToolInvocation::query()->count())->toBe(1);
});
