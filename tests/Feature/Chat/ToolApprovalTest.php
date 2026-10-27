<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\McpTool;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Models\SynapseToolInvocation;
use Redberry\Synapse\Synapse;
use Redberry\Synapse\Tests\Fixtures\CountedApprovalTool;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Workbench\App\Agents\ExtractorAgent;
use Workbench\App\Agents\SupportAgent;
use Workbench\App\Agents\WeatherAgent;

beforeEach(function () {
    Synapse::auth(fn (): bool => true);
    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
    Http::preventStrayRequests();
    config(['ai.providers.anthropic.key' => 'fake-key']);
    $this->tool = new CountedApprovalTool;
});

function approvalAgent(string $class, CountedApprovalTool $tool): string
{
    $agent = match ($class) {
        SupportAgent::class => new class extends SupportAgent
        {
            public function provider(): string
            {
                return 'anthropic';
            }

            public function model(): string
            {
                return 'claude-test';
            }
        },
        WeatherAgent::class => new class extends WeatherAgent
        {
            public function provider(): string
            {
                return 'anthropic';
            }

            public function model(): string
            {
                return 'claude-test';
            }
        },
        default => new class extends ExtractorAgent
        {
            public function provider(): string
            {
                return 'anthropic';
            }

            public function model(): string
            {
                return 'claude-test';
            }
        },
    };
    $agent->withTools([$tool]);
    app()->instance($class, $agent);

    return match ($class) {
        SupportAgent::class => 'workbench.app.agents.support-agent',
        WeatherAgent::class => 'workbench.app.agents.weather-agent',
        default => 'workbench.app.agents.extractor-agent',
    };
}

function approvalHttpStep(array $content, bool $streaming, string $stop = 'end_turn'): array|string
{
    if (! $streaming) {
        return ['id' => 'msg_approval', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-test',
            'content' => $content, 'stop_reason' => $stop, 'usage' => ['input_tokens' => 20, 'output_tokens' => 5]];
    }
    $events = [['type' => 'message_start', 'message' => ['id' => 'msg_approval', 'model' => 'claude-test', 'usage' => ['input_tokens' => 20, 'output_tokens' => 0]]]];
    foreach ($content as $index => $block) {
        $events[] = ['type' => 'content_block_start', 'index' => $index, 'content_block' => [...$block, ...($block['type'] === 'text' ? ['text' => ''] : ['input' => new stdClass])]];
        $events[] = ['type' => 'content_block_delta', 'index' => $index, 'delta' => $block['type'] === 'text'
            ? ['type' => 'text_delta', 'text' => $block['text']]
            : ['type' => 'input_json_delta', 'partial_json' => json_encode($block['input'])]];
        $events[] = ['type' => 'content_block_stop', 'index' => $index];
    }
    $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $stop], 'usage' => ['output_tokens' => 5]];
    $events[] = ['type' => 'message_stop'];

    return implode('', array_map(fn (array $event): string => 'event: '.$event['type']."\ndata: ".json_encode($event)."\n\n", $events));
}

function approvalCall(string $id = 'call_approval', string $query = 'private'): array
{
    return ['type' => 'tool_use', 'id' => $id, 'name' => 'CountedApprovalTool', 'input' => ['query' => $query]];
}

function resolveApproval(string $slug, SynapseMessage $message, array $decisions = ['call_approval' => 'approve']): TestResponse
{
    $response = test()->postJson("/synapse/api/chat/{$slug}/messages/{$message->id}/approvals", [
        'revision' => $message->approval['revision'], 'decisions' => $decisions,
    ]);
    if ($response->baseResponse instanceof StreamedResponse) {
        $response->streamedContent();
    }

    return $response;
}

