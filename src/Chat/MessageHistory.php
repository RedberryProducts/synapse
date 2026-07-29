<?php

namespace Redberry\Synapse\Chat;

use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Redberry\Synapse\Models\SynapseMessage;

/**
 * Rebuilds an SDK `Message[]` thread from Synapse's own rows.
 *
 * This deliberately mirrors `Laravel\Ai\Storage\DatabaseConversationStore::
 * getLatestConversationMessages()` step for step. Synapse stores replay data in
 * the SDK's own serialization shapes precisely so rehydration can follow the
 * SDK's code rather than maintain a parallel format — when the SDK's payloads
 * evolve, ours evolve with them.
 */
class MessageHistory
{
    /**
     * Matches the SDK's own `maxConversationMessages()` default.
     */
    public const int LIMIT = 100;

    public function __construct(protected AttachmentStore $attachments) {}

    /**
     * @return Message[]
     */
    public function for(string $conversationId, int $limit = self::LIMIT): array
    {
        return SynapseMessage::query()
            ->where('conversation_id', $conversationId)
            // uuid7 keys are k-sortable, so id order is chronological.
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->flatMap(fn (SynapseMessage $message): array => $this->toMessages($message))
            ->all();
    }

    /**
     * @return Message[]
     */
    protected function toMessages(SynapseMessage $record): array
    {
        // Error rows are Synapse observations, never model context.
        if ($record->role === 'error') {
            return [];
        }

        if ($record->role === 'user') {
            // A turn that carried files has to carry them again, or a follow-up
            // question silently loses the image it refers to and the model
            // answers about a picture it can no longer see.
            $attachments = $this->attachments->rehydrate($record->attachments);

            return $attachments === []
                ? [new Message('user', (string) $record->content)]
                : [new UserMessage((string) $record->content, $attachments)];
        }

        $toolCalls = new Collection($record->tool_calls);
        $toolResults = new Collection($record->tool_results);

        if ($toolCalls->isEmpty()) {
            return [new AssistantMessage((string) $record->content)];
        }

        // A tool call with no result never completed — replay its text only.
        if ($toolResults->isEmpty()) {
            return filled($record->content)
                ? [new AssistantMessage((string) $record->content)]
                : [];
        }

        $messages = [
            new AssistantMessage('', $toolCalls->map(ToolCall::fromArray(...))),
            new ToolResultMessage($toolResults->map(ToolResult::fromArray(...))),
        ];

        if (filled($record->content)) {
            $messages[] = new AssistantMessage((string) $record->content);
        }

        return $messages;
    }
}
