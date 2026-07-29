<?php

namespace Redberry\Synapse\Chat;

use Illuminate\Support\Str;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Redberry\Synapse\Models\SynapseConversation;
use Redberry\Synapse\Models\SynapseMessage;
use Throwable;

/**
 * Owns every write a chat turn makes.
 *
 * The user row is written before the agent is invoked, so a crash mid-stream
 * still leaves a readable thread rather than a question that vanished.
 */
class ConversationWriter
{
    /**
     * Longest a derived conversation title may be.
     */
    protected const int TITLE_LENGTH = 80;

    /**
     * Find the conversation for this turn, creating one when needed.
     */
    public function conversation(?string $conversationId, string $agentClass, string $firstMessage): SynapseConversation
    {
        if ($conversationId !== null) {
            $existing = SynapseConversation::query()->find($conversationId);

            if ($existing !== null) {
                return $existing;
            }
        }

        return SynapseConversation::query()->create([
            'agent_class' => $agentClass,
            'title' => Str::limit($firstMessage, self::TITLE_LENGTH),
        ]);
    }

    /**
     * Store the message the developer just sent.
     *
     * @param  array<int, array<string, mixed>>  $attachments  SDK file serializations
     */
    public function storeUserMessage(
        SynapseConversation $conversation,
        string $content,
        array $attachments = [],
    ): SynapseMessage {
        return $this->store($conversation, 'user', content: $content, attachments: $attachments);
    }

    /**
     * Store a completed assistant turn.
     *
     * Tool calls and results are persisted in the SDK's own `toArray()` shapes
     * so `MessageHistory` can rehydrate them with the SDK's own `fromArray()`.
     */
    public function storeAssistantMessage(
        SynapseConversation $conversation,
        AgentResponse $response,
        int $durationMs,
        ?string $reasoning = null,
        ?array $structured = null,
    ): SynapseMessage {
        return $this->store(
            $conversation,
            'assistant',
            content: $response->text,
            toolCalls: $response->toolCalls->map->toArray()->values()->all(),
            toolResults: $response->toolResults->map->toArray()->values()->all(),
            usage: $response->usage->toArray(),
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
            durationMs: $durationMs,
            // Reasoning and structured output live only in the stream — the
            // SDK's `text` is `TextDelta::combine()`, which excludes reasoning,
            // and `structured` is not persisted anywhere. Without keeping them
            // here a replayed conversation would quietly differ from the one
            // the developer watched.
            meta: array_filter([
                ...$response->meta->toArray(),
                'reasoning' => $reasoning,
                'structured' => $structured,
            ], fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * Store an error that occurred while running the agent.
     *
     * Every failure lands here — provider errors, tool exceptions, resolution
     * failures — so the thread always explains itself (PRD Feature 6).
     */
    public function storeError(SynapseConversation $conversation, Throwable $e): SynapseMessage
    {
        $detail = ProviderErrorDetail::for($e);

        return $this->store($conversation, 'error', content: $e->getMessage(), metadata: [
            'exception_class' => $e::class,
            'stack_trace' => $e->getTraceAsString(),
            // What the provider actually said. `getMessage()` on an HTTP failure
            // is just "returned status code 400"; the reason is in the body.
            'response_status' => $detail['status'] ?? null,
            'response_body' => $detail['body'] ?? null,
        ]);
    }

    /**
     * Store a mid-stream error event, which carries no exception of its own.
     */
    public function storeStreamError(SynapseConversation $conversation, string $message, bool $recoverable): SynapseMessage
    {
        return $this->store($conversation, 'error', content: $message, metadata: [
            'exception_class' => Error::class,
            'recoverable' => $recoverable,
        ]);
    }

    /**
     * Insert a message row and mark the conversation as active.
     *
     * @param  array<int, mixed>  $attachments
     * @param  array<int, mixed>  $toolCalls
     * @param  array<int, mixed>  $toolResults
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $metadata
     */
    protected function store(
        SynapseConversation $conversation,
        string $role,
        ?string $content = null,
        array $attachments = [],
        array $toolCalls = [],
        array $toolResults = [],
        array $usage = [],
        ?int $promptTokens = null,
        ?int $completionTokens = null,
        ?int $durationMs = null,
        array $meta = [],
        array $metadata = [],
    ): SynapseMessage {
        $message = SynapseMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => $role,
            'content' => $content,
            'attachments' => $attachments,
            'tool_calls' => $toolCalls,
            'tool_results' => $toolResults,
            'usage' => $usage,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'duration_ms' => $durationMs,
            'meta' => $meta,
            'metadata' => $metadata,
        ]);

        // Retention and the history list both order by this.
        $conversation->forceFill(['updated_at' => now()])->save();

        return $message;
    }
}
