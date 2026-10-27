<?php

namespace Redberry\Synapse\Chat;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Responses\AgentResponse;
use Redberry\Synapse\Models\SynapseConversation;
use Redberry\Synapse\Models\SynapseMessage;

class ToolApprovals
{
    /** @return array<string, mixed>|null */
    public function checkpoint(AgentResponse $response, SynapseRecorder $recorder): ?array
    {
        if (! $response->hasPendingApprovals()) {
            return null;
        }

        return [
            'revision' => (string) Str::uuid7(),
            'requests' => $response->pendingApprovals->values()->toArray(),
            ...$recorder->providerAndModel(),
        ];
    }

    /** @param array<string, string> $decisions */
    public function claim(string $messageId, string $agentClass, string $revision, array $decisions): SynapseMessage
    {
        $message = SynapseMessage::query()->findOrFail($messageId);

        return $message->getConnection()->transaction(function () use ($message, $agentClass, $revision, $decisions): SynapseMessage {
            $conversation = SynapseConversation::query()->lockForUpdate()->findOrFail($message->conversation_id);
            abort_unless($conversation->agent_class === $agentClass, 404, 'Approval not found for this agent.');
            $message->refresh();
            abort_unless($message->approval_state === 'pending' && ($message->approval['revision'] ?? null) === $revision,
                409, 'This approval is no longer pending. Reload the conversation.');
            abort_unless($conversation->messages()->latest('id')->value('id') === $message->id,
                409, 'A newer turn has superseded this approval.');

            $expected = array_column($message->approval['requests'], 'id');
            if (array_diff($expected, array_keys($decisions)) !== [] || array_diff(array_keys($decisions), $expected) !== []) {
                throw ValidationException::withMessages(['decisions' => 'Provide one decision for every pending tool call.']);
            }

            $message->forceFill([
                'approval_state' => 'running',
                'approval' => [...$message->approval, 'decisions' => $decisions],
            ])->save();

            return $message;
        });
    }

    public function assertCanSend(?string $conversationId): void
    {
        if ($conversationId === null) {
            return;
        }
        abort_if(SynapseMessage::query()->where('conversation_id', $conversationId)
            ->whereIn('approval_state', ['pending', 'running'])->exists(),
            409, 'Resolve the pending tool approvals or start a new conversation.');
    }
}
