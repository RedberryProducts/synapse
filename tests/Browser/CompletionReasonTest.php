<?php

use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Tests\Fixtures\ApprovalResumeAgent;
use Redberry\Synapse\Tests\Fixtures\ApprovalSearchTool;
use Redberry\Synapse\Tests\Fixtures\StepSequenceAgent;
use Redberry\Synapse\Tests\Fixtures\StructuredStepSequenceAgent;
use Workbench\App\Agents\SupportAgent;

beforeEach(function () {
    config(['synapse.discovery.paths' => [dirname(__DIR__, 2).'/workbench/app/Agents']]);
    fakeAgent(SupportAgent::class);
});

it('explains exceptional endings without answer text in both themes', function (FinishReason $reason, string $explanation, string $theme) {
    app()->instance(SupportAgent::class, new StepSequenceAgent([
        $reason === FinishReason::Cancelled ? StepResult::stop() : new StepResponse('', [], $reason, new TextUsage(10, 2), new Meta('openai', 'test')),
    ]));
    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $page->click('System')->click($theme)->resize(420, 900);
    $page->type('@composer-input', 'Explain')->click('Send')->assertPresent('@completion-notice')
        ->assertSeeIn('@completion-notice', $explanation)->assertCount('@completion-notice', 1)
        ->assertMissing('@error-card')->assertMissing('@message-assistant-loading');
    expect($page->script("document.querySelector('[data-testid=completion-notice]').getAttribute('role')"))->toBe('status');
    $page->refresh()->assertPresent('@completion-notice')->assertSeeIn('@completion-notice', $explanation)
        ->assertCount('@completion-notice', 1)->assertNoJavaScriptErrors();
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
})->with([
    [FinishReason::Length, 'Output token limit reached.'],
    [FinishReason::ContentFilter, 'Stopped by content filtering.'],
    [FinishReason::Cancelled, 'Generation was cancelled.'],
])->with(['Dark', 'Light']);

it('adds no notice for a normal completion after intermediate steps', function () {
    app()->instance(SupportAgent::class, new StepSequenceAgent([
        new StepResponse('Checking.', [], FinishReason::Continue, new TextUsage(10, 2), new Meta('openai', 'test')),
        new StepResponse('Finished.', [], FinishReason::Stop, new TextUsage(20, 3), new Meta('openai', 'test')),
    ]));
    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $page->type('@composer-input', 'Explain')->click('Send')->assertPresent('@message-meta')
        ->assertSee('Finished.')->assertMissing('@completion-notice');
    $page->refresh()->assertPresent('@message-meta')->assertMissing('@completion-notice');
});

it('keeps structured output beside its completion explanation', function () {
    app()->instance(SupportAgent::class, new StructuredStepSequenceAgent([
        new StepResponse('{"answer":"Partial structured answer"}', [], FinishReason::Length, new TextUsage(10, 2), new Meta('openai', 'test'), structured: ['answer' => 'Partial structured answer']),
    ]));
    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $page->type('@composer-input', 'Explain')->click('Send')->assertPresent('@completion-notice')
        ->assertSeeIn('@structured-card', 'Partial structured answer')->assertSeeIn('@completion-notice', 'Output token limit reached.');
    $page->refresh()->assertPresent('@completion-notice')->assertSeeIn('@structured-card', 'Partial structured answer');
});

it('keeps the error card authoritative for failed terminal reasons', function (FinishReason $reason) {
    app()->instance(SupportAgent::class, new StepSequenceAgent([
        new StepResponse('Partial answer.', [], $reason, new TextUsage(10, 2), new Meta('openai', 'test')),
    ]));
    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $page->type('@composer-input', 'Explain')->click('Send')->assertPresent('@error-card')
        ->assertCount('@error-card', 1)->assertMissing('@completion-notice')->assertSee('Partial answer.');
    $page->refresh()->assertPresent('@error-card')->assertCount('@error-card', 1)->assertMissing('@completion-notice');
})->with([FinishReason::Error, FinishReason::Unknown]);

it('explains historical exceptional reasons without changing their status', function (string $reason, string $explanation) {
    app()->instance(SupportAgent::class, new StepSequenceAgent([
        new StepResponse('Old answer.', [], FinishReason::Stop, new TextUsage, new Meta('openai', 'test')),
    ]));
    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $page->type('@composer-input', 'Explain')->click('Send')->assertPresent('@message-meta');
    $message = SynapseMessage::where('role', 'assistant')->sole();
    $message->update(['meta' => [], 'steps' => [['finish_reason' => $reason]]]);
    $page->refresh()->assertPresent('@completion-notice')->assertSeeIn('@completion-notice', $explanation)->assertMissing('@error-card');
    expect($message->fresh()->status)->toBe('completed');
})->with([
    ['unknown', 'Completion reason unavailable.'],
    ['error', 'SDK reported an error completion.'],
]);

it('shows the final explanation only after an approval continuation', function () {
    app()->instance(SupportAgent::class, (new StepSequenceAgent([
        new StepResponse('Review this.', [new ToolCall('approval', 'ApprovalSearchTool', ['query' => 'hoodie'])], FinishReason::ToolCalls, new TextUsage(10, 2), new Meta('openai', 'test')),
    ]))->withTools([new ApprovalSearchTool]));
    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $page->type('@composer-input', 'Search')->click('Send')->assertPresent('@approval-summary')->assertMissing('@completion-notice');
    $page->refresh()->assertPresent('@approval-summary')->assertMissing('@completion-notice');
    $agent = new ApprovalResumeAgent([new ToolResult('approval', 'ApprovalSearchTool', ['query' => 'hoodie'], 'Result')]);
    $agent->response->finishReason = FinishReason::Length;
    app()->instance(SupportAgent::class, $agent);
    $page->click('Approve')->assertMissing('@approval-summary')->assertPresent('@completion-notice')
        ->assertSeeIn('@completion-notice', 'Output token limit reached.')->assertCount('@completion-notice', 1);
    $page->refresh()->assertPresent('@completion-notice')->assertCount('@completion-notice', 1)->assertNoJavaScriptErrors();
});

it('accepts a saved completion whose terminal marker ends at EOF', function () {
    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $page->script(<<<'JS'
        (() => {
            const originalFetch = window.fetch;
            window.fetch = (url, options) => {
                if (!String(url).includes('/api/chat/')) return originalFetch(url, options);
                return Promise.resolve(new Response('data: {"type":"text-delta","id":"answer","delta":"Saved answer."}\n\ndata: {"type":"data-synapse-end","data":{}}\n\ndata: [DONE]', { headers: { 'Content-Type': 'text/event-stream' } }));
            };
        })()
    JS);
    $page->type('@composer-input', 'Explain')->click('Send')->assertSee('Saved answer.')
        ->assertMissing('[aria-label=Generating]')->assertMissing('@error-card')->assertMissing('@completion-notice')->assertNoJavaScriptErrors();
});
