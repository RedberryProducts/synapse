<?php

namespace Redberry\Synapse\Chat;

use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
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

    public function __construct(protected ToolApprovals $approvals) {}

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
        return $conversation->getConnection()->transaction(function () use ($conversation, $content, $attachments): SynapseMessage {
            SynapseConversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $this->approvals->assertCanSend($conversation->id);
            $conversation->messages()->where('role', 'assistant')->where('status', 'paused')
                ->get()->each(function (SynapseMessage $message): void {
                    $message->forceFill(['steps' => array_map(
                        fn (array $step): array => [...$step, 'replay_blocks' => []],
                        $message->steps ?? [],
                    )])->save();
                });

            return $this->store($conversation, 'user', content: $content, attachments: $attachments);
        });
    }

    /**
     * Store an assistant turn, including useful output from a failed run.
     *
     * Steps retain the SDK's public response serialization. Raw provider blocks
     * survive only while a turn is paused; closed turns replay generically.
     */
    public function storeAssistantMessage(
        SynapseConversation $conversation,
        AgentResponse $response,
        int $durationMs,
        array $parts = [],
        string $status = 'completed',
        ?array $structured = null,
        ?array $approval = null,
        ?SynapseMessage $existing = null,
    ): SynapseMessage {
        $hasUsage = ! empty($existing->usage) || $status !== 'failed' || $response->steps->contains(
            fn (Step $step): bool => $step->finishReason !== FinishReason::Unknown,
        );

        $usage = TextUsage::fromArray($existing->usage ?? [])->add($response->usage);
        $steps = [...($existing->steps ?? []), ...$response->steps->map(fn (Step $step): array => $step->toArray())->all()];

        return $this->store(
            $conversation,
            'assistant',
            content: implode("\n\n", array_filter([$existing?->content, $response->text], fn (?string $text): bool => filled($text))),
            steps: array_map(fn (array $step): array => [
                ...$step, 'replay_blocks' => $status === 'paused' ? $step['replay_blocks'] : [],
            ], $steps),
            parts: $parts,
            status: $status,
            usage: $hasUsage ? $usage->toArray() : [],
            inputTokens: $hasUsage ? $usage->inputTokens : null,
            outputTokens: $hasUsage ? $usage->outputTokens : null,
            durationMs: ($existing->duration_ms ?? 0) + $durationMs,
            meta: array_filter([
                ...$response->meta->toArray(),
                'citations' => CitationSources::merge($existing === null ? [] : CitationSources::forMessage($existing), $response->meta->citations),
                'reasoning' => implode("\n\n", array_filter([$existing?->meta['reasoning'] ?? null, $response->reasoning])) ?: null,
                'structured' => $structured,
            ], fn (mixed $value): bool => $value !== null),
            approval: $approval,
            existing: $existing,
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

        return $this->store($conversation, 'error', content: $e->getMessage(), status: 'failed', metadata: [
            'exception_class' => $e::class,
            'stack_trace' => $e->getTraceAsString(),
            // What the provider actually said. `getMessage()` on an HTTP failure
            // is just "returned status code 400"; the reason is in the body.
            'response_status' => $detail['status'] ?? null,
            'response_body' => $detail['body'] ?? null,
            ...($e instanceof StreamErrorException && $e->error !== null ? ['stream_error' => $e->error->toArray()] : []),
        ]);
    }

    /**
     * Insert a message row and mark the conversation as active.
     *
     * @param  array<int, mixed>  $attachments
     * @param  array<int, mixed>  $steps
     * @param  array<int, mixed>  $parts
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $metadata
     */
    protected function store(
        SynapseConversation $conversation,
        string $role,
        ?string $content = null,
        array $attachments = [],
        array $steps = [],
        array $parts = [],
        string $status = 'completed',
        array $usage = [],
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $durationMs = null,
        array $meta = [],
        array $metadata = [],
        ?array $approval = null,
        ?SynapseMessage $existing = null,
    ): SynapseMessage {
        $message = $existing ?? new SynapseMessage;
        $message->forceFill([
            'conversation_id' => $conversation->id,
            'role' => $role,
            'content' => $content,
            'attachments' => $attachments,
            'steps' => $steps,
            'parts' => $parts,
            'status' => $status,
            'usage' => $usage,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'duration_ms' => $durationMs,
            'meta' => $meta,
            'metadata' => $metadata,
            'approval' => $approval,
            'approval_state' => $approval === null ? null : 'pending',
        ])->save();

        // Retention and the history list both order by this.
        $conversation->forceFill(['updated_at' => now()])->save();

        return $message;
    }
}
