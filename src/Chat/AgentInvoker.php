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
use RuntimeException;
use Throwable;

class AgentInvoker
{
    public const APPROVAL_NOTICE = 'Tool approval is required. Synapse does not support approvals yet; no approval was granted. Start a new message to continue.';

    public function __construct(
        protected ConversationWriter $writer,
        protected MessageHistory $history,
        protected AttachmentStore $attachments,
        protected RecordingScope $recording,
        protected ConversationPresenter $presenter,
    ) {}

    public function handle(
        DiscoveredAgent $discovered,
        string $message,
        ?string $conversationId,
        StreamEmitter $emitter,
        array $attachments = [],
        ?string $model = null,
    ): void {
        $conversation = null;
        $recorder = null;
        $assistant = null;
        $turn = null;
        $startedAt = hrtime(true);

        try {
            $conversation = $this->writer->conversation($conversationId, $discovered->class, $message);
            $history = $this->history->for($conversation->id);
            $user = $this->writer->storeUserMessage($conversation, $message, $this->attachments->toArray($attachments));
            $emitter->conversationStarted($conversation->id, $user->id);

            $agent = app($discovered->class);
            if (! $agent instanceof Agent) {
                throw new RuntimeException($discovered->class.' does not implement '.Agent::class.'.');
            }

            $structured = $agent instanceof HasStructuredOutput;
            $target = ! $structured && $agent instanceof Conversational
                ? SynapseConversationalAgent::for($agent, $history) : $agent;
            $recorder = new SynapseRecorder($conversation->id, ! $structured);
            $response = $this->recording->during($target, $recorder, $emitter, function () use ($target, $message, $attachments, $model, $structured, $recorder, $emitter): AgentResponse {
                if ($structured) {
                    return $target->prompt($message, $attachments, model: $model);
                }
                $stream = $target->stream($message, $attachments, model: $model);
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
                if ($completed->steps->last()?->finishReason === FinishReason::Unknown) {
                    $recorder->capture($completed);
                    throw new RuntimeException('The provider ended the stream without a completion reason.');
                }

                return $completed;
            });

            $recorder->capture($response);
            $status = $response->hasPendingApprovals() ? 'paused' : 'completed';
            if ($status === 'paused') {
                $recorder->notice(self::APPROVAL_NOTICE);
                $emitter->notice(self::APPROVAL_NOTICE);
            }
            $parts = $recorder->finish($status);
            $assistant = $this->writer->storeAssistantMessage(
                $conversation, $recorder->snapshot(), $this->elapsedMs($startedAt), $parts, $status,
                $response instanceof StructuredAgentResponse ? $response->structured : null,
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
                    if ($partial->text !== '' || $partial->reasoning !== '' || $partial->steps->isNotEmpty() || $parts !== []) {
                        $assistant = $this->writer->storeAssistantMessage($conversation, $partial, $this->elapsedMs($startedAt), $parts, $status);
                        $recorder->linkToMessage($assistant->id);
                        $turn = $this->presenter->turn($assistant);
                    }
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
