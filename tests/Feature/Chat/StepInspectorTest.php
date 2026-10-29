<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ProviderToolCall;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Tools\AgentTool;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Synapse;
use Redberry\Synapse\Tests\Fixtures\InterruptedAnswerAgent;
use Redberry\Synapse\Tests\Fixtures\StepSequenceAgent;
use Redberry\Synapse\Tests\Fixtures\StreamSequenceAgent;
use Redberry\Synapse\Tests\Fixtures\StructuredFailoverAgent;
use Workbench\App\Agents\ExtractorAgent;
use Workbench\App\Agents\SupportAgent;
use Workbench\App\Agents\WeatherAgent;
use Workbench\App\Tools\BrokenLedgerTool;

beforeEach(function () {
    Synapse::auth(fn (): bool => true);
    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
    Http::preventStrayRequests();
    fakeAgent(SupportAgent::class);
});

it('projects ordered steps with distinct tool executions', function () {
    app()->instance(SupportAgent::class, new StepSequenceAgent([
        new StepResponse('', [new ToolCall('one', 'SearchProductsTool', ['query' => 'hoodie']), new ToolCall('two', 'SearchProductsTool', ['query' => 'hoodie'])], FinishReason::ToolCalls, new TextUsage(10, 2, 3, null, 1), new Meta('openai', 'first')),
        new StepResponse('Done.', [], FinishReason::Stop, new TextUsage(20, 4), new Meta('openai', 'second')),
    ]));
    $turn = chatPart(sendMessage('workbench.app.agents.support-agent', 'Search'), 'data-synapse-end')['data']['turn'];
    $steps = $turn['message']['step_details'];
    expect(array_column($steps, 'number'))->toBe([1, 2])
        ->and(array_column($steps, 'model'))->toBe(['first', 'second'])
        ->and($steps[0]['model_duration_ms'])->toBeNull()
        ->and($steps[0]['completion'])->toBe('response')
        ->and($steps[0]['usage']['input_tokens'])->toBe(10)
        ->and(array_column($steps[0]['tools'], 'call_id'))->toBe(['one', 'two'])
        ->and(array_column($steps[0]['tools'], 'status'))->toBe(['success', 'success'])
        ->and($steps[0]['tools'][0]['observation_id'])->not->toBe($steps[0]['tools'][1]['observation_id'])
        ->and($steps[1]['text'])->toBe('Done.');
    $message = SynapseMessage::findOrFail($turn['message']['id']);
    $this->getJson('/synapse/api/conversations/'.$message->conversation_id)->assertJsonPath('messages.1', $turn['message']);
});

it('projects historical fields without exposing private step data or inventing counts', function () {
    sendMessage('workbench.app.agents.support-agent', 'Hi');
    $message = SynapseMessage::where('role', 'assistant')->sole();
    $message->update(['step_observations' => null, 'steps' => [[
        'text' => 'Historical answer', 'reasoning' => 'Recorded thought', 'finish_reason' => 'unknown',
        'meta' => ['provider' => 'old', 'model' => 'old-model', 'secret' => 'PRIVATE_MARKER'],
        'replay_blocks' => [['signature' => 'PRIVATE_MARKER']], 'raw_response' => 'PRIVATE_MARKER',
        'usage' => ['output_tokens' => 0], 'tool_calls' => [],
    ]]]);
    $response = $this->getJson('/synapse/api/conversations/'.$message->conversation_id);
    $response->assertJsonPath('messages.1.step_details.0.model_duration_ms', null)
        ->assertJsonPath('messages.1.step_details.0.completion', 'unverified')
        ->assertJsonPath('messages.1.step_details.0.usage.input_tokens', null)
        ->assertJsonPath('messages.1.step_details.0.usage.output_tokens', 0);
    expect($response->getContent())->not->toContain('PRIVATE_MARKER');
});

it('captures SDK model time separately from tool time', function () {
    $observed = [];
    Event::listen(StepCompleted::class, function (StepCompleted $event) use (&$observed): void {
        $observed[] = $event->time;
    });
    fakeAgent(SupportAgent::class, [new ToolCall('search', 'SearchProductsTool', ['query' => 'hoodie']), AgentResponse::fakeWithReasoning('Checked stock.', 'Available.')]);
    $turn = chatPart(sendMessage('workbench.app.agents.support-agent', 'Search'), 'data-synapse-end')['data']['turn'];
    $steps = $turn['message']['step_details'];
    expect(array_column($steps, 'model_duration_ms'))->toBe($observed)
        ->and(array_column($steps, 'completion'))->toBe(['completed', 'completed'])
        ->and($steps[0]['tools'][0]['duration_ms'])->toBe($turn['tool_invocations'][0]['duration_ms'])
        ->and($steps[1]['reasoning'])->toBe('Checked stock.');
});

it('retains the model outcome when a subsequent tool fails', function () {
    app()->instance(SupportAgent::class, (new SupportAgent)->withTools([new BrokenLedgerTool]));
    fakeAgent(SupportAgent::class, [new ToolCall('broken', 'BrokenLedgerTool', ['entry' => '42'])]);
    $turn = chatPart(sendMessage('workbench.app.agents.support-agent', 'Write'), 'data-synapse-end')['data']['turn'];
    expect($turn['message']['status'])->toBe('failed')
        ->and($turn['message']['step_details'][0]['finish_reason'])->toBe('tool_calls')
        ->and($turn['message']['step_details'][0]['completion'])->toBe('completed')
        ->and($turn['message']['step_details'][0]['tools'][0]['status'])->toBe('error');
});

