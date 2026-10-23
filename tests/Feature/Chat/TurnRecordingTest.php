<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Tools\AgentTool;
use Redberry\Synapse\Chat\AgentInvoker;
use Redberry\Synapse\Chat\MessageHistory;
use Redberry\Synapse\Discovery\AgentDiscovery;
use Redberry\Synapse\Models\SynapseConversation;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Models\SynapseToolInvocation;
use Redberry\Synapse\Synapse;
use Redberry\Synapse\Tests\Fixtures\RecordingEmitter;
use Redberry\Synapse\Tests\Fixtures\StepSequenceAgent;
use Redberry\Synapse\Tests\Fixtures\StreamSequenceAgent;
use Redberry\Synapse\Tests\Fixtures\StringableResultTool;
use Workbench\App\Agents\ExtractorAgent;
use Workbench\App\Agents\FlakyToolAgent;
use Workbench\App\Agents\SupportAgent;
use Workbench\App\Agents\WeatherAgent;
use Workbench\App\Tools\BrokenLedgerTool;
use Workbench\App\Tools\SearchProductsTool;

beforeEach(function () {
    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
});

function recordTurn(string $message = 'Hello', ?string $id = null, string $slug = 'workbench.app.agents.support-agent'): RecordingEmitter
{
    $emitter = new RecordingEmitter;
    $agent = app(AgentDiscovery::class)->find($slug);
    app(AgentInvoker::class)->handle($agent, $message, $id, $emitter);

    return $emitter;
}

it('stores a completed turn with inclusive usage and ordered display parts', function () {
    fakeAgent(SupportAgent::class, [new TextResponse('Counted.', new TextUsage(inputTokens: 100, outputTokens: 20, cacheReadInputTokens: 30, reasoningTokens: 5), new Meta('openai', 'chosen-model'))]);
    recordTurn();
    $assistant = SynapseMessage::where('role', 'assistant')->sole();
    expect($assistant->content)->toBe('Counted.')
        ->and($assistant->status)->toBe('completed')
        ->and($assistant->input_tokens)->toBe(100)
        ->and($assistant->output_tokens)->toBe(20)
        ->and($assistant->usage['cache_read_input_tokens'])->toBe(30)
        ->and($assistant->usage['cache_write_input_tokens'])->toBeNull()
        ->and($assistant->steps)->toHaveCount(1)
        ->and($assistant->parts)->toBe([['id' => 'part-0', 'type' => 'text', 'text' => 'Counted.']]);
});

it('replays each text and tool step in the order the model produced them', function () {
    fakeAgent(SupportAgent::class);
    app()->instance(SupportAgent::class, new StepSequenceAgent([
        new StepResponse('First search.', [new ToolCall('one', 'SearchProductsTool', ['query' => 'hoodie'])], FinishReason::ToolCalls, new TextUsage, new Meta('openai', 'test')),
        new StepResponse('Second search.', [new ToolCall('two', 'SearchProductsTool', ['query' => 'scarf'])], FinishReason::ToolCalls, new TextUsage, new Meta('openai', 'test')),
        new StepResponse('Both available.', [], FinishReason::Stop, new TextUsage, new Meta('openai', 'test')),
    ]));
    recordTurn();
    $assistant = SynapseMessage::where('role', 'assistant')->sole();
    $history = app(MessageHistory::class)->for($assistant->conversation_id);
    expect($history)->toHaveCount(6)
        ->and($history[1])->toBeInstanceOf(AssistantMessage::class)
        ->and($history[1]->content)->toBe('First search.')
        ->and($history[2])->toBeInstanceOf(ToolResultMessage::class)
        ->and($history[2]->toolResults->sole()->id)->toBe('one')
        ->and($history[3]->content)->toBe('Second search.')
        ->and($history[4]->toolResults->sole()->id)->toBe('two')
        ->and($history[5]->content)->toBe('Both available.')
        ->and(array_column($assistant->parts, 'type'))->toBe(['text', 'tool', 'text', 'tool', 'text']);
});

