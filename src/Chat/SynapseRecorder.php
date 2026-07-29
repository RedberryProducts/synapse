<?php

namespace Redberry\Synapse\Chat;

use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Tools\ToolNameResolver;
use Redberry\Synapse\Models\SynapseToolInvocation;

/**
 * Turns the SDK's tool events into `synapse_tool_invocations` rows.
 *
 * Two SDK facts shape everything here:
 *
 * 1. `ToolInvoked` fires **only on success**. `InvokesTools::executeTool()` runs
 *    the handler inside `try/finally` with no `catch`, so a throwing tool leaves
 *    its row `pending` — the invocation-level catch-all sweeps those to `error`.
 * 2. The id correlating the two events is not reliable under nesting (see
 *    `resolveRow()`).
 *
 * The recorder is bound per invocation rather than globally: it needs the
 * conversation the run belongs to, and a listener registered for the lifetime of
 * one request would otherwise have to guess.
 */
class SynapseRecorder
{
    public function __construct(protected string $conversationId) {}

    /**
     * A tool is about to run. The pending row is what gives the UI an in-flight
     * card for free — the developer sees the call the moment it happens rather
     * than after it returns.
     */
    public function toolInvoking(InvokingTool $event): void
    {
        SynapseToolInvocation::query()->create([
            'conversation_id' => $this->conversationId,
            'invocation_id' => $event->invocationId,
            'tool_invocation_id' => $event->toolInvocationId,
            'type' => 'tool',
            'name' => ToolNameResolver::resolve($event->tool),
            'arguments' => $event->arguments,
            'status' => 'pending',
            'started_at' => now(),
        ]);
    }

    /**
     * A tool returned. Nothing else reports success, so this is the only place
     * a row leaves `pending` on the happy path.
     */
    public function toolInvoked(ToolInvoked $event): void
    {
        $row = $this->resolveRow($event);

        if ($row === null) {
            return;
        }

        $finishedAt = now();

        $row->forceFill([
            'status' => 'success',
            'result' => $event->result,
            'finished_at' => $finishedAt,
            'duration_ms' => $row->started_at?->diffInMilliseconds($finishedAt),
        ])->save();
    }

    /**
     * Find the pending row this result belongs to.
     *
     * The obvious lookup — by `tool_invocation_id` — is not always right. The
     * SDK holds the current id in a single property on the *provider*, and
     * `AiManager` caches provider instances by name. A sub-agent tool therefore
     * runs a nested invocation through the same provider and overwrites the id
     * before the outer tool's `ToolInvoked` fires, so the outer event carries
     * the inner tool's id.
     *
     * Falling back to the newest pending row for the same run and tool name
     * costs nothing in the common case and keeps nested calls landing on the
     * right card. Note this cannot be reproduced under `Agent::fake()`, which
     * clones the provider per invocation and so isolates the property.
     */
    protected function resolveRow(ToolInvoked $event): ?SynapseToolInvocation
    {
        $pending = SynapseToolInvocation::query()
            ->where('invocation_id', $event->invocationId)
            ->where('status', 'pending');

        $exact = (clone $pending)
            ->where('tool_invocation_id', $event->toolInvocationId)
            ->first();

        return $exact ?? $pending
            ->where('name', ToolNameResolver::resolve($event->tool))
            ->orderByDesc('started_at')
            ->first();
    }

    /**
     * Attach every row from this run to the assistant turn it produced.
     *
     * Rows that never get one — a run that failed before any message landed —
     * still render, ordered by `started_at`.
     */
    public function linkToMessage(string $invocationId, string $messageId): void
    {
        SynapseToolInvocation::query()
            ->where('invocation_id', $invocationId)
            ->whereNull('message_id')
            ->update(['message_id' => $messageId]);
    }
}
