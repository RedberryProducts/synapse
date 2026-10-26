<?php

namespace Redberry\Synapse\Chat;

use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Redberry\Synapse\Models\SynapseMessage;

class MessageHistory
{
    public const int LIMIT = 100;

    public function __construct(protected AttachmentStore $attachments) {}

    /** @return Message[] */
    public function for(string $conversationId, int $limit = self::LIMIT): array
    {
        return SynapseMessage::query()->where('conversation_id', $conversationId)
            ->whereIn('role', ['user', 'assistant'])
            ->orderByDesc('id')->limit($limit)->get()->reverse()->values()
            ->flatMap(fn (SynapseMessage $message): array => $this->toMessages($message))->all();
    }

    /** @return Message[] */
    protected function toMessages(SynapseMessage $record): array
    {
        if ($record->role === 'user') {
            $attachments = $this->attachments->rehydrate($record->attachments);

            return $attachments === []
                ? [new Message('user', $record->content ?? '')]
                : [new UserMessage($record->content ?? '', $attachments)];
        }

        if (empty($record->steps)) {
            return filled($record->content) ? [new AssistantMessage($record->content)] : [];
        }

        return collect($record->steps)->flatMap(function (array $step) use ($record): array {
            $results = collect($step['tool_results'])->map(ToolResult::fromArray(...))->keyBy('id');
            $failed = $record->status === 'failed' || $step['finish_reason'] === 'error';
            $calls = collect($step['tool_calls'])->map(ToolCall::fromArray(...))
                ->filter(fn (ToolCall $call): bool => $failed || $results->has($call->id))->values();
            $paired = $calls->map(fn (ToolCall $call): ToolResult => $results->get($call->id) ?? new ToolResult(
                id: $call->id, name: $call->name, arguments: $call->arguments,
                result: 'This tool call was interrupted before a result was recorded, so it may or may not have run.',
                resultId: $call->resultId, failed: true,
            ));
            // New prompts close paused turns. Never replay raw provider state
            // naming an unapproved call, or send it to a different provider.
            $messages = $step['text'] !== '' || $calls->isNotEmpty()
                ? [new AssistantMessage($step['text'], $calls)] : [];
            if ($paired->isNotEmpty()) {
                $messages[] = new ToolResultMessage($paired);
            }

            return $messages;
        })->all();
    }
}