it('labels synthetic partial steps without claiming reported usage', function () {
    app()->instance(SupportAgent::class, new InterruptedAnswerAgent);
    $turn = chatPart(sendMessage('workbench.app.agents.support-agent', 'Search'), 'data-synapse-end')['data']['turn'];
    $steps = $turn['message']['step_details'];
    expect($steps)->toHaveCount(2)
        ->and($steps[1]['completion'])->toBe('incomplete')
        ->and($steps[1]['text'])->toBe('The hoodie stock is')
        ->and($steps[1]['model_duration_ms'])->toBeNull()
        ->and(array_filter($steps[1]['usage'], fn ($value) => $value !== null))->toBe([])
        ->and($turn['message']['usage']['input_tokens'])->toBe(10);
});

it('keeps reused model call IDs distinct across failover', function () {
    app()->instance(ExtractorAgent::class, new StructuredFailoverAgent);
    fakeAgent(ExtractorAgent::class, StructuredFailoverAgent::responses());
    $turn = chatPart(sendMessage('workbench.app.agents.extractor-agent', 'Search'), 'data-synapse-end')['data']['turn'];
    $steps = $turn['message']['step_details'];
    expect(array_column($steps, 'provider'))->toBe(['openai', 'anthropic', 'anthropic'])
        ->and(array_column($steps, 'number'))->toBe([1, 2, 3])
        ->and($steps[0]['tools'][0]['call_id'])->toBe('search')
        ->and($steps[1]['tools'][0]['call_id'])->toBe('search')
        ->and($steps[0]['tools'][0]['observation_id'])->not->toBe($steps[1]['tools'][0]['observation_id'])
        ->and($turn['message']['usage']['input_tokens'])->toBe(20);
});

it('keeps explicitly reported zero counts while omitting unreported subsets', function () {
    app()->instance(SupportAgent::class, new StepSequenceAgent([
        new StepResponse('Answer.', [], FinishReason::Stop, new TextUsage(100, 20, 0, 30, 5), new Meta('openai', 'test')),
    ]));
    $message = chatPart(sendMessage('workbench.app.agents.support-agent', 'Hi'), 'data-synapse-end')['data']['turn']['message'];
    expect($message['step_details'][0]['usage'])->toBe([
        'input_tokens' => 100, 'output_tokens' => 20, 'cache_read_input_tokens' => 0, 'cache_write_input_tokens' => 30, 'reasoning_tokens' => 5,
    ])->and($message['usage']['input_tokens'] + $message['usage']['output_tokens'])->toBe(120);
});

it('projects provider tool observations without exposing private SDK provider payloads', function () {
    app()->instance(SupportAgent::class, new StreamSequenceAgent([
        new ProviderToolEvent('provider-event', 'web-1', 'web_search_call', ['query' => 'Laravel'], 'completed', time(), 'openai'),
    ], new StepResponse('', [], FinishReason::Stop, new TextUsage(10, 2), new Meta('openai', 'test'), providerToolCalls: [
        new ProviderToolCall('web-1', 'web_search_call', ['private' => 'PRIVATE_PROVIDER_MARKER']),
        new ProviderToolCall('web-2', 'web_search_call', ['private' => 'PRIVATE_PROVIDER_MARKER']),
    ])));
    $response = sendMessage('workbench.app.agents.support-agent', 'Search');
    $tools = chatPart($response, 'data-synapse-end')['data']['turn']['message']['step_details'][0]['tools'];
    expect($tools)->toHaveCount(2)
        ->and(collect($tools)->firstWhere('call_id', 'web-1')['status'])->toBe('success')
        ->and(collect($tools)->firstWhere('call_id', 'web-2')['status'])->toBe('unavailable')
        ->and($response->streamedContent())->not->toContain('PRIVATE_PROVIDER_MARKER');
});

it('keeps child model calls out of the root step inspector', function () {
    app()->instance(SupportAgent::class, (new SupportAgent)->withTools([new AgentTool(new WeatherAgent)]));
    fakeAgent(SupportAgent::class, [new ToolCall('child', 'WeatherAgent', ['task' => 'Weather']), 'Bring an umbrella.']);
    WeatherAgent::fake([new ToolCall('weather', 'GetWeatherTool', ['city' => 'Tbilisi']), 'Child weather answer.']);
    $response = sendMessage('workbench.app.agents.support-agent', 'Weather');
    $steps = chatPart($response, 'data-synapse-end')['data']['turn']['message']['step_details'];
    expect($steps)->toHaveCount(2)
        ->and($steps[0]['tools'])->toHaveCount(1)
        ->and($steps[0]['tools'][0]['name'])->toBe('WeatherAgent')
        ->and($steps[1]['text'])->toBe('Bring an umbrella.');
});

it('retains partial reasoning in an incomplete inspector step', function () {
    app()->instance(SupportAgent::class, new StreamSequenceAgent([
        new ReasoningDelta('r', 'reasoning', 'Checking stock...', time()),
        new TextDelta('t', 'answer', 'Partial answer.', time()),
        new Error('error', 'overloaded', 'Unavailable', false, time()),
    ]));
    $steps = chatPart(sendMessage('workbench.app.agents.support-agent', 'Search'), 'data-synapse-end')['data']['turn']['message']['step_details'];
    expect($steps[0]['completion'])->toBe('incomplete')
        ->and($steps[0]['reasoning'])->toBe('Checking stock...');
});
