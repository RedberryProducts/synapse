<?php

use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Models\SynapseToolInvocation;
use Redberry\Synapse\Tests\Fixtures\ApprovalResumeAgent;
use Redberry\Synapse\Tests\Fixtures\ApprovalSearchTool;
use Redberry\Synapse\Tests\Fixtures\InterruptedAnswerAgent;
use Redberry\Synapse\Tests\Fixtures\StepSequenceAgent;
use Redberry\Synapse\Tests\Fixtures\StructuredStepSequenceAgent;
use Workbench\App\Agents\SupportAgent;

beforeEach(function () {
    config(['synapse.discovery.paths' => [dirname(__DIR__, 2).'/workbench/app/Agents']]);
    fakeAgent(SupportAgent::class);
});

it('inspects ordered steps without changing chat totals in either theme', function (string $theme) {
    app()->instance(SupportAgent::class, new StepSequenceAgent([
        new StepResponse('', [new ToolCall('lookup-one', 'SearchProductsTool', ['query' => 'hoodie']), new ToolCall('lookup-two', 'SearchProductsTool', ['query' => 'hoodie'])], FinishReason::ToolCalls, new TextUsage(100, 20, 0, 30, 5), new Meta('openai', 'search-model')),
        new StepResponse('Available stock.', [], FinishReason::Stop, new TextUsage(40, 5), new Meta('openai', 'answer-model')),
    ]));
    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $page->click('System')->click($theme)->resize(420, 900);
    $page->type('@composer-input', 'Search')->click('Send')->assertPresent('@step-inspector')
        ->assertCount('@step-inspector', 1)->assertMissing('@step-inspector-content')->assertCount('@tool-card', 2);
    $page->click('button:text-is("Steps (2)")')->assertPresent('@model-step-2')->assertMissing('@model-step-1-content')->assertMissing('@model-step-2-content')->click('Step 1')->assertPresent('@model-step-1-content')
        ->assertSeeIn('@model-step-1-content', 'openai / search-model')->assertSeeIn('@model-step-1', 'tool_calls')
        ->assertSeeIn('@model-step-1', 'Unavailable')->assertSeeIn('@model-step-1-content', 'No text returned.')
        ->assertSeeIn('@model-step-1-content', 'Total: 120')->assertSeeIn('@model-step-1-content', 'Cache read (input subset): 0')
        ->assertSeeIn('@model-step-1-content', 'Cache write (input subset): 30')->assertSeeIn('@model-step-1-content', 'Reasoning (output subset): 5')
        ->assertSeeIn('@model-step-1-content', 'Call ID: lookup-one')->assertSeeIn('@model-step-1-content', 'Call ID: lookup-two')
        ->click('[data-testid=step-tool]:has-text("lookup-one") button:text-is("Arguments and result")')
        ->assertSeeIn('@model-step-1-content', 'Sony WH-1000 (hoodie)')
        ->click('Step 2')->assertPresent('@model-step-2-content')->assertSeeIn('@model-step-2-content', 'Available stock.')
        ->assertSeeIn('@message-meta', 'Total: 165')->assertNoJavaScriptErrors();
    expect(SynapseToolInvocation::count())->toBe(2)->and(SynapseMessage::count())->toBe(2)
        ->and($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->refresh()->assertPresent('@step-inspector')->assertMissing('@step-inspector-content')
        ->click('button:text-is("Steps (2)")')->click('Step 2')->assertPresent('@model-step-2-content')
        ->assertSeeIn('@model-step-2-content', 'Available stock.')->assertSeeIn('@model-step-2-content', 'openai / answer-model');
})->with(['Dark', 'Light']);

it('supports keyboard expansion without invoking the agent again', function () {
    fakeAgent(SupportAgent::class, ['Keyboard answer.']);
    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $page->type('@composer-input', 'Hello')->click('Send')->assertPresent('@step-inspector');
    $page->keys('[data-testid=step-inspector] > div > button', 'Enter')->assertPresent('@step-inspector-content');
    $page->assertMissing('@model-step-1-content')->keys('[aria-label="Step 1"]', 'Enter')->assertPresent('@model-step-1-content');
    $page->keys('[aria-label="Step 1"]', 'Space')->assertMissing('@model-step-1-content');
    $page->keys('[data-testid=step-inspector] > div > button', 'Enter')->assertMissing('@step-inspector-content');
    expect(SynapseMessage::count())->toBe(2);
});

it('identifies an interrupted step without fabricated usage', function () {
    app()->instance(SupportAgent::class, new InterruptedAnswerAgent);
    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $page->type('@composer-input', 'Search')->click('Send')->assertPresent('@error-card')
        ->click('button:text-is("Steps (2)")')->click('Step 2')->assertPresent('@model-step-2-content')
        ->assertSeeIn('@model-step-2', 'incomplete')->assertSeeIn('@model-step-2-content', 'The hoodie stock is')
        ->assertSeeIn('@model-step-2-content', 'Input: Unavailable')->assertSeeIn('@model-step-2-content', 'Total: Unavailable');
    $page->refresh()->assertPresent('@step-inspector')->click('button:text-is("Steps (2)")')->assertSeeIn('@model-step-2', 'incomplete');
});

it('keeps structured output inspectable', function () {
    app()->instance(SupportAgent::class, new StructuredStepSequenceAgent([
        new StepResponse('{"answer":"Structured answer"}', [], FinishReason::Stop, new TextUsage(10, 2), new Meta('openai', 'structured-model'), reasoning: 'Checked the schema.', structured: ['answer' => 'Structured answer']),
    ]));
    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $page->type('@composer-input', 'Extract')->click('Send')->assertPresent('@structured-card')
        ->click('button:text-is("Steps (1)")')->click('Step 1')->assertPresent('@model-step-1-content')
        ->assertSeeIn('@model-step-1-content', '{"answer":"Structured answer"}');
    $page->click('[data-testid=model-step-1] button:has-text("Thinking")')->assertSeeIn('@model-step-1-content', 'Checked the schema.');
});

it('retains one inspector as approval updates its original step', function () {
    app()->instance(SupportAgent::class, (new StepSequenceAgent([
        new StepResponse('Review this.', [new ToolCall('approval', 'ApprovalSearchTool', ['query' => 'hoodie'])], FinishReason::ToolCalls, new TextUsage(10, 2), new Meta('openai', 'test')),
    ]))->withTools([new ApprovalSearchTool]));
    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $page->type('@composer-input', 'Search')->click('Send')->assertPresent('@approval-summary')
        ->click('button:text-is("Steps (1)")')->click('Step 1')->assertPresent('@model-step-1-content')
        ->assertSeeIn('@model-step-1-content', 'paused');
    app()->instance(SupportAgent::class, new ApprovalResumeAgent([new ToolResult('approval', 'ApprovalSearchTool', ['query' => 'hoodie'], 'Approved stock result')]));
    $page->click('Approve')->assertMissing('@approval-summary')->assertPresent('@model-step-2')->assertCount('@step-inspector', 1)
        ->assertSeeIn('@model-step-1-content', 'success')->assertCount('@tool-card', 1);
    $page->refresh()->assertPresent('@step-inspector')->click('button:text-is("Steps (2)")')->click('Step 1')->assertPresent('@model-step-1-content')
        ->assertSeeIn('@model-step-1-content', 'success');
});

it('shows historical uncertainty without inventing model time', function () {
    fakeAgent(SupportAgent::class, ['Saved answer.']);
    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $page->type('@composer-input', 'Hello')->click('Send')->assertPresent('@step-inspector');
    $message = SynapseMessage::where('role', 'assistant')->sole();
    $message->update(['step_observations' => null]);
    $page->refresh()->assertPresent('@step-inspector')->click('button:text-is("Steps (1)")')->assertPresent('@model-step-1')
        ->assertSeeIn('@model-step-1', 'unverified')->assertSeeIn('@model-step-1', 'Unavailable');
    $message->update(['steps' => []]);
    $page->refresh()->assertMissing('@step-inspector')->assertSee('Saved answer.');
});
