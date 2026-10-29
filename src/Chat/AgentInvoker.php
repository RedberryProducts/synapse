<?php

namespace Redberry\Synapse\Chat;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Exceptions\ApprovalNotResumableException;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Redberry\Synapse\Discovery\DiscoveredAgent;
use Redberry\Synapse\Models\SynapseMessage;
use RuntimeException;
use Throwable;

class AgentInvoker
{
    public const APPROVAL_NOTICE = 'Tool approval is required. Review the pending tool calls to continue.';

    public function __construct(
        protected ConversationWriter $writer,
        protected MessageHistory $history,
        protected AttachmentStore $attachments,
        protected RecordingScope $recording,
        protected ConversationPresenter $presenter,
        protected ToolApprovals $approvals,
    ) {}

    public function handle(
        DiscoveredAgent $discovered,
        string $message,
        ?string $conversationId,
        StreamEmitter $emitter,
        array $attachments = [],
        ?string $model = null,
        ?SynapseMessage $resuming = null,
    ): void {
        $conversation = null;
        $recorder = null;
        $assistant = null;
        $turn = null;
        $finishReason = null;
        $structuredOutput = null;
        $startedAt = hrtime(true);

        try {
            $conversation = $this->writer->conversation($conversationId, $discovered->class, $message);
            if ($resuming === null) {
                $history = $this->history->for($conversation->id);
                $user = $this->writer->storeUserMessage($conversation, $message, $this->attachments->toArray($attachments));
                $emitter->conversationStarted($conversation->id, $user->id);
            } else {
                $emitter->approvalResumed($resuming);
            }

            $agent = app($discovered->class);
            if (! $agent instanceof Agent) {
                throw new RuntimeException($discovered->class.' does not implement '.Agent::class.'.');
            }

            $structured = $agent instanceof HasStructuredOutput;
            $conversational = ! $structured && $agent instanceof Conversational;
            $history = $resuming !== null ? $this->history->forApproval($resuming, $conversational) : ($conversational ? $history : []);
            $target = SynapseConversationalAgent::for($agent, $history);
            $continuation = $resuming === null ? null : new ApprovalContinuation($resuming);
            if ($continuation !== null) {
                $target->continuingApproval($continuation);
            }
            $input = $continuation?->decisions() ?? $message;
            $provider = $resuming?->approval['provider'];
            $model = $resuming?->approval['model'] ?? $model;
            $recorder = new SynapseRecorder($conversation->id, ! $structured, $continuation);
            $response = $this->recording->during($target, $recorder, $emitter, function () use ($target, $input, $attachments, $provider, $model, $structured, $recorder, $emitter): AgentResponse {
                if ($structured) {
                    return $target->prompt($input, $attachments, provider: $provider, model: $model);
                }
                $stream = $target->stream($input, $attachments, provider: $provider, model: $model);
                $completed = null;
                $terminalError = null;
                $stream->then(function (StreamedAgentResponse $response) use (&$completed): void {
                    $completed = $response;
                });
                foreach ($stream as $event) {
                    $recorder->event($event);
                    if ($event instanceof Error) {
                        if (! $event->recoverable) {
                            $terminalError = $event;

                            continue;
                        }
                        $recorder->notice($event->message, ['recoverable' => true]);
                        $emitter->notice($event->message, ['recoverable' => true]);
                    } else {
                        $emitter->event($event);
                    }
                }
                if ($terminalError !== null) {
                    if ($completed !== null) {
                        $recorder->capture($completed);
                    }
                    throw new StreamErrorException($terminalError);
                }
                if ($completed === null) {
                    throw new RuntimeException('The provider ended the stream without completing the response.');
                }

                return $completed;
            });

            $recorder->capture($response);
            $structuredOutput = $response instanceof StructuredAgentResponse ? $response->structured : null;
            $finishReason = CompletionReason::forResponse($response);
            $status = $response->hasPendingApprovals() ? 'paused' : 'completed';
            if ($status !== 'paused') {
                if ($finishReason === FinishReason::Unknown) {
                    throw new RuntimeException($structured
                        ? 'The provider ended the response without a completion reason.'
                        : 'The provider ended the stream without a completion reason.');
                }
                if ($finishReason === FinishReason::Error) {
                    throw new RuntimeException('The SDK reported an error completion.');
                }
            }
            if ($status === 'paused') {
                $recorder->notice(self::APPROVAL_NOTICE);
                $emitter->notice(self::APPROVAL_NOTICE);
            }
            $parts = $recorder->finish($status);
            $assistant = $this->writer->storeAssistantMessage(
                $conversation, $recorder->snapshot(), $this->elapsedMs($startedAt), $parts, $status,
                $structuredOutput,
                $this->approvals->checkpoint($response, $recorder), $resuming, $finishReason,
            );
            $recorder->linkToMessage($assistant->id);
            $turn = $this->presenter->turn($assistant);
            if ($structured) {
                $emitter->text($response->text, $response->invocationId);
                if ($response instanceof StructuredAgentResponse) {
                    $emitter->structured($response->structured);
                }
            }
        } catch (Throwable $e) {
            $paused = $e instanceof ApprovalNotResumableException;
            if ($paused) {
                $recorder?->notice(self::APPROVAL_NOTICE);
                $emitter->notice(self::APPROVAL_NOTICE);
            }
            if ($conversation !== null) {
                if ($recorder !== null && $assistant === null) {
                    $status = $paused ? 'paused' : 'failed';
                    $parts = $recorder->finish($status, $e);
                    $partial = $recorder->snapshot();
                    if ($resuming !== null || $partial->text !== '' || $partial->reasoning !== '' || $partial->steps->isNotEmpty() || $partial->meta->citations->isNotEmpty() || $parts !== []) {
                        $assistant = $this->writer->storeAssistantMessage($conversation, $partial, $this->elapsedMs($startedAt), $parts, $status, structured: $structuredOutput, existing: $resuming, finishReason: $finishReason);
                        $recorder->linkToMessage($assistant->id);
                        $turn = $this->presenter->turn($assistant);
                    }
                }
                if ($resuming !== null && $assistant === null) {
                    $resuming->forceFill(['status' => 'failed', 'approval_state' => null, 'approval' => null])->save();
                }
                if (! $paused) {
                    $error = $this->writer->storeError($conversation, $e);
                }
            }
            if (! $paused) {
                $emitter->error($e, $error->id ?? '');
            }
        }

        $emitter->finish($assistant?->id, $assistant?->usage ?: null, $assistant?->duration_ms,
            isset($error) ? 'failed' : ($assistant->status ?? 'failed'),
            $turn,
        );
    }

    protected function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
