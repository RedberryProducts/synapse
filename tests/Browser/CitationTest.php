<?php

use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\Citation;
use Laravel\Ai\Streaming\Events\Error;
use Redberry\Synapse\Tests\Fixtures\ApprovalResumeAgent;
use Redberry\Synapse\Tests\Fixtures\ApprovalSearchTool;
use Redberry\Synapse\Tests\Fixtures\StepSequenceAgent;
use Redberry\Synapse\Tests\Fixtures\StreamSequenceAgent;
use Workbench\App\Agents\ExtractorAgent;
use Workbench\App\Agents\SupportAgent;

beforeEach(function () {
    config(['synapse.discovery.paths' => [dirname(__DIR__, 2).'/workbench/app/Agents']]);
});

it('renders safe sources in both themes with reload parity', function (string $theme) {
    fakeAgent(SupportAgent::class, [new TextResponse('A cited answer.', new TextUsage(10, 2), new Meta('openai', 'test', collect([
        new UrlCitation('https://example.com/guide'),
        new UrlCitation('https://example.com/guide', '<b>Source title</b>'),
        new UrlCitation('https://fallback.example/path'),
        new UrlCitation('javascript:alert(1)', 'Unsafe source'),
    ])))]);
    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $page->click('System')->click($theme)->resize(420, 900);
    $page->type('@composer-input', 'Explain')->click('Send')->assertPresent('@sources')
        ->assertSeeIn('@sources', '<b>Source title</b>')->assertSeeIn('@sources', 'fallback.example')
        ->assertCount('[data-testid=sources] a', 2)->assertMissing('[data-testid=sources] b');
    expect($page->script("document.querySelector('[data-testid=sources] a').getAttribute('href')"))->toBe('https://example.com/guide')
        ->and($page->script("document.querySelector('[data-testid=sources] a').getAttribute('rel')"))->toBe('noopener noreferrer')
        ->and($page->script("document.querySelector('[data-testid=sources] a').getAttribute('target')"))->toBe('_blank');
    $page->script("document.querySelector('[data-testid=sources] a').focus()");
    expect($page->script("document.activeElement === document.querySelector('[data-testid=sources] a')"))->toBeTrue();
    $page->refresh()->assertPresent('@sources')->assertCount('[data-testid=sources] a', 2)
        ->assertSeeIn('@sources', '<b>Source title</b>')->assertNoJavaScriptErrors();
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
})->with(['Dark', 'Light']);

it('renders Unicode citation links after refresh', function (string $url) {
    fakeAgent(SupportAgent::class, [new TextResponse('A cited answer.', new TextUsage, new Meta('openai', 'test', collect([
        new UrlCitation($url, 'ქართული წყარო'),
    ])))]);
    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $page->type('@composer-input', 'Explain')->click('Send')->assertPresent('@sources')
        ->assertSeeIn('@sources', 'ქართული წყარო');
    expect($page->script("document.querySelector('[data-testid=sources] a').getAttribute('href')"))->toBe($url);
    $page->refresh()->assertPresent('@sources')->assertSeeIn('@sources', 'ქართული წყარო')->assertNoJavaScriptErrors();
    expect($page->script("document.querySelector('[data-testid=sources] a').getAttribute('href')"))->toBe($url);
})->with(['https://example.com/ქართული', 'https://მაგალითი.გე/ქართული']);

it('keeps sources on a citation-only failed turn after refresh', function () {
    fakeAgent(SupportAgent::class);
    app()->instance(SupportAgent::class, new StreamSequenceAgent([
        new Citation('c', 'text', new UrlCitation('https://example.com/partial', 'Partial source'), time()),
        new Error('e', 'failed', 'Citation run failed.', false, time()),
    ]));
    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $page->type('@composer-input', 'Explain')->click('Send')->assertPresent('@sources')
        ->assertSeeIn('@sources', 'Partial source')->assertSee('Citation run failed.');
    $page->refresh()->assertPresent('@sources')->assertSeeIn('@sources', 'Partial source')->assertNoJavaScriptErrors();
});