it('keeps reasoning from the SDK stream on the persisted turn', function () {
    fakeAgent(SupportAgent::class, [AgentResponse::fakeWithReasoning('Check the catalog.', 'In stock.')]);
    recordTurn();
    $assistant = SynapseMessage::where('role', 'assistant')->sole();
    expect($assistant->meta['reasoning'])->toBe('Check the catalog.')
        ->and(array_column($assistant->parts, 'type'))->toBe(['reasoning', 'text']);
});

it('keeps the user message without inventing an assistant when the provider fails immediately', function () {
    fakeAgent(SupportAgent::class, [fn () => throw new RuntimeException('Provider unavailable')]);
    recordTurn();
    expect(SynapseMessage::orderBy('id')->pluck('role')->all())->toBe(['user', 'error'])
        ->and(SynapseMessage::where('role', 'error')->sole()->content)->toBe('Provider unavailable');
});

it('retains successful work before a later tool fails', function () {
    $agent = (new SupportAgent)->withTools([new SearchProductsTool, new BrokenLedgerTool]);
    app()->instance(SupportAgent::class, $agent);
    fakeAgent(SupportAgent::class, [
        new ToolCall('search', 'SearchProductsTool', ['query' => 'hoodie']),
        new ToolCall('broken', 'BrokenLedgerTool', ['entry' => '42']),
    ]);
    recordTurn();
    $assistant = SynapseMessage::where('role', 'assistant')->sole();
    $history = app(MessageHistory::class)->for($assistant->conversation_id);
    expect($assistant->status)->toBe('failed')
        ->and($assistant->steps)->toHaveCount(2)
        ->and($assistant->steps[0]['tool_results'][0]['id'])->toBe('search')
        ->and(SynapseMessage::where('role', 'error')->count())->toBe(1)
        ->and(SynapseToolInvocation::orderBy('id')->pluck('status')->all())->toBe(['success', 'error'])
        ->and($history[4]->toolResults->sole()->result)->toContain('may or may not have run');
});

it('correlates repeated identical calls without confusing execution and model IDs', function () {
    fakeAgent(SupportAgent::class);
    app()->instance(SupportAgent::class, new StepSequenceAgent([
        new StepResponse('', [new ToolCall('one', 'SearchProductsTool', ['query' => 'hoodie']), new ToolCall('two', 'SearchProductsTool', ['query' => 'hoodie'])], FinishReason::ToolCalls, new TextUsage, new Meta('openai', 'test')),
        new StepResponse('Done.', [], FinishReason::Stop, new TextUsage, new Meta('openai', 'test')),
    ]));
    recordTurn();
    $tools = SynapseToolInvocation::orderBy('id')->get();
    expect($tools)->toHaveCount(2)
        ->and($tools->pluck('tool_call_id')->all())->toBe(['one', 'two'])
        ->and($tools->pluck('tool_invocation_id')->unique())->toHaveCount(2)
        ->and($tools[0]->tool_invocation_id)->not->toBe('one')
        ->and($tools->pluck('provider')->all())->toBe(['openai', 'openai']);
});

