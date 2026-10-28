<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\Citation;
use Laravel\Ai\Streaming\Events\Error;
use Redberry\Synapse\Chat\SynapseRecorder;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Synapse;
use Redberry\Synapse\Tests\Fixtures\StepSequenceAgent;
use Redberry\Synapse\Tests\Fixtures\StreamSequenceAgent;
use Workbench\App\Agents\ExtractorAgent;
use Workbench\App\Agents\SupportAgent;

beforeEach(function () {
    Synapse::auth(fn (): bool => true);
    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
    Http::preventStrayRequests();
});

it('preserves streamed sources through completion and reload', function () {
    fakeAgent(SupportAgent::class, [new TextResponse('Cited answer.', new TextUsage(10, 2), new Meta('openai', 'test', collect([
        new UrlCitation('https://example.com/guide', 'Guide'),
    ])))]);
    $response = sendMessage('workbench.app.agents.support-agent', 'Explain');
    $message = SynapseMessage::where('role', 'assistant')->sole();
    $sources = [['url' => 'https://example.com/guide', 'title' => 'Guide']];
    expect(chatPart($response, 'data-synapse-end')['data']['turn']['message']['sources'] ?? null)->toBe($sources);
    $this->getJson('/synapse/api/conversations/'.$message->conversation_id)->assertJsonPath('messages.1.sources', $sources);
});

it('preserves Unicode citation URLs in streaming and saved projections', function (string $url) {
    fakeAgent(SupportAgent::class);
    app()->instance(SupportAgent::class, new StreamSequenceAgent([
        new Citation('c', 'text', new UrlCitation($url, 'Unicode source'), time()),
        new Error('e', 'failed', 'Provider failed.', false, time()),
    ]));
    $response = sendMessage('workbench.app.agents.support-agent', 'Explain');
    $sources = [['url' => $url, 'title' => 'Unicode source']];
    expect(chatPart($response, 'source-url')['url'] ?? null)->toBe($url)
        ->and(chatPart($response, 'data-synapse-end')['data']['turn']['message']['sources'] ?? null)->toBe($sources);
    $message = SynapseMessage::where('role', 'assistant')->sole();
    $this->getJson('/synapse/api/conversations/'.$message->conversation_id)->assertJsonPath('messages.1.sources', $sources);
})->with([
    'Unicode path' => 'https://example.com/ქართული',
    'internationalized hostname' => 'https://მაგალითი.გე/ქართული',
    'Unicode query and fragment' => 'https://example.com/search?q=ქართული#წყარო',
    'IPv6 with Unicode path' => 'https://[::1]/ქართული',
]);

it('retains citation-only output when the provider fails', function () {
    fakeAgent(SupportAgent::class);
    app()->instance(SupportAgent::class, new StreamSequenceAgent([
        new Citation('c', 'text', new UrlCitation('https://example.com/source', 'Source'), time()),
        new Error('e', 'failed', 'Provider failed.', false, time()),
    ]));
    $response = sendMessage('workbench.app.agents.support-agent', 'Explain');
    $message = SynapseMessage::where('role', 'assistant')->first();
    expect($message?->status)->toBe('failed')
        ->and(chatPart($response, 'data-synapse-end')['data']['turn']['message']['sources'] ?? null)
        ->toBe([['url' => 'https://example.com/source', 'title' => 'Source']]);
});

it('merges exact URLs in first seen order while enriching missing titles', function () {
    fakeAgent(SupportAgent::class, [new TextResponse('Answer.', new TextUsage, new Meta('openai', 'test', collect([
        new UrlCitation('https://example.com/a', '  '),
        new UrlCitation('https://example.com/b', 'Second'),
        new UrlCitation('https://example.com/a', 'First'),
        new UrlCitation('https://example.com/a', 'Replacement'),
        new UrlCitation('https://example.com/a?version=2', 'Different'),
    ])))]);
    $response = sendMessage('workbench.app.agents.support-agent', 'Explain');
    expect(chatPart($response, 'data-synapse-end')['data']['turn']['message']['sources'])->toBe([
        ['url' => 'https://example.com/a', 'title' => 'First'],
        ['url' => 'https://example.com/b', 'title' => 'Second'],
        ['url' => 'https://example.com/a?version=2', 'title' => 'Different'],
    ]);
});

it('omits unsafe citations from streaming and saved projections', function (string $url) {
    fakeAgent(SupportAgent::class, [new TextResponse('Answer.', new TextUsage, new Meta('openai', 'test', collect([new UrlCitation($url, 'Unsafe')])))]);
    $response = sendMessage('workbench.app.agents.support-agent', 'Explain');
    expect(chatPartTypes($response))->not->toContain('source-url')
        ->and(chatPart($response, 'data-synapse-end')['data']['turn']['message']['sources'])->toBe([]);
})->with(['javascript:alert(1)', 'data:text/html,hi', '//example.com', 'https:///example.com', 'https://user:pass@example.com', "https://example.com/\nsecret", 'https://example.com\\@evil.test', 'file:///etc/passwd', 'https://example.com/a b']);

