<?php

namespace Redberry\Synapse\Chat;

use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Models\SynapseToolInvocation;

/** The client projection shared by thread reload and stream completion. */
class ConversationPresenter
{
    /** @return array<string, mixed> */
    public function turn(SynapseMessage $message): array
    {
        return [
            'message' => $this->message($message),
            'tool_invocations' => $message->toolInvocations()->orderBy('id')->get()->map($this->tool(...))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function message(SynapseMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'status' => $message->status,
            'approval' => $message->approval === null ? null : [
                'state' => $message->approval_state,
                'revision' => $message->approval['revision'],
                'requests' => $message->approval['requests'],
            ],
            'parts' => $this->parts($message),
            'content' => $message->content,
            'attachments' => $this->attachments($message),
            'usage' => $message->usage ?: null,
            'duration_ms' => $message->duration_ms,
            'meta' => $message->meta ?: null,
            'metadata' => $message->metadata ?: null,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function tool(SynapseToolInvocation $invocation): array
    {
        return [
            'id' => $invocation->id,
            'message_id' => $invocation->message_id,
            'tool_call_id' => $invocation->tool_call_id,
            'provider' => $invocation->provider,
            'type' => $invocation->type,
            'name' => $invocation->name,
            'arguments' => $invocation->arguments,
            'result' => $invocation->result,
            'status' => $invocation->status,
            // The provider's own word for it, kept unnormalized.
            'provider_status' => $invocation->provider_status,
            'error' => $invocation->error,
            'duration_ms' => $invocation->duration_ms,
            'started_at' => $invocation->started_at?->toIso8601String(),
            'finished_at' => $invocation->finished_at?->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function parts(SynapseMessage $message): array
    {
        return array_values(array_filter(array_map(fn (array $part): ?array => match ($part['type']) {
            'text', 'reasoning' => ['id' => $part['id'], 'type' => $part['type'], 'text' => $part['text']],
            'tool' => ['id' => $part['id'], 'type' => 'tool', 'tool_invocation_id' => $part['tool_invocation_id']],
            'notice' => ['id' => $part['id'], 'type' => 'notice', 'message' => $part['message']],
            default => null,
        }, $message->parts ?? [])));
    }

    /**
     * A message's attachments, as things the browser can fetch.
     *
     * `path` and `disk` stay on the server: the UI needs to show and download a
     * file, not to know where it lives on the filesystem.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function attachments(SynapseMessage $message): array
    {
        $base = rtrim((string) config('synapse.ui.path', 'synapse'), '/');

        return collect($message->attachments)
            ->values()
            ->map(fn (mixed $attachment, int $index): ?array => is_array($attachment) ? [
                'type' => $attachment['type'] ?? 'stored-document',
                'name' => $attachment['name'] ?? 'attachment',
                'url' => url("{$base}/api/attachments/{$message->id}/{$index}"),
            ] : null)
            ->filter()
            ->values()
            ->all();
    }
}