it('resumes an approved execution in the original turn', function (string $class) {
    $slug = approvalAgent($class, $this->tool);
    $streaming = $class !== ExtractorAgent::class;
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(approvalHttpStep([approvalCall()], $streaming, 'tool_use'))
        ->push(approvalHttpStep([['type' => 'text', 'text' => $streaming ? 'Finished.' : '{"name":"Ada"}']], $streaming))]);
    sendMessage($slug, 'Run the tool');
    $paused = SynapseMessage::where('role', 'assistant')->sole();
    $toolId = SynapseToolInvocation::sole()->id;
    expect($paused->approval_state)->toBe('pending')->and($this->tool->executions)->toBe(0);
    $response = resolveApproval($slug, $paused)->assertOk();
    $saved = $paused->fresh();

    expect($this->tool->executions)->toBe(1)
        ->and($saved->status)->toBe('completed')
        ->and($saved->approval_state)->toBeNull()
        ->and(SynapseMessage::where('role', 'assistant')->count())->toBe(1)
        ->and(SynapseMessage::where('role', 'user')->count())->toBe(1)
        ->and(SynapseToolInvocation::sole()->id)->toBe($toolId)
        ->and(SynapseToolInvocation::sole()->status)->toBe('success')
        ->and(SynapseToolInvocation::sole()->duration_ms)->not->toBeNull()
        ->and($saved->steps[0]['tool_results'][0]['result'])->toBe('Approved result 1')
        ->and($saved->input_tokens)->toBe(40)
        ->and($saved->output_tokens)->toBe(10)
        ->and(chatPart($response, 'data-synapse-end')['data']['assistantMessageId'])->toBe($paused->id);
    $reload = $this->getJson('/synapse/api/conversations/'.$saved->conversation_id)->assertOk();
    expect($reload->json('messages.1'))->toBe(chatPart($response, 'data-synapse-end')['data']['turn']['message']);
})->with([SupportAgent::class, WeatherAgent::class, ExtractorAgent::class]);

it('continues after denying a call without executing its tool', function () {
    $slug = approvalAgent(ExtractorAgent::class, $this->tool);
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(approvalHttpStep([approvalCall()], false, 'tool_use'))
        ->push(approvalHttpStep([['type' => 'text', 'text' => '{"name":"Declined"}']], false))]);
    sendMessage($slug, 'Run the tool');
    $paused = SynapseMessage::where('role', 'assistant')->sole();
    resolveApproval($slug, $paused, ['call_approval' => 'deny'])->assertOk();

    expect($this->tool->executions)->toBe(0)
        ->and(SynapseToolInvocation::sole()->status)->toBe('denied')
        ->and($paused->fresh()->steps[0]['tool_results'][0]['denied'])->toBeTrue()
        ->and($paused->fresh()->status)->toBe('completed');
    Http::assertSentCount(2);
});

it('rejects replaying an already submitted decision', function () {
    $slug = approvalAgent(ExtractorAgent::class, $this->tool);
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(approvalHttpStep([approvalCall()], false, 'tool_use'))
        ->push(approvalHttpStep([['type' => 'text', 'text' => '{"name":"Ada"}']], false))]);
    sendMessage($slug, 'Run the tool');
    $paused = SynapseMessage::where('role', 'assistant')->sole();
    resolveApproval($slug, $paused)->assertOk();
    resolveApproval($slug, $paused)->assertConflict();
    expect($this->tool->executions)->toBe(1);
});

it('keeps an executed result when the next provider request fails', function (string $class) {
    $slug = approvalAgent($class, $this->tool);
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(approvalHttpStep([approvalCall()], $class !== ExtractorAgent::class, 'tool_use'))
        ->push(['error' => ['type' => 'invalid_request_error', 'message' => 'Continuation failed']], 400)]);
    sendMessage($slug, 'Run the tool');
    $paused = SynapseMessage::where('role', 'assistant')->sole();
    resolveApproval($slug, $paused)->assertOk();

    expect($paused->fresh()->status)->toBe('failed')
        ->and($paused->fresh()->steps[0]['tool_results'][0]['result'])->toBe('Approved result 1')
        ->and(SynapseToolInvocation::sole()->status)->toBe('success');
    resolveApproval($slug, $paused)->assertConflict();
    expect($this->tool->executions)->toBe(1);
})->with([SupportAgent::class, ExtractorAgent::class]);

