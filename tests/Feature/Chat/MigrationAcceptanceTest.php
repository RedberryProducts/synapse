<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Redberry\Synapse\Models\SynapseToolInvocation;
use Redberry\Synapse\Synapse;
use Workbench\App\Agents\SupportAgent;

beforeEach(function () {
    Synapse::auth(fn (): bool => true);
    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
});

it('preserves reasoning through the send endpoint and thread reload', function () {
    fakeAgent(SupportAgent::class, [AgentResponse::fakeWithReasoning('First I check availability.', 'In stock.')]);
    $response = sendMessage('workbench.app.agents.support-agent', 'Is it available?');
    $reasoning = collect(chatParts($response))->where('type', 'reasoning-delta')->pluck('delta')->implode('');
    expect($reasoning)->toBe('First I check availability.');

    $id = chatConversationId($response);
    $thread = test()->getJson("/synapse/api/conversations/{$id}")->assertOk();
    expect($thread->json('messages.1.meta.reasoning'))->toBe('First I check availability.');
});

it('does not record later sends in an earlier conversation', function () {
    fakeAgent(SupportAgent::class, [
        new ToolCall('first', 'SearchProductsTool', ['query' => 'hoodie']), 'Found a hoodie.',
        new ToolCall('second', 'SearchProductsTool', ['query' => 'scarf']), 'Found a scarf.',
    ]);
    $first = chatConversationId(sendMessage('workbench.app.agents.support-agent', 'Find a hoodie'));
    $second = chatConversationId(sendMessage('workbench.app.agents.support-agent', 'Find a scarf'));

    expect(SynapseToolInvocation::where('conversation_id', $first)->pluck('arguments')->all())->toBe([['query' => 'hoodie']])
        ->and(SynapseToolInvocation::where('conversation_id', $second)->pluck('arguments')->all())->toBe([['query' => 'scarf']])
        ->and(SynapseToolInvocation::count())->toBe(2);
});

it('keeps the full send and replay pipeline isolated from SDK storage', function () {
    fakeAgent(SupportAgent::class, ['First answer.', 'Second answer.']);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $first = sendMessage('workbench.app.agents.support-agent', 'Hello');
    expect(chatPartTypes($first))->toContain('text-delta');
    $id = chatConversationId($first);
    $second = sendMessage('workbench.app.agents.support-agent', 'Continue', $id);
    expect(chatPartTypes($second))->toContain('text-delta');
    test()->getJson("/synapse/api/conversations/{$id}")->assertOk();

    expect(implode("\n", $queries))->not->toContain('agent_conversations')
        ->not->toContain('agent_conversation_messages');
});
