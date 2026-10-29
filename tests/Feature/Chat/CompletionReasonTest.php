<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Redberry\Synapse\Chat\CompletionReason;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Synapse;
use Redberry\Synapse\Tests\Fixtures\StepSequenceAgent;
use Redberry\Synapse\Tests\Fixtures\StreamSequenceAgent;
use Redberry\Synapse\Tests\Fixtures\StructuredStepSequenceAgent;
use Workbench\App\Agents\SupportAgent;

beforeEach(function () {
    Synapse::auth(fn (): bool => true);
    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
    Http::preventStrayRequests();
    fakeAgent(SupportAgent::class);
});

it('projects the terminal reason identically at completion and reload', function (FinishReason $reason, string $agent) {
    app()->instance(SupportAgent::class, new $agent([
        new StepResponse('Partial answer.', [], $reason, new TextUsage(10, 2), new Meta('openai', 'test')),
    ]));
    $response = sendMessage('workbench.app.agents.support-agent', 'Explain');
    $message = chatPart($response, 'data-synapse-end')['data']['turn']['message'];
    expect($message['finish_reason'] ?? null)->toBe($reason->value)
        ->and($message['status'])->toBe('completed')
        ->and($message['content'])->toBe('Partial answer.')
        ->and($message['usage']['input_tokens'])->toBe(10)
        ->and(SynapseMessage::where('role', 'error')->count())->toBe(0);
    $saved = SynapseMessage::findOrFail($message['id']);
    $this->getJson('/synapse/api/conversations/'.$saved->conversation_id)->assertJsonPath('messages.1', $message);
})->with([FinishReason::Length, FinishReason::ContentFilter, FinishReason::Stop])
    ->with([StepSequenceAgent::class, StructuredStepSequenceAgent::class]);

it('records middleware cancellation without inventing a model step', function (bool $afterStep) {
    $steps = $afterStep ? [new StepResponse('Before cancellation.', [], FinishReason::Continue, new TextUsage(10, 2), new Meta('openai', 'test'))] : [];
    app()->instance(SupportAgent::class, new StepSequenceAgent([...$steps, StepResult::stop()]));
    $response = sendMessage('workbench.app.agents.support-agent', 'Explain');
    $message = chatPart($response, 'data-synapse-end')['data']['turn']['message'];
    expect($message['finish_reason'] ?? null)->toBe('cancelled')
        ->and($message['status'])->toBe('completed')
        ->and(count(SynapseMessage::findOrFail($message['id'])->steps))->toBe($afterStep ? 1 : 0)
        ->and($message['usage']['input_tokens'])->toBe($afterStep ? 10 : 0);
})->with([false, true]);

it('reports exceptional terminal failures through one error card', function (FinishReason $reason, string $agent) {
    app()->instance(SupportAgent::class, new $agent([
        new StepResponse('Partial answer.', [], $reason, new TextUsage(10, 2), new Meta('openai', 'test')),
    ]));
    $response = sendMessage('workbench.app.agents.support-agent', 'Explain');
    expect(chatPart($response, 'data-synapse-end')['data']['status'])->toBe('failed')
        ->and(SynapseMessage::where('role', 'error')->count())->toBe(1)
        ->and(SynapseMessage::where('role', 'assistant')->sole()->content)->toBe('Partial answer.')
        ->and(array_count_values(chatPartTypes($response))['error'] ?? 0)->toBe(1)
        ->and(chatPartTypes($response))->not->toContain('finish');
})->with([FinishReason::Error, FinishReason::Unknown])
    ->with([StepSequenceAgent::class, StructuredStepSequenceAgent::class]);

it('uses the final step rather than an intermediate continuation', function (FinishReason $initial) {
    app()->instance(SupportAgent::class, new StepSequenceAgent([
        new StepResponse('First.', $initial === FinishReason::ToolCalls ? [new ToolCall('lookup', 'SearchProductsTool', ['query' => 'hoodie'])] : [], $initial, new TextUsage(10, 2), new Meta('openai', 'test')),
        new StepResponse('Done.', [], FinishReason::Stop, new TextUsage(20, 3), new Meta('openai', 'test')),
    ]));
    $response = sendMessage('workbench.app.agents.support-agent', 'Explain');
    expect(chatPart($response, 'data-synapse-end')['data']['turn']['message']['finish_reason'])->toBe('stop');
})->with([FinishReason::ToolCalls, FinishReason::Continue]);

it('does not guess cancellation for an empty structured middleware result', function () {
    app()->instance(SupportAgent::class, new StructuredStepSequenceAgent([StepResult::stop()]));
    $response = sendMessage('workbench.app.agents.support-agent', 'Explain');
    expect(chatPart($response, 'data-synapse-end')['data']['turn']['message']['finish_reason'])->toBeNull()
        ->and(SynapseMessage::where('role', 'assistant')->sole()->meta)->toHaveKey('finish_reason', null);
});

