<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Redberry\Synapse\Chat\AgentInvoker;
use Redberry\Synapse\Chat\StreamEmitter;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Models\SynapseToolInvocation;
use Redberry\Synapse\Synapse;
use Redberry\Synapse\Tests\Fixtures\ApprovalSearchTool;
use Workbench\App\Agents\SupportAgent;
use Workbench\App\Agents\WeatherAgent;

beforeEach(function () {
    Synapse::auth(fn (): bool => true);
    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
    Http::preventStrayRequests();
});

it('finishes a multi step stream exactly once after persistence', function () {
    $emitter = new StreamEmitter(false);
    $emitter->event((new StreamStart('s1', 'openai', 'test', time()))->withInvocationId('root'));
    $emitter->event(new TextDelta('t1', 'one', 'First.', time()));
    $emitter->event(new StreamEnd('e1', 'tool_calls', new TextUsage(10, 2), time()));
    $emitter->event(new StreamStart('s2', 'openai', 'test', time()));
    $emitter->event(new TextDelta('t2', 'two', 'Last.', time()));
    $emitter->event(new StreamEnd('e2', 'stop', new TextUsage(20, 3), time()));
    expect(array_map(fn (string $part): string => json_decode($part, true)['type'], $emitter->written()))
        ->toBe(['start', 'text-delta', 'text-delta']);
    $emitter->finish('saved', (new TextUsage(30, 5))->toArray());
    $emitter->finish('saved');
    $written = $emitter->written();
    expect(array_pop($written))->toBe('[DONE]');
    $parts = array_map(fn (string $part): array => json_decode($part, true), $written);
    expect(array_column($parts, 'type'))->toBe(['start', 'text-delta', 'text-delta', 'finish', 'data-synapse-end'])
        ->and($parts[4]['data']['assistantMessageId'])->toBe('saved')
        ->and($parts[4]['data']['usage']['input_tokens'])->toBe(30);
});

it('does not settle a parent tool from preliminary child output', function () {
    $emitter = new StreamEmitter(false);
    $emitter->event(new ToolCallEvent('call', new ToolCall('parent', 'Child', []), time()));
    $emitter->event(new ToolResultEvent('partial', new ToolResult('parent', 'Child', [], 'Partial'), true, null, time(), preliminary: true));
    $emitter->event(new ToolResultEvent('final', new ToolResult('parent', 'Child', [], 'Done'), true, null, time()));
    $parts = array_map(fn (string $part): array => json_decode($part, true), $emitter->written());
    expect(array_column($parts, 'type'))->toBe(['start', 'tool-input-available', 'tool-output-available'])
        ->and($parts[2]['output'])->toBe('Done');
});

it('maps denied or failed tool results to an error part', function (bool $denied) {
    $emitter = new StreamEmitter(false);
    $emitter->event(new ToolCallEvent('call', new ToolCall('tool', 'Search', []), time()));
    $emitter->event(new ToolResultEvent('result', new ToolResult('tool', 'Search', [], 'Unavailable'), $denied, 'Unavailable', time(), denied: $denied));
    $part = json_decode($emitter->written()[2], true);
    expect($part)->toBe($denied
        ? ['type' => 'tool-output-denied', 'toolCallId' => 'tool']
        : ['type' => 'tool-output-error', 'toolCallId' => 'tool', 'errorText' => 'Unavailable']);
})->with([true, false]);

it('substitutes invalid UTF8 instead of dropping a streamed text part', function () {
    $emitter = new StreamEmitter(false);
    $emitter->text("Partial \xB1 answer", 'message');
    expect(json_decode($emitter->written()[2], true)['delta'])->toBe("Partial \u{FFFD} answer");
});

it('ends approval requests as paused unexecuted tools', function (string $agent, string $slug) {
    app()->instance($agent, (new $agent)->withTools([new ApprovalSearchTool]));
    fakeAgent($agent, [new ToolCall('approval', 'ApprovalSearchTool', ['query' => 'hoodie'])]);
    $response = sendMessage($slug, 'Search');
    $assistant = SynapseMessage::where('role', 'assistant')->sole();
    $tool = SynapseToolInvocation::sole();
    expect($assistant->status)->toBe('paused')
        ->and($tool->status)->toBe('paused')
        ->and($tool->tool_invocation_id)->toBeNull()
        ->and($tool->result)->toBeNull()
        ->and(chatPart($response, 'data-synapse-notice')['data']['message'])->toBe(AgentInvoker::APPROVAL_NOTICE)
        ->and(chatPart($response, 'data-synapse-end')['data']['status'])->toBe('paused')
        ->and(substr_count($response->streamedContent(), 'data: [DONE]'))->toBe(1)
        ->and(SynapseMessage::where('role', 'error')->count())->toBe(0);
})->with([
    [SupportAgent::class, 'workbench.app.agents.support-agent'],
    [WeatherAgent::class, 'workbench.app.agents.weather-agent'],
]);

it('keeps private replay blocks out of completion and reload payloads', function () {
    fakeAgent(SupportAgent::class, ['Visible answer.']);
    $response = sendMessage('workbench.app.agents.support-agent', 'Hello');
    $assistant = SynapseMessage::where('role', 'assistant')->sole();
    $completion = chatPart($response, 'data-synapse-end')['data']['turn']['message'];
    $assistant->update(['steps' => [['replay_blocks' => ['signature' => 'private-secret']]]]);
    $detail = $this->getJson('/synapse/api/conversations/'.$assistant->conversation_id)->assertOk();
    expect($detail->json('messages.1'))->toBe($completion)
        ->and($completion)->not->toHaveKey('steps')
        ->and($detail->getContent())->not->toContain('private-secret', 'replay_blocks');
});

it('retains partial output when a real provider stream terminates unsuccessfully', function (bool $premature) {
    config(['ai.providers.anthropic.key' => 'fake']);
    app()->instance(SupportAgent::class, new class extends SupportAgent
    {
        public function provider(): string
        {
            return 'anthropic';
        }

        public function model(): string
        {
            return 'claude-test';
        }
    });
    $events = [
        ['type' => 'message_start', 'message' => ['model' => 'claude-test', 'usage' => ['input_tokens' => 12, 'output_tokens' => 0]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Partial provider answer.']],
        ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Provider overloaded.']],
    ];
    if ($premature) {
        array_pop($events);
    }
    $body = implode('', array_map(fn (array $event): string => 'data: '.json_encode($event)."\n\n", $events));
    Http::fake(['api.anthropic.com/*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);
    $response = sendMessage('workbench.app.agents.support-agent', 'Hello');
    expect(chatPart($response, 'text-delta')['delta'])->toBe('Partial provider answer.')
        ->and(chatPart($response, 'error')['errorText'])->toBe($premature ? 'The provider ended the stream without a completion reason.' : 'Provider overloaded.')
        ->and(chatPart($response, 'data-synapse-end')['data']['turn']['message']['status'])->toBe('failed')
        ->and(SynapseMessage::where('role', 'assistant')->sole()->content)->toBe('Partial provider answer.')
        ->and(SynapseMessage::where('role', 'assistant')->sole()->input_tokens)->toBeNull()
        ->and(array_count_values(chatPartTypes($response))['error'])->toBe(1)
        ->and(substr_count($response->streamedContent(), 'data: [DONE]'))->toBe(1);
    Http::assertSentCount(1);
})->with([false, true]);
