<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Ai\Tools\AgentTool;
use Redberry\Synapse\Chat\SynapseConversationalAgent;
use Redberry\Synapse\Tests\Fixtures\CachedSkillAgent;
use Workbench\App\Agents\FlakyToolAgent;
use Workbench\App\Agents\SupportAgent;
use Workbench\App\Agents\WeatherAgent;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['ai.providers.anthropic.key' => 'fake-key']);
});

function anthropicStep(array $content, string $stop = 'end_turn'): array
{
    return [
        'id' => 'msg_boundary', 'type' => 'message', 'role' => 'assistant',
        'model' => 'claude-test', 'content' => $content, 'stop_reason' => $stop,
        'usage' => ['input_tokens' => 20, 'output_tokens' => 5],
    ];
}

it('preserves cache attributes and skills in the effective provider request', function () {
    $toolStep = anthropicStep([
        ['type' => 'tool_use', 'id' => 'call_search', 'name' => 'SearchProductsTool', 'input' => ['query' => 'hoodie']],
    ], 'tool_use');
    $answerStep = anthropicStep([['type' => 'text', 'text' => 'Found a hoodie.']]);
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push($toolStep)->push($answerStep)->push($toolStep)->push($answerStep)]);

    $agent = new CachedSkillAgent;
    $agent->prompt('Find a hoodie', provider: 'anthropic', model: 'claude-test');
    SynapseConversationalAgent::for($agent)->prompt('Find a hoodie', provider: 'anthropic', model: 'claude-test');

    $requests = Http::recorded()->map(fn (array $pair): array => json_decode($pair[0]->body(), true, flags: JSON_THROW_ON_ERROR));
    expect($requests)->toHaveCount(4)
        ->and($requests[2])->toBe($requests[0])
        ->and($requests[3])->toBe($requests[1])
        ->and($requests[2]['system'][0]['text'])->toContain('Never include personal email addresses in your answer.')
        ->and($requests[2]['system'][0]['cache_control'])->toBe(['type' => 'ephemeral', 'ttl' => '1h'])
        ->and(collect($requests[2]['tools'])->last()['cache_control'])->toBe(['type' => 'ephemeral', 'ttl' => '1h'])
        ->and(array_column($requests[2]['tools'], 'name'))->toContain('LoadSkill')
        ->and($requests[2]['tool_choice'])->toBe(['type' => 'any'])
        ->and($requests[3]['tool_choice'] ?? null)->not->toBe(['type' => 'any']);
});

it('runs application middleware once per step after forwarding cache options', function () {
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(anthropicStep([
            ['type' => 'tool_use', 'id' => 'call_search', 'name' => 'SearchProductsTool', 'input' => ['query' => 'hoodie']],
        ], 'tool_use'))
        ->push(anthropicStep([['type' => 'text', 'text' => 'Found a hoodie.']]))]);
    $seen = [];
    $agent = new CachedSkillAgent;
    $agent->stepMiddleware = [
        function (PendingStep $step, Closure $next) use (&$seen): StepResult {
            $seen[] = [$step->number, count($step->steps), $step->options?->cacheInstructions?->ttl, $step->options?->toolChoice?->mode];

            return $next($step->withMaxTokens(123)->withInstructions('Application instructions.'));
        },
    ];
    SynapseConversationalAgent::for($agent)->prompt('Find a hoodie', provider: 'anthropic', model: 'claude-test');

    expect($seen)->toBe([[0, 0, '1h', 'required'], [1, 1, '1h', null]]);
    foreach (Http::recorded() as [$request]) {
        expect($request['max_tokens'])->toBe(123)
            ->and($request['system'][0]['text'])->toContain('Application instructions.');
    }
});

it('propagates real gateway tool failures through the wrapper', function () {
    Event::fake([ToolFailed::class]);
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicStep([
        ['type' => 'tool_use', 'id' => 'call_broken', 'name' => 'BrokenLedgerTool', 'input' => ['entry' => '42']],
    ], 'tool_use'))]);

    expect(fn () => SynapseConversationalAgent::for(new FlakyToolAgent)
        ->prompt('Look up entry 42', provider: 'anthropic', model: 'claude-test'))
        ->toThrow(RuntimeException::class, 'Ledger service unavailable');
    Event::assertDispatchedTimes(ToolFailed::class, 1);
    Http::assertSentCount(1);
});

it('keeps preliminary child output separate from the settled parent tool result', function () {
    fakeAgent(SupportAgent::class, [new ToolCall('child_call', 'WeatherAgent', ['task' => 'Check weather']), 'Bring an umbrella.']);
    WeatherAgent::fake(['It is raining.']);
    $agent = (new SupportAgent)->withTools([new AgentTool(new WeatherAgent)]);
    $stream = SynapseConversationalAgent::for($agent)->stream('Check weather');
    $completed = null;
    $stream->then(function (StreamedAgentResponse $response) use (&$completed): void {
        $completed = $response;
    });
    $results = collect(iterator_to_array($stream))->whereInstanceOf(ToolResult::class)->values();

    expect($results->filter(fn (ToolResult $event): bool => $event->preliminary))->not->toBeEmpty()
        ->and($results->last()->preliminary)->toBeFalse()
        ->and($results->last()->toolResult->id)->toBe('child_call')
        ->and($results->last()->toolResult->result)->toBe('It is raining.')
        ->and($completed->toolResults)->toHaveCount(1)
        ->and($completed->text)->toBe('Bring an umbrella.');
});

it('never consults SDK conversation tables when supplying Synapse history', function () {
    fakeAgent(SupportAgent::class, ['A private playground answer.']);
    $agent = new class extends SupportAgent implements RemembersConversationsContract
    {
        use RemembersConversations;
    };
    $agent->continue('sdk-only-conversation', as: (object) ['id' => 1]);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    SynapseConversationalAgent::for($agent)->prompt('Hello');

    expect(implode("\n", $queries))->not->toContain('agent_conversations')
        ->not->toContain('agent_conversation_messages')
        ->and($agent->currentConversation())->toBe('sdk-only-conversation');
});