it('keeps citations from every model step without exposing private replay data', function () {
    fakeAgent(SupportAgent::class);
    app()->instance(SupportAgent::class, new StepSequenceAgent([
        new StepResponse('Searching.', [new ToolCall('call', 'SearchProductsTool', ['query' => 'hoodie'])], FinishReason::ToolCalls, new TextUsage(10, 2), new Meta('openai', 'test', collect([new UrlCitation('https://example.com/first', 'First')]))),
        new StepResponse('Done.', [], FinishReason::Stop, new TextUsage(20, 3), new Meta('openai', 'test', collect([new UrlCitation('https://example.com/last', 'Last')]))),
    ]));
    $response = sendMessage('workbench.app.agents.support-agent', 'Explain');
    $message = SynapseMessage::where('role', 'assistant')->sole();
    $completion = chatPart($response, 'data-synapse-end')['data']['turn']['message'];
    expect(array_column($completion['sources'], 'title'))->toBe(['First', 'Last'])
        ->and($message->input_tokens)->toBe(30)
        ->and($message->output_tokens)->toBe(5);
    $steps = $message->steps;
    $steps[0]['replay_blocks'] = [['signature' => 'private-sentinel']];
    $steps[0]['meta']['citations'][0]['private'] = 'private-sentinel';
    $message->update(['steps' => $steps]);
    $reload = $this->getJson('/synapse/api/conversations/'.$message->conversation_id)->assertOk();
    expect($reload->json('messages.1'))->toBe($completion)
        ->and($reload->getContent())->not->toContain('private-sentinel', 'replay_blocks');
});

it('reads previously stored citation metadata without changing historical rows', function () {
    fakeAgent(SupportAgent::class, ['Old answer']);
    sendMessage('workbench.app.agents.support-agent', 'Explain');
    $message = SynapseMessage::where('role', 'assistant')->sole();
    $message->update(['meta' => ['citations' => [['url' => 'https://example.com/old', 'title' => 'Old', 'private' => 'secret']]],
        'steps' => [['meta' => ['citations' => [['url' => 'https://example.com/step', 'title' => 'Step']]]]]]);
    $reload = $this->getJson('/synapse/api/conversations/'.$message->conversation_id)->assertOk();
    expect(array_column($reload->json('messages.1.sources'), 'title'))->toBe(['Old', 'Step'])
        ->and($reload->getContent())->not->toContain('secret')
        ->and($message->fresh()->meta['citations'][0]['private'])->toBe('secret');
});

it('does not infer citations or carry them into a later turn', function () {
    fakeAgent(SupportAgent::class, [new TextResponse('First.', new TextUsage, new Meta('openai', 'test', collect([new UrlCitation('https://example.com', 'Source')]))), 'See https://example.com']);
    sendMessage('workbench.app.agents.support-agent', 'First');
    $message = SynapseMessage::where('role', 'assistant')->sole();
    $response = sendMessage('workbench.app.agents.support-agent', 'Next', $message->conversation_id);
    expect(chatPart($response, 'data-synapse-end')['data']['turn']['message']['sources'])->toBe([]);
});

it('ignores citations from a different invocation', function () {
    $recorder = new SynapseRecorder('test');
    $recorder->begin('parent', 'openai', 'test');
    $recorder->event((new Citation('child', 'text', new UrlCitation('https://example.com/child', 'Child'), time()))->withInvocationId('child'));
    expect($recorder->snapshot()->meta->citations)->toBeEmpty();
});

it('retains gateway citation events before a failed or premature stream end', function (bool $premature) {
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
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Partial answer.']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'citations_delta', 'citation' => ['type' => 'web_search_result_location', 'url' => 'https://example.com/gateway', 'title' => 'Gateway source']]],
    ];
    if (! $premature) {
        $events[] = ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Provider overloaded.']];
    }
    Http::fake(['api.anthropic.com/*' => Http::response(implode('', array_map(fn (array $event): string => 'data: '.json_encode($event)."\n\n", $events)), 200, ['Content-Type' => 'text/event-stream'])]);
    $response = sendMessage('workbench.app.agents.support-agent', 'Explain');
    $completion = chatPart($response, 'data-synapse-end')['data']['turn']['message'];
    expect($completion['status'])->toBe('failed')->and($completion['content'])->toBe('Partial answer.')
        ->and($completion['sources'])->toBe([['url' => 'https://example.com/gateway', 'title' => 'Gateway source']]);
    Http::assertSentCount(1);
})->with([false, true]);

it('preserves completed attempt sources through structured failover', function () {
    fakeAgent(ExtractorAgent::class);
    app()->instance(ExtractorAgent::class, new class extends ExtractorAgent implements HasMiddleware
    {
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

                return new StepResponse($step->provider === 'openai' ? '' : '{"name":"Ada"}', [],
                    $step->provider === 'openai' ? FinishReason::Continue : FinishReason::Stop,
                    new TextUsage(10, 2), new Meta($step->provider, $step->model, collect([new UrlCitation('https://example.com/'.$step->provider, $step->provider)])),
                    structured: $step->provider === 'openai' ? null : ['name' => 'Ada']);
            }];
        }
    });
    $response = sendMessage('workbench.app.agents.extractor-agent', 'Extract');
    $message = chatPart($response, 'data-synapse-end')['data']['turn']['message'];
    expect(array_column($message['sources'], 'title'))->toBe(['openai', 'anthropic'])
        ->and($message['meta']['structured'])->toBe(['name' => 'Ada'])
        ->and($message['usage']['input_tokens'])->toBe(20);
});
