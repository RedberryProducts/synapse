<?php

use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Redberry\Synapse\Models\SynapseConversation;
use Redberry\Synapse\Synapse;
use Workbench\App\Agents\FlakyToolAgent;
use Workbench\App\Agents\SupportAgent;

/*
| History is seeded through the chat pipeline rather than by inserting rows, so
| these tests read exactly the data the product writes.
*/

beforeEach(function () {
    Synapse::auth(fn (): bool => true);

    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
});

it('lists conversations newest first', function () {
    fakeAgent(SupportAgent::class, fn (): string => 'Answered.');

    sendMessage('workbench.app.agents.support-agent', 'First question');
    sendMessage('workbench.app.agents.support-agent', 'Second question');

    $titles = array_column(
        test()->getJson('/synapse/api/conversations')->json('data'),
        'title',
    );

    expect($titles)->toBe(['Second question', 'First question']);
});

it('sorts oldest first when asked', function () {
    fakeAgent(SupportAgent::class, fn (): string => 'Answered.');

    sendMessage('workbench.app.agents.support-agent', 'First question');
    sendMessage('workbench.app.agents.support-agent', 'Second question');

    $titles = array_column(
        test()->getJson('/synapse/api/conversations?sort=oldest')->json('data'),
        'title',
    );

    expect($titles)->toBe(['First question', 'Second question']);
});

it('reports the agent, tokens and tool count per row', function () {
    fakeAgent(SupportAgent::class, [
        new ToolCall(id: 'call_1', name: 'SearchProductsTool', arguments: ['query' => 'hoodie']),
        new TextResponse('Found it.', new Usage(promptTokens: 200, completionTokens: 50), new Meta('openai', 'gpt-5.6-luna')),
    ]);

    sendMessage('workbench.app.agents.support-agent', 'Find me a hoodie');

    $row = test()->getJson('/synapse/api/conversations')->json('data.0');

    expect($row['agent_name'])->toBe('SupportAgent')
        ->and($row['agent_slug'])->toBe('workbench.app.agents.support-agent')
        ->and($row['agent_available'])->toBeTrue()
        ->and($row['tool_calls'])->toBe(1)
        ->and($row['prompt_tokens'])->toBe(200)
        ->and($row['completion_tokens'])->toBe(50)
        ->and($row['total_tokens'])->toBe(250)
        ->and($row['status'])->toBe('success');
});

it('marks a conversation as error when a message failed', function () {
    fakeAgent(SupportAgent::class, [
        fn () => throw new RuntimeException('Provider exploded'),
    ]);

    sendMessage('workbench.app.agents.support-agent', 'This one fails');

    expect(test()->getJson('/synapse/api/conversations')->json('data.0.status'))->toBe('error');
});

it('still calls a recovered tool failure a success', function () {
    // The distinction the status column exists for. An agent that loses a tool
    // and answers anyway has not failed — colouring it red would teach you to
    // ignore the column, and then it stops working for real failures.
    fakeAgent(FlakyToolAgent::class, [
        new ToolCall(id: 'call_1', name: 'BrokenLedgerTool', arguments: ['entry' => '42']),
    ]);

    sendMessage('workbench.app.agents.flaky-tool-agent', 'Look up entry 42');

    // This particular run does fail (the exception ends the turn), so build the
    // recovered case directly: a failed tool row beside a clean thread.
    $conversation = SynapseConversation::query()->create([
        'agent_class' => SupportAgent::class,
        'title' => 'Recovered after a bad tool',
    ]);

    $conversation->messages()->create([
        'role' => 'user', 'content' => 'Find me a hoodie', 'attachments' => [],
        'tool_calls' => [], 'tool_results' => [], 'usage' => [], 'meta' => [], 'metadata' => [],
    ]);

    $conversation->messages()->create([
        'role' => 'assistant', 'content' => 'Found it anyway.', 'attachments' => [],
        'tool_calls' => [], 'tool_results' => [], 'usage' => [], 'meta' => [], 'metadata' => [],
    ]);

    $conversation->toolInvocations()->create([
        'invocation_id' => 'inv_1', 'tool_invocation_id' => 'tool_1', 'type' => 'tool',
        'name' => 'SearchProductsTool', 'arguments' => [], 'status' => 'error',
        'error' => 'Upstream timed out',
    ]);

    $row = collect(test()->getJson('/synapse/api/conversations')->json('data'))
        ->firstWhere('id', $conversation->id);

    expect($row['status'])->toBe('success')
        ->and($row['tool_calls'])->toBe(1);
});

it('paginates at twenty-five and reports the totals', function () {
    fakeAgent(SupportAgent::class, fn (): string => 'Answered.');

    for ($i = 1; $i <= 27; $i++) {
        sendMessage('workbench.app.agents.support-agent', "Question {$i}");
    }

    $first = test()->getJson('/synapse/api/conversations')->json();

    expect($first['data'])->toHaveCount(25)
        ->and($first['meta'])->toMatchArray([
            'current_page' => 1,
            'last_page' => 2,
            'per_page' => 25,
            'total' => 27,
        ]);

    expect(test()->getJson('/synapse/api/conversations?page=2')->json('data'))->toHaveCount(2);
});

it('ships the filter options with the list', function () {
    fakeAgent(SupportAgent::class, [
        new ToolCall(id: 'call_1', name: 'SearchProductsTool', arguments: ['query' => 'hoodie']),
        'Found it.',
    ]);

    sendMessage('workbench.app.agents.support-agent', 'Find me a hoodie');

    $filters = test()->getJson('/synapse/api/conversations')->json('filters');

    expect(array_column($filters['agents'], 'slug'))->toContain('workbench.app.agents.support-agent')
        // Tool names come from what actually ran, so a since-deleted tool can
        // still be filtered by.
        ->and($filters['tools'])->toContain('SearchProductsTool');
});

it('is empty rather than broken with no conversations', function () {
    $response = test()->getJson('/synapse/api/conversations');

    $response->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->json('meta.total'))->toBe(0);
});

it('keeps the list behind the gate', function () {
    Synapse::auth(fn (): bool => false);

    test()->getJson('/synapse/api/conversations')->assertForbidden();
});

it('no longer exposes a clear-all route', function () {
    // Removed deliberately: `synapse:clear` does this, and the route used to
    // return 204 while doing nothing. 405 rather than 404 because the SPA
    // catch-all answers GET on every path — POST is what no longer exists.
    test()->postJson('/synapse/api/conversations/clear')->assertMethodNotAllowed();
});