it('reads historical reasons without modifying the record', function (array $meta, array $steps, ?string $expected) {
    app()->instance(SupportAgent::class, new StepSequenceAgent([new StepResponse('Old.', [], FinishReason::Stop, new TextUsage, new Meta('openai', 'test'))]));
    sendMessage('workbench.app.agents.support-agent', 'Explain');
    $message = SynapseMessage::where('role', 'assistant')->sole();
    $message->update(['meta' => $meta, 'steps' => $steps]);
    $this->getJson('/synapse/api/conversations/'.$message->conversation_id)
        ->assertJsonPath('messages.1.finish_reason', $expected);
    expect($message->fresh()->steps)->toBe($steps)->and($message->fresh()->meta)->toBe($meta);
})->with([
    'last step' => [[], [['finish_reason' => 'length'], ['finish_reason' => 'stop']], 'stop'],
    'length' => [[], [['finish_reason' => 'length']], 'length'],
    'cancelled' => [[], [['finish_reason' => 'cancelled']], 'cancelled'],
    'unknown' => [[], [['finish_reason' => 'unknown']], 'unknown'],
    'error' => [[], [['finish_reason' => 'error']], 'error'],
    'missing' => [[], [], null],
    'invalid' => [[], [['finish_reason' => ['private' => 'secret']]], null],
    'future reason' => [[], [['finish_reason' => 'future']], null],
    'explicit null' => [['finish_reason' => null], [['finish_reason' => 'length']], null],
    'terminal event overrides step' => [['finish_reason' => 'cancelled'], [['finish_reason' => 'continue']], 'cancelled'],
]);

it('uses only the completed root stream outcome', function () {
    $response = new StreamedAgentResponse('root', collect([
        (new StreamEnd('root-end', 'length', new TextUsage, time()))->withInvocationId('root'),
        (new StreamEnd('child-end', 'stop', new TextUsage, time()))->withInvocationId('child'),
    ]), new Meta);
    expect(CompletionReason::forResponse($response))->toBe(FinishReason::Length);
});

it('preserves provider error details without an extra finish error', function () {
    app()->instance(SupportAgent::class, new StreamSequenceAgent([
        new TextDelta('text', 'answer', 'Partial.', time()),
        new Error('error', 'provider_error', 'Specific provider failure.', false, time()),
    ], new StepResponse('Partial.', [], FinishReason::Error, new TextUsage(10, 2), new Meta)));
    $response = sendMessage('workbench.app.agents.support-agent', 'Explain');
    expect(SynapseMessage::where('role', 'error')->sole()->content)->toBe('Specific provider failure.')
        ->and(array_count_values(chatPartTypes($response))['error'] ?? 0)->toBe(1);
});

it('replaces earlier attempt reasons with the final failover outcome', function (?FinishReason $reason) {
    app()->instance(SupportAgent::class, new class($reason) extends StructuredStepSequenceAgent
    {
        public function __construct(public ?FinishReason $reason) {}

        public function provider(): array
        {
            return ['openai' => 'primary', 'anthropic' => 'fallback'];
        }

        public function middleware(): array
        {
            return [function (PendingStep $step): StepResponse {
                if ($step->provider === 'openai' && $step->number > 0) {
                    throw RateLimitedException::forProvider('openai');
                }
                if ($step->provider === 'anthropic' && $this->reason === null) {
                    throw new RuntimeException('Fallback failed.');
                }

                return new StepResponse('Partial.', [], $step->provider === 'openai' ? FinishReason::Continue : $this->reason, new TextUsage(10, 2), new Meta($step->provider, $step->model));
            }];
        }
    });
    $response = sendMessage('workbench.app.agents.support-agent', 'Explain');
    $message = chatPart($response, 'data-synapse-end')['data']['turn']['message'];
    expect($message['finish_reason'])->toBe($reason?->value)
        ->and($message['status'])->toBe($reason === null ? 'failed' : 'completed')
        ->and(chatPart($response, 'data-synapse-notice')['data']['message'])->toContain('Failed over from openai');
})->with([FinishReason::Stop, FinishReason::Length, FinishReason::ContentFilter, null]);

it('preserves content and accounting when a tool turn ends at a limit', function () {
    app()->instance(SupportAgent::class, new StructuredStepSequenceAgent([
        new StepResponse('Searching.', [new ToolCall('lookup', 'SearchProductsTool', ['query' => 'hoodie'])], FinishReason::ToolCalls, new TextUsage(10, 2), new Meta('openai', 'test')),
        new StepResponse('Partial answer.', [], FinishReason::Length, new TextUsage(20, 3), new Meta('openai', 'test', collect([new UrlCitation('https://example.com', 'Source')])), reasoning: 'Checked the results.'),
    ]));
    $response = sendMessage('workbench.app.agents.support-agent', 'Explain');
    $turn = chatPart($response, 'data-synapse-end')['data']['turn'];
    expect($turn['message']['finish_reason'])->toBe('length')
        ->and($turn['message']['content'])->toContain('Searching.', 'Partial answer.')
        ->and($turn['message']['meta']['reasoning'])->toBe('Checked the results.')
        ->and($turn['message']['sources'])->toBe([['url' => 'https://example.com', 'title' => 'Source']])
        ->and($turn['message']['usage']['input_tokens'])->toBe(30)
        ->and($turn['message']['usage']['output_tokens'])->toBe(5)
        ->and($turn['tool_invocations'][0]['status'])->toBe('success');
});
