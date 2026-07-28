<?php

namespace Redberry\Synapse\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Redberry\Synapse\Discovery\AgentSlug;
use Redberry\Synapse\Models\SynapseConversation;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Repositories\ConversationRepository;

class ConversationsController
{
    /**
     * Replay one conversation: every turn, in order, with its token counts.
     */
    public function show(string $id): JsonResponse
    {
        $conversation = SynapseConversation::query()->find($id);

        abort_if($conversation === null, 404, 'Conversation not found.');

        // uuid7 keys are k-sortable, so id order is chronological.
        $messages = $conversation->messages()->orderBy('id')->get();

        return response()->json([
            'id' => $conversation->id,
            'agent_class' => $conversation->agent_class,
            'agent_slug' => AgentSlug::make($conversation->agent_class),
            'title' => $conversation->title,
            'created_at' => $conversation->created_at?->toIso8601String(),
            'totals' => [
                'prompt_tokens' => (int) $messages->sum('prompt_tokens'),
                'completion_tokens' => (int) $messages->sum('completion_tokens'),
                'total_tokens' => (int) ($messages->sum('prompt_tokens') + $messages->sum('completion_tokens')),
            ],
            'messages' => $messages->map(fn (SynapseMessage $message): array => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'usage' => $message->usage ?: null,
                'duration_ms' => $message->duration_ms,
                'meta' => $message->meta ?: null,
                'metadata' => $message->metadata ?: null,
                'created_at' => $message->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * Delete a conversation and everything hanging off it.
     */
    public function destroy(string $id, ConversationRepository $repository): Response
    {
        $repository->deleteConversations([$id]);

        return response()->noContent();
    }
}