it('correlates stringable tool results with their execution', function () {
    Synapse::auth(fn (): bool => true);
    app()->instance(SupportAgent::class, (new SupportAgent)->withTools([new StringableResultTool]));
    fakeAgent(SupportAgent::class, [new ToolCall('stringable-call', 'StringableResultTool', []), 'Done.']);

    $response = sendMessage('workbench.app.agents.support-agent', 'Check stock')->assertOk();
    expect(SynapseToolInvocation::count())->toBe(1);
    $tool = SynapseToolInvocation::sole();
    $assistant = SynapseMessage::where('role', 'assistant')->sole();
    $turn = chatPart($response, 'data-synapse-end')['data']['turn'];

    expect($tool->tool_call_id)->toBe('stringable-call')
        ->and($tool->tool_invocation_id)->not->toBeNull()->not->toBe('stringable-call')
        ->and($tool->duration_ms)->not->toBeNull()
        ->and($tool->result)->toBe('Hoodie stock: 12.')
        ->and($tool->status)->toBe('success')
        ->and($tool->message_id)->toBe($assistant->id)
        ->and(collect($assistant->parts)->where('type', 'tool')->pluck('tool_invocation_id')->all())->toBe([$tool->id])
        ->and($turn['tool_invocations'])->toHaveCount(1)
        ->and($turn['tool_invocations'][0]['result'])->toBe($tool->result)
        ->and($turn['tool_invocations'][0]['duration_ms'])->toBe($tool->duration_ms)
        ->and($assistant->steps[0]['tool_results'][0]['result'])->toBe($tool->result);

    $this->getJson('/synapse/api/conversations/'.$assistant->conversation_id)
        ->assertOk()->assertJsonPath('tool_invocations', $turn['tool_invocations']);
});

it('does not retain listeners or observations across repeated sends', function () {
    fakeAgent(SupportAgent::class, [new ToolCall('one', 'SearchProductsTool', ['query' => 'hoodie']), 'First.', new ToolCall('two', 'SearchProductsTool', ['query' => 'scarf']), 'Second.']);
    $calls = 0;
    Event::listen(InvokingTool::class, function () use (&$calls): void {
        $calls++;
    });
    $listeners = count(app('events')->getListeners(InvokingTool::class));
    recordTurn('First');
    recordTurn('Second');
    expect(count(app('events')->getListeners(InvokingTool::class)))->toBe($listeners)
        ->and($calls)->toBe(2)
        ->and(SynapseConversation::count())->toBe(2)
        ->and(SynapseToolInvocation::count())->toBe(2)
        ->and(SynapseToolInvocation::pluck('conversation_id')->unique())->toHaveCount(2);
});

it('ignores agent activity after the recording scope closes', function () {
    fakeAgent(SupportAgent::class, ['Inside.']);
    recordTurn();
    $before = SynapseToolInvocation::count();
    SupportAgent::fake([new ToolCall('outside', 'SearchProductsTool', ['query' => 'private']), 'Outside.']);
    (new SupportAgent)->prompt('Outside Synapse');
    expect(SynapseToolInvocation::count())->toBe($before)
        ->and(SynapseMessage::count())->toBe(2);
});

it('keeps nested agents from changing root provider announcements or tool rows', function () {
    app()->instance(SupportAgent::class, (new SupportAgent)->withTools([new AgentTool(new WeatherAgent)]));
    fakeAgent(SupportAgent::class, [new ToolCall('child', 'WeatherAgent', ['task' => 'Weather']), 'Bring an umbrella.']);
    WeatherAgent::fake([new ToolCall('weather', 'GetWeatherTool', ['city' => 'Tbilisi']), 'Rain.']);
    $emitter = recordTurn();
    expect(SynapseToolInvocation::count())->toBe(1)
        ->and(SynapseToolInvocation::sole()->name)->toBe('WeatherAgent')
        ->and(SynapseToolInvocation::sole()->status)->toBe('success')
        ->and(collect($emitter->written())->filter(fn (string $part): bool => str_contains($part, 'data-synapse-provider')))->toHaveCount(1);
});

it('records structured output through the undecorated single shot path', function () {
    fakeAgent(ExtractorAgent::class, [['name' => 'Ada', 'email' => 'ada@example.com']]);
    recordTurn('Extract Ada', slug: 'workbench.app.agents.extractor-agent');
    expect(SynapseMessage::where('role', 'assistant')->sole()->meta['structured'])->toBe(['name' => 'Ada', 'email' => 'ada@example.com']);
});