it('keeps later answers after intervening events when citations arrive first', function (array $event, string $selector, array $answer) {
    fakeAgent(SupportAgent::class);
    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $parts = json_encode([
        ['type' => 'source-url', 'url' => 'https://example.com/source', 'title' => 'Early source'],
        $event,
        $answer,
    ]);
    $page->script(<<<JS
        (() => {
            const originalFetch = window.fetch;
            window.fetch = (url, options) => {
                if (!String(url).includes('/api/chat/')) return originalFetch(url, options);
                return Promise.resolve(new Response(new ReadableStream({
                    start(controller) {
                        window.citationStream = controller;
                        const body = {$parts}.map(part => 'data: ' + JSON.stringify(part) + '\\n\\n').join('');
                        controller.enqueue(new TextEncoder().encode(body));
                    }
                }), { headers: { 'Content-Type': 'text/event-stream' } }));
            };
        })()
    JS);
    $page->type('@composer-input', 'Explain')->click('Send')->assertPresent('@sources')
        ->assertSeeIn('@message-assistant', 'Answer after the event.')->assertSeeIn('@sources', 'Early source');
    $order = "Boolean(document.querySelector('{$selector}').compareDocumentPosition(document.querySelector('[data-testid=message-assistant]')) & Node.DOCUMENT_POSITION_FOLLOWING)";
    expect($page->script($order))->toBeTrue();
    $page->assertCount('@message-assistant', 1)->assertCount('@sources', 1)->assertMissing('@message-assistant-loading');

    // End without a saved completion so server replay cannot conceal incorrect live ordering.
    $page->script('window.citationStream.close()');
    $page->assertPresent('@error-card')->assertSeeIn('@error-card', 'The stream ended before the response was saved.')
        ->assertSeeIn('@message-assistant', 'Answer after the event.')->assertSeeIn('@sources', 'Early source');
    expect($page->script($order))->toBeTrue();
    $page->assertCount('@message-assistant', 1)->assertCount('@sources', 1)->assertNoJavaScriptErrors();
})->with([
    'custom tool' => [['type' => 'tool-input-available', 'toolCallId' => 'lookup', 'toolName' => 'Lookup', 'input' => []], '[data-testid=tool-card]'],
    'provider tool' => [['type' => 'data-provider-tool', 'data' => ['item_id' => 'lookup', 'type' => 'web_search', 'status' => 'completed', 'data' => []]], '[data-testid=tool-card]'],
    'notice' => [['type' => 'data-synapse-notice', 'data' => ['message' => 'Retrying.']], '[data-testid=chat-notice]'],
])->with([
    'text answer' => [['type' => 'text-delta', 'id' => 'answer', 'delta' => 'Answer after the event.']],
    'structured answer' => [['type' => 'data-structured-output', 'data' => ['answer' => 'Answer after the event.']]],
]);

it('does not invent a sources section from answer links', function () {
    fakeAgent(SupportAgent::class, ['See [Example](https://example.com).']);
    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $page->type('@composer-input', 'Explain')->click('Send')->assertSee('Example')->assertMissing('@sources');
    $page->refresh()->assertSee('Example')->assertMissing('@sources');
});

it('shows sources for structured answers', function () {
    fakeAgent(ExtractorAgent::class, [new StructuredTextResponse(['name' => 'Ada'], '{"name":"Ada"}', new TextUsage, new Meta('openai', 'test', collect([
        new UrlCitation('https://example.com/contact', 'Contact source'),
    ])))]);
    $page = visit('/synapse/playground/workbench.app.agents.extractor-agent');
    $page->type('@composer-input', 'Extract')->click('Send')->assertPresent('@sources')->assertSeeIn('@sources', 'Contact source')->assertSee('Ada');
    $page->refresh()->assertPresent('@sources')->assertSeeIn('@sources', 'Contact source');
});

it('keeps one source list across an approval continuation', function () {
    fakeAgent(SupportAgent::class);
    app()->instance(SupportAgent::class, (new StepSequenceAgent([
        new StepResponse('Review this.', [new ToolCall('approval', 'ApprovalSearchTool', ['query' => 'private'])], FinishReason::ToolCalls, new TextUsage(10, 2), new Meta('openai', 'test', collect([
            new UrlCitation('https://example.com/before', 'Before approval'),
        ]))),
    ]))->withTools([new ApprovalSearchTool]));
    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $page->type('@composer-input', 'Search')->click('Send')->assertPresent('@approval-summary')->assertSeeIn('@sources', 'Before approval');
    $page->refresh()->assertPresent('@approval-summary');
    $agent = new ApprovalResumeAgent([new ToolResult('approval', 'ApprovalSearchTool', ['query' => 'private'], 'Result')]);
    $agent->events[] = new Citation('after', 'answer', new UrlCitation('https://example.com/after', 'After approval'), time());
    app()->instance(SupportAgent::class, $agent);
    $page->click('Approve')->assertMissing('@approval-summary')->assertCount('@sources', 1)
        ->assertSeeIn('@sources', 'Before approval')->assertSeeIn('@sources', 'After approval');
    $page->refresh()->assertPresent('@sources')->assertCount('@sources', 1)->assertSeeIn('@sources', 'After approval')->assertNoJavaScriptErrors();
});