it('requires a complete batch of decisions before any execution', function (array $decisions) {
    $slug = approvalAgent(ExtractorAgent::class, $this->tool);
    Http::fake(['api.anthropic.com/*' => Http::response(approvalHttpStep([approvalCall('first'), approvalCall('second')], false, 'tool_use'))]);
    sendMessage($slug, 'Run two tools');
    $paused = SynapseMessage::where('role', 'assistant')->sole();
    resolveApproval($slug, $paused, $decisions)->assertUnprocessable();
    expect($this->tool->executions)->toBe(0)->and($paused->fresh()->approval_state)->toBe('pending');
    Http::assertSentCount(1);
})->with([
    'missing decision' => [['first' => 'approve']],
    'unknown call' => [['first' => 'approve', 'second' => 'deny', 'other' => 'approve']],
    'wildcard' => [['*' => 'approve']],
    'invalid action' => [['first' => 'edit', 'second' => 'approve']],
]);

it('keeps identical approved calls separate from a denied call', function () {
    $slug = approvalAgent(ExtractorAgent::class, $this->tool);
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(approvalHttpStep([approvalCall('first'), approvalCall('second'), approvalCall('third')], false, 'tool_use'))
        ->push(approvalHttpStep([['type' => 'text', 'text' => '{"name":"Done"}']], false))]);
    sendMessage($slug, 'Run three tools');
    $paused = SynapseMessage::where('role', 'assistant')->sole();
    $ids = SynapseToolInvocation::orderBy('id')->pluck('id')->all();
    resolveApproval($slug, $paused, ['third' => 'approve', 'second' => 'deny', 'first' => 'approve'])->assertOk();
    $tools = SynapseToolInvocation::orderBy('id')->get();
    expect($this->tool->executions)->toBe(2)
        ->and($tools->pluck('id')->all())->toBe($ids)
        ->and($tools->pluck('status')->all())->toBe(['success', 'denied', 'success'])
        ->and($tools->pluck('result')->all())->toBe(['Approved result 1', 'The user declined this action.', 'Approved result 2']);
});

it('restores another approval round on the same assistant turn', function () {
    $slug = approvalAgent(SupportAgent::class, $this->tool);
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(approvalHttpStep([approvalCall()], true, 'tool_use'))
        ->push(approvalHttpStep([approvalCall('next_call')], true, 'tool_use'))
        ->push(approvalHttpStep([['type' => 'text', 'text' => 'Finished twice.']], true))]);
    sendMessage($slug, 'Run tools');
    $first = SynapseMessage::where('role', 'assistant')->sole();
    resolveApproval($slug, $first)->assertOk();
    $second = $first->fresh();
    expect($second->approval['revision'])->not->toBe($first->approval['revision']);
    resolveApproval($slug, $first)->assertConflict();
    resolveApproval($slug, $second, ['next_call' => 'approve'])->assertOk();
    expect(SynapseMessage::where('role', 'error')->pluck('content')->all())->toBe([])
        ->and(SynapseMessage::where('role', 'assistant')->count())->toBe(1)
        ->and(SynapseToolInvocation::orderBy('id')->pluck('result')->all())->toBe(['Approved result 1', 'Approved result 2'])
        ->and($second->fresh()->input_tokens)->toBe(60);
});

it('lets conditional tools run without prompting when approval is unnecessary', function () {
    $slug = approvalAgent(ExtractorAgent::class, $this->tool);
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(approvalHttpStep([approvalCall(query: 'public')], false, 'tool_use'))
        ->push(approvalHttpStep([['type' => 'text', 'text' => '{"name":"Public"}']], false))]);
    sendMessage($slug, 'Run public search');
    expect($this->tool->executions)->toBe(1)
        ->and(SynapseMessage::where('role', 'assistant')->sole()->approval)->toBeNull();
});