it('retains partial text and reasoning when an error event becomes an exception', function () {
    fakeAgent(SupportAgent::class);
    app()->instance(SupportAgent::class, new StreamSequenceAgent([
        new ReasoningDelta('r1', 'reason', 'Thinking...', time()),
        new TextDelta('t1', 'text', 'Partial answer', time()),
        new Error('e1', 'overloaded', 'Provider stopped', false, time()),
    ]));
    recordTurn();
    $assistant = SynapseMessage::where('role', 'assistant')->sole();
    expect($assistant->status)->toBe('failed')
        ->and($assistant->content)->toBe('Partial answer')
        ->and($assistant->input_tokens)->toBeNull()
        ->and($assistant->usage)->toBe([])
        ->and($assistant->meta['reasoning'])->toBe('Thinking...')
        ->and(array_column($assistant->parts, 'type'))->toBe(['reasoning', 'text'])
        ->and(SynapseMessage::where('role', 'error')->count())->toBe(1)
        ->and(SynapseMessage::where('role', 'error')->sole()->content)->toBe('Provider stopped')
        ->and(SynapseMessage::where('role', 'error')->sole()->metadata['stream_error']['type'])->toBe('overloaded');
});

it('retains an unfinished model call without claiming it executed', function () {
    fakeAgent(SupportAgent::class);
    app()->instance(SupportAgent::class, new StreamSequenceAgent([
        new Laravel\Ai\Streaming\Events\ToolCall('tc', new ToolCall('unfinished', 'SearchProductsTool', ['query' => 'hoodie']), time()),
        new Error('e1', 'overloaded', 'Provider stopped', false, time()),
    ]));
    recordTurn();
    $tool = SynapseToolInvocation::sole();
    expect($tool->status)->toBe('error')
        ->and($tool->tool_call_id)->toBe('unfinished')
        ->and($tool->tool_invocation_id)->toBeNull()
        ->and(SynapseMessage::where('role', 'assistant')->sole()->parts[0]['tool_invocation_id'])->toBe($tool->id);
});

it('does not guess which identical unanswered model call matches a failed execution', function () {
    fakeAgent(SupportAgent::class);
    $agent = (new StepSequenceAgent([
        new StepResponse('', [new ToolCall('one', 'BrokenLedgerTool', ['entry' => '42']), new ToolCall('two', 'BrokenLedgerTool', ['entry' => '42'])], FinishReason::ToolCalls, new TextUsage, new Meta('openai', 'test')),
    ]))->withTools([new BrokenLedgerTool]);
    app()->instance(SupportAgent::class, $agent);
    recordTurn();
    $execution = SynapseToolInvocation::whereNotNull('tool_invocation_id')->sole();
    expect($execution->status)->toBe('error')
        ->and($execution->tool_call_id)->toBeNull()
        ->and(SynapseToolInvocation::whereNotNull('tool_call_id')->pluck('tool_call_id')->all())->toBe(['one', 'two'])
        ->and(SynapseToolInvocation::where('status', 'success')->count())->toBe(0);
});

it('retains a successful tool result when another tool in the same step throws', function () {
    fakeAgent(SupportAgent::class);
    app()->instance(SupportAgent::class, (new StepSequenceAgent([
        new StepResponse('Checking.', [new ToolCall('search', 'SearchProductsTool', ['query' => 'hoodie']), new ToolCall('broken', 'BrokenLedgerTool', ['entry' => '42'])], FinishReason::ToolCalls, new TextUsage, new Meta('openai', 'test')),
    ]))->withTools([new SearchProductsTool, new BrokenLedgerTool]));
    recordTurn();
    $assistant = SynapseMessage::where('role', 'assistant')->sole();
    $results = app(MessageHistory::class)->for($assistant->conversation_id)[2]->toolResults;
    expect($assistant->steps)->toHaveCount(1)
        ->and($results[0]->id)->toBe('search')
        ->and($results[0]->failed)->toBeFalse()
        ->and($results[1]->id)->toBe('broken')
        ->and($results[1]->failed)->toBeTrue()
        ->and(SynapseToolInvocation::where('tool_call_id', 'search')->sole()->status)->toBe('success');
});

