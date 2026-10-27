<?php

use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Tests\Fixtures\ApprovalResumeAgent;
use Redberry\Synapse\Tests\Fixtures\ApprovalSearchTool;
use Redberry\Synapse\Tests\Fixtures\StepSequenceAgent;
use Workbench\App\Agents\SupportAgent;

beforeEach(function () {
    config(['synapse.discovery.paths' => [dirname(__DIR__, 2).'/workbench/app/Agents']]);
});

it('resumes a saved approval from the tool card', function (string $action, string $status) {
    app()->instance(SupportAgent::class, (new SupportAgent)->withTools([(new ApprovalSearchTool)->requireApproval('Search the private catalog?')]));
    fakeAgent(SupportAgent::class, [new ToolCall('approval', 'ApprovalSearchTool', ['query' => 'private hoodie'])]);
    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $page->type('@composer-input', 'Find a hoodie')->click('Send')
        ->assertPresent('@tool-approval')
        ->assertSeeIn('@tool-approval', 'Search the private catalog?')
        ->assertSeeIn('@tool-arguments', 'private hoodie');
    $page->refresh()->assertPresent('@tool-approval');
    app()->instance(SupportAgent::class, new ApprovalResumeAgent([
        new ToolResult('approval', 'ApprovalSearchTool', ['query' => 'private hoodie'], $status === 'denied' ? 'The user declined this action.' : 'Found the private hoodie.', denied: $status === 'denied'),
    ]));
    $page->click($action)->assertMissing('@tool-approval')
        ->assertPresent('[data-tool-status='.$status.']')
        ->assertSee('Continued after your decision.');
    expect($page->script("document.querySelectorAll('[data-testid=tool-card]').length"))->toBe(1);
    $page->refresh()->assertPresent('[data-tool-status='.$status.']')
        ->assertSee('Continued after your decision.')
        ->click('[aria-label="ApprovalSearchTool tool call"]')
        ->assertSeeIn('@tool-result', $status === 'denied' ? 'The user declined this action.' : 'Found the private hoodie.')
        ->assertNoJavaScriptErrors();
    expect(SynapseMessage::where('role', 'assistant')->count())->toBe(1);
})->with([['Approve', 'success'], ['Deny', 'denied']]);

it('waits for all tool decisions before continuing a batch', function () {
    fakeAgent(SupportAgent::class);
    app()->instance(SupportAgent::class, (new StepSequenceAgent([
        new StepResponse('', [new ToolCall('one', 'ApprovalSearchTool', ['query' => 'first']), new ToolCall('two', 'ApprovalSearchTool', ['query' => 'second'])], FinishReason::ToolCalls, new TextUsage(10, 2), new Meta('openai', 'test')),
    ]))->withTools([new ApprovalSearchTool]));
    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $page->type('@composer-input', 'Two searches')->click('Send')->assertPresent('@approval-summary');
    $page->click(':nth-match([data-testid=tool-approval], 1) button:first-child');
    expect($page->script("document.querySelector('[data-testid=approval-summary] button').disabled"))->toBeTrue();
    $page->click(':nth-match([data-testid=tool-approval], 2) button:last-child');
    app()->instance(SupportAgent::class, new ApprovalResumeAgent([
        new ToolResult('one', 'ApprovalSearchTool', ['query' => 'first'], 'First search result.'),
        new ToolResult('two', 'ApprovalSearchTool', ['query' => 'second'], 'The user declined this action.', denied: true),
    ]));
    $page->click('Continue with decisions')->assertMissing('@approval-summary')
        ->assertPresent('[data-tool-status=success]')->assertPresent('[data-tool-status=denied]')
        ->assertSee('Continued after your decision.')->assertNoJavaScriptErrors();
});