it('locks the conversation while a decision request owns the continuation', function () {
    $slug = approvalAgent(ExtractorAgent::class, $this->tool);
    Http::fake(['api.anthropic.com/*' => Http::response(approvalHttpStep([approvalCall()], false, 'tool_use'))]);
    sendMessage($slug, 'Run the tool');
    $paused = SynapseMessage::where('role', 'assistant')->sole();
    // Claim the response without consuming it: no tool has started yet.
    $body = ['revision' => $paused->approval['revision'], 'decisions' => ['call_approval' => 'approve']];
    $this->postJson("/synapse/api/chat/{$slug}/messages/{$paused->id}/approvals", $body)->assertOk();
    $this->postJson("/synapse/api/chat/{$slug}/messages/{$paused->id}/approvals", $body)->assertConflict();
    $this->postJson("/synapse/api/chat/{$slug}/send", ['message' => 'Race', 'conversation_id' => $paused->conversation_id])->assertConflict();
    $this->deleteJson('/synapse/api/conversations/'.$paused->conversation_id)->assertConflict();
    expect($this->tool->executions)->toBe(0)->and($paused->fresh()->approval_state)->toBe('running');
});

it('rejects approving a different agents conversation', function () {
    $slug = approvalAgent(ExtractorAgent::class, $this->tool);
    Http::fake(['api.anthropic.com/*' => Http::response(approvalHttpStep([approvalCall()], false, 'tool_use'))]);
    sendMessage($slug, 'Run the tool');
    resolveApproval('workbench.app.agents.support-agent', SynapseMessage::where('role', 'assistant')->sole())->assertNotFound();
    expect($this->tool->executions)->toBe(0);
});

it('applies the dashboard gate to approval submissions', function () {
    Synapse::auth(fn (): bool => false);
    $this->postJson('/synapse/api/chat/workbench.app.agents.support-agent/messages/unknown/approvals', [])->assertForbidden();
});

it('keeps approval replay state private after a reload', function () {
    $slug = approvalAgent(ExtractorAgent::class, $this->tool);
    $this->tool->requireApproval('Confirm this private search.');
    Http::fake(['api.anthropic.com/*' => Http::response(approvalHttpStep([approvalCall()], false, 'tool_use'))]);
    sendMessage($slug, 'Run the tool');
    $paused = SynapseMessage::where('role', 'assistant')->sole();
    $response = $this->getJson('/synapse/api/conversations/'.$paused->conversation_id)->assertOk();
    expect($response->json('messages.1.approval.requests.0.reason'))->toBe('Confirm this private search.')
        ->and($response->json('messages.1.approval'))->not->toHaveKeys(['provider', 'model', 'decisions'])
        ->and($response->getContent())->not->toContain('replay_blocks', '"steps"');
});

it('preserves the intended history boundary when resuming', function (string $class, bool $remembers) {
    $slug = approvalAgent($class, $this->tool);
    $streaming = $class !== ExtractorAgent::class;
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(approvalHttpStep([['type' => 'text', 'text' => $streaming ? 'Earlier answer.' : '{"name":"Earlier"}']], $streaming))
        ->push(approvalHttpStep([approvalCall()], $streaming, 'tool_use'))
        ->push(approvalHttpStep([['type' => 'text', 'text' => $streaming ? 'Finished.' : '{"name":"Done"}']], $streaming))]);
    $conversation = chatConversationId(sendMessage($slug, 'Earlier private question'));
    sendMessage($slug, 'Current question', $conversation);
    $paused = SynapseMessage::where('role', 'assistant')->latest('id')->first();
    resolveApproval($slug, $paused)->assertOk();
    $body = Http::recorded()->last()[0]->body();
    expect(str_contains($body, 'Earlier private question'))->toBe($remembers)
        ->and($body)->toContain('Current question', 'Approved result 1');
})->with([
    [SupportAgent::class, true], [WeatherAgent::class, false], [ExtractorAgent::class, false],
]);