it('records rejected unknown tools without inventing execution IDs', function () {
    fakeAgent(SupportAgent::class, [new ToolCall('missing', 'UnavailableTool', []), 'Cannot run that.']);
    recordTurn();
    $tool = SynapseToolInvocation::sole();
    expect($tool->tool_call_id)->toBe('missing')
        ->and($tool->tool_invocation_id)->toBeNull()
        ->and($tool->status)->toBe('error')
        ->and($tool->error)->not->toBeEmpty()
        ->and(SynapseMessage::where('role', 'assistant')->sole()->status)->toBe('failed');
});

it('keeps provider identities separate when provider tools reuse an item ID', function () {
    fakeAgent(SupportAgent::class);
    app()->instance(SupportAgent::class, new StreamSequenceAgent([
        new ProviderToolEvent('p1', 'same', 'web_search_call', [], 'completed', time(), 'openai'),
        new ProviderToolEvent('p2', 'same', 'server_tool_use', ['name' => 'web_search'], 'started', time(), 'anthropic'),
        new Error('e1', 'overloaded', 'Provider stopped', false, time()),
    ]));
    recordTurn();
    $tools = SynapseToolInvocation::orderBy('id')->get();
    expect($tools)->toHaveCount(2)
        ->and($tools->pluck('provider')->all())->toBe(['openai', 'anthropic'])
        ->and($tools->pluck('status')->all())->toBe(['success', 'error'])
        ->and($tools->pluck('tool_invocation_id')->all())->toBe([null, null])
        ->and($tools->pluck('message_id')->unique()->all())->toBe([SynapseMessage::where('role', 'assistant')->sole()->id]);
});

it('joins text blocks within a provider step without inserting paragraph breaks', function () {
    fakeAgent(SupportAgent::class);
    app()->instance(SupportAgent::class, new StreamSequenceAgent([
        new TextDelta('t1', 'block-one', 'The answer ', time()),
        new TextDelta('t2', 'block-two', 'is 42.', time()),
    ], new StepResponse('The answer is 42.', [], FinishReason::Stop, new TextUsage, new Meta('openai', 'test'))));
    recordTurn();
    expect(SynapseMessage::where('role', 'assistant')->sole()->content)->toBe('The answer is 42.');
});

it('resets the recording scope after a failure before the next send', function () {
    fakeAgent(SupportAgent::class, fn (string $prompt) => $prompt === 'Fail' ? throw new RuntimeException('Unavailable') : 'Recovered.');
    recordTurn('Fail');
    recordTurn('Recover');
    expect(SynapseMessage::where('role', 'error')->count())->toBe(1)
        ->and(SynapseMessage::where('role', 'assistant')->sole()->content)->toBe('Recovered.')
        ->and(SynapseConversation::count())->toBe(2);
});

it('passes only prior turns to a conversational agent without accessing SDK storage', function () {
    $queries = [];
    DB::listen(function (QueryExecuted $event) use (&$queries): void {
        $queries[] = $event->sql;
    });
    $history = [];
    Event::listen(StreamingAgent::class, function (StreamingAgent $event) use (&$history): void {
        $history[] = [...$event->prompt->agent->messages()];
    });
    $prompts = [];
    fakeAgent(SupportAgent::class, function (string $prompt) use (&$prompts): string {
        $prompts[] = $prompt;

        return 'Answer.';
    });
    recordTurn('First');
    recordTurn('Second', SynapseConversation::sole()->id);
    expect($history[0])->toBe([])
        ->and(array_column($history[1], 'content'))->toBe(['First', 'Answer.'])
        ->and($prompts)->toBe(['First', 'Second'])
        ->and(implode("\n", $queries))->not->toContain('agent_conversations')->not->toContain('agent_conversation_messages');
});

