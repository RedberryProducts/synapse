<?php

namespace Redberry\Synapse\Chat;

use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Tools\ToolNameResolver;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Models\SynapseToolInvocation;
use RuntimeException;

class ApprovalContinuation
{
    /** @var list<array<string, mixed>> */
    protected array $executions;

    /** @var array<string, SynapseToolInvocation> */
    protected array $observations;

    /** @var array<string, true> */
    protected array $remaining;

    public function __construct(public SynapseMessage $message)
    {
        $requests = $message->approval['requests'];
        $this->executions = array_values(array_filter($requests,
            fn (array $request): bool => $message->approval['decisions'][$request['id']] === 'approve'));
        $this->remaining = array_fill_keys(array_column($requests, 'id'), true);
        $this->observations = $message->toolInvocations()->where('status', 'paused')
            ->orderBy('id')->get()->keyBy('tool_call_id')->all();
    }

    public function decisions(): Decisions
    {
        return Decisions::from(array_map(
            fn (string $decision): Decision => $decision === 'approve'
                ? Decision::approve() : Decision::reject('The user declined this action.'),
            $this->message->approval['decisions'],
        ));
    }

    public function invoking(InvokingTool $event): ?SynapseToolInvocation
    {
        if ($this->remaining === [] || $this->executions === []) {
            return null;
        }
        // The SDK executes approved pending calls in request order. Consume
        // that order before execution; identical tools must retain distinct IDs.
        $request = array_shift($this->executions);
        if ($request['tool'] !== ToolNameResolver::resolve($event->tool) || $request['arguments'] !== $event->arguments) {
            throw new RuntimeException('The pending tool changed. Start a new conversation.');
        }

        return $this->observations[$request['id']];
    }

    /** @param Message[] $messages */
    public function captureMessages(array $messages): void
    {
        // Only the result messages after the paused assistant belong to this
        // continuation. Older turns can legitimately reuse model call IDs.
        foreach (array_reverse($messages) as $message) {
            if ($message instanceof AssistantMessage) {
                break;
            }
            if ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $result) {
                    $this->capture($result);
                }
            }
        }
    }

    public function fail(): void
    {
        foreach ($this->observations as $row) {
            if ($row->fresh()?->status === 'paused') {
                $row->forceFill(['status' => 'error', 'error' => 'The approval continuation stopped before this tool ran.', 'finished_at' => now()])->save();
            }
        }
    }

    public function capture(ToolResult $result): void
    {
        if (! isset($this->remaining[$result->id])) {
            return;
        }
        $steps = $this->message->steps;
        $index = array_key_last($steps);
        $results = collect($steps[$index]['tool_results'])->keyBy('id');
        $results[$result->id] = $result->toArray();
        $steps[$index]['tool_results'] = $results->values()->all();
        $this->message->forceFill(['steps' => $steps])->save();
        $this->observations[$result->id]->forceFill([
            'result' => $result->result,
            'status' => $result->denied ? 'denied' : ($result->successful() ? 'success' : 'error'),
            'error' => $result->error(),
            'finished_at' => now(),
        ])->save();
        unset($this->remaining[$result->id]);
    }
}