it('requires approval before invoking an AgentTool child', function () {
    $slug = approvalAgent(ExtractorAgent::class, $this->tool);
    $child = new WeatherAgent;
    WeatherAgent::fake(['Child result.']);
    app(ExtractorAgent::class)->withTools([(new AgentTool($child))->requireApproval('Delegate this task?')]);
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(approvalHttpStep([['type' => 'tool_use', 'id' => 'child', 'name' => 'WeatherAgent', 'input' => ['task' => 'Check weather']]], false, 'tool_use'))
        ->push(approvalHttpStep([['type' => 'text', 'text' => '{"name":"Child finished"}']], false))]);
    sendMessage($slug, 'Delegate the task');
    WeatherAgent::assertNeverPrompted();
    resolveApproval($slug, SynapseMessage::where('role', 'assistant')->sole(), ['child' => 'approve'])->assertOk();
    WeatherAgent::assertPrompted('Check weather');
    expect(SynapseToolInvocation::sole()->result)->toBe('Child result.');
});

it('resumes an SDK MCP client wrapper only after approval', function () {
    $slug = approvalAgent(ExtractorAgent::class, $this->tool);
    $remote = new class
    {
        public string $name = 'inventory';

        public string $description = 'Read inventory';

        public array $inputSchema = [];

        public array $annotations = [];

        public int $calls = 0;

        public function call(array $arguments): object
        {
            $this->calls++;

            return (object) ['structuredContent' => ['stock' => 12], 'isError' => false];
        }
    };
    app(ExtractorAgent::class)->withTools([(new McpTool($remote))->requireApproval('Read remote inventory?')]);
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(approvalHttpStep([['type' => 'tool_use', 'id' => 'remote', 'name' => 'mcp_tools_inventory', 'input' => []]], false, 'tool_use'))
        ->push(approvalHttpStep([['type' => 'text', 'text' => '{"name":"Inventory"}']], false))]);
    sendMessage($slug, 'Read inventory');
    expect($remote->calls)->toBe(0);
    resolveApproval($slug, SynapseMessage::where('role', 'assistant')->sole(), ['remote' => 'approve'])->assertOk();
    expect($remote->calls)->toBe(1)->and(SynapseToolInvocation::sole()->result)->toBe('{"stock":12}');
});

it('pins the saved provider and model during approval continuation', function () {
    $slug = approvalAgent(ExtractorAgent::class, $this->tool);
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(approvalHttpStep([approvalCall()], false, 'tool_use'))
        ->push(approvalHttpStep([['type' => 'text', 'text' => '{"name":"Done"}']], false))]);
    $this->postJson("/synapse/api/chat/{$slug}/send", ['message' => 'Run the tool', 'model' => 'selected-model'])->streamedContent();
    $paused = SynapseMessage::where('role', 'assistant')->sole();
    // Changing the agent default between requests must not change the resume.
    app()->instance(ExtractorAgent::class, (new ExtractorAgent)->withTools([$this->tool]));
    resolveApproval($slug, $paused)->assertOk();
    expect(Http::recorded()->last()[0]['model'])->toBe('selected-model')
        ->and($paused->fresh()->status)->toBe('completed');
});

it('checkpoints results before application continuation middleware can fail', function () {
    $slug = approvalAgent(ExtractorAgent::class, $this->tool);
    Http::fake(['api.anthropic.com/*' => Http::response(approvalHttpStep([approvalCall()], false, 'tool_use'))]);
    sendMessage($slug, 'Run the tool');
    $paused = SynapseMessage::where('role', 'assistant')->sole();
    app()->instance(ExtractorAgent::class, (new class extends ExtractorAgent implements HasMiddleware
    {
        public function middleware(): array
        {
            return [fn (PendingStep $step) => throw new RuntimeException('Application middleware failed')];
        }
    })->withTools([$this->tool]));
    resolveApproval($slug, $paused)->assertOk();

    expect($paused->fresh()->steps[0]['tool_results'][0]['result'])->toBe('Approved result 1')
        ->and(SynapseToolInvocation::sole()->status)->toBe('success')
        ->and(SynapseMessage::where('role', 'error')->sole()->content)->toBe('Application middleware failed');
    Http::assertSentCount(1);
});