it('keeps stateless turns independent in the same stored conversation', function () {
    $prompts = [];
    Event::listen(StreamingAgent::class, function (StreamingAgent $event) use (&$prompts): void {
        $prompts[] = $event->prompt->agent;
    });
    fakeAgent(WeatherAgent::class, ['Sunny.', 'Rain.']);
    recordTurn('First', slug: 'workbench.app.agents.weather-agent');
    recordTurn('Second', SynapseConversation::sole()->id, 'workbench.app.agents.weather-agent');
    expect($prompts)->toHaveCount(2)
        ->and($prompts[0])->toBeInstanceOf(WeatherAgent::class)
        ->and($prompts[1])->toBeInstanceOf(WeatherAgent::class)
        ->and(SynapseMessage::where('role', 'assistant')->pluck('content')->all())->toBe(['Sunny.', 'Rain.']);
});

it('keeps a handled child failure from marking the parent run failed', function () {
    app()->instance(SupportAgent::class, (new SupportAgent)->withTools([new AgentTool(new FlakyToolAgent)]));
    fakeAgent(SupportAgent::class, [new ToolCall('child', 'FlakyToolAgent', ['task' => 'Read ledger']), 'Child unavailable.']);
    FlakyToolAgent::fake([new ToolCall('ledger', 'BrokenLedgerTool', ['entry' => '42'])]);
    recordTurn();
    expect(SynapseToolInvocation::count())->toBe(1)
        ->and(SynapseToolInvocation::sole()->name)->toBe('FlakyToolAgent')
        ->and(SynapseToolInvocation::sole()->status)->toBe('success')
        ->and(SynapseToolInvocation::sole()->result)->toContain('Agent failed: Ledger service unavailable')
        ->and(SynapseMessage::where('role', 'error')->count())->toBe(0);
});

it('records a successful failover as a notice with the actual provider', function () {
    app()->instance(SupportAgent::class, new class extends SupportAgent
    {
        public function provider(): array
        {
            return ['openai' => 'primary', 'anthropic' => 'fallback'];
        }
    });
    $fallbackCalls = 0;
    fakeAgent(SupportAgent::class, function (string $prompt, $attachments, TextProvider $provider) use (&$fallbackCalls): ToolCall|string {
        if ($provider->name() === 'openai') {
            throw RateLimitedException::forProvider('openai');
        }

        return ++$fallbackCalls === 1 ? new ToolCall('search', 'SearchProductsTool', ['query' => 'hoodie']) : 'Recovered.';
    });
    recordTurn();
    $assistant = SynapseMessage::where('role', 'assistant')->sole();
    expect($assistant->status)->toBe('completed')
        ->and($assistant->meta['provider'])->toBe('anthropic')
        ->and($assistant->meta['model'])->toBe('fallback')
        ->and($assistant->parts[0]['type'])->toBe('notice')
        ->and($assistant->parts[0]['message'])->toContain('Failed over from openai')
        ->and(SynapseToolInvocation::sole()->provider)->toBe('anthropic')
        ->and(SynapseMessage::where('role', 'error')->count())->toBe(0);
});

it('keeps a recovered stream error as a notice without creating an error row', function () {
    fakeAgent(SupportAgent::class);
    app()->instance(SupportAgent::class, new StreamSequenceAgent([
        new Error('e1', 'retry', 'Retrying', true, time()),
        new TextDelta('t1', 'text', 'Recovered.', time()),
    ], new StepResponse('Recovered.', [], FinishReason::Stop, new TextUsage, new Meta('openai', 'test'))));
    recordTurn();
    $assistant = SynapseMessage::where('role', 'assistant')->sole();
    expect($assistant->status)->toBe('completed')
        ->and(array_column($assistant->parts, 'type'))->toBe(['notice', 'text'])
        ->and(SynapseMessage::where('role', 'error')->count())->toBe(0);
});
