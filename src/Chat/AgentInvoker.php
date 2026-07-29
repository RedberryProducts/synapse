<?php

namespace Redberry\Synapse\Chat;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Redberry\Synapse\Discovery\DiscoveredAgent;
use Redberry\Synapse\Models\SynapseConversation;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Models\SynapseToolInvocation;
use RuntimeException;
use Throwable;

/**
 * Runs one chat turn end to end: history, invocation, streaming, persistence.
 *
 * The whole pipeline sits inside a single `catch (Throwable)`. That is not
 * belt-and-braces — it is the entire error strategy (PRD Feature 6). The SDK
 * lets exceptions propagate: `InvokesTools::executeTool()` wraps the developer's
 * tool handler in `try/finally` with no `catch`, and `TextGenerationLoop`
 * hard-codes `successful: true` on every tool result event, so there is no
 * "failed tool result" path to observe. A tool that throws exits `stream()`
 * entirely, and only a catch-all this wide can turn that into a readable card.
 */
class AgentInvoker
{
    public function __construct(
        protected ConversationWriter $writer,
        protected MessageHistory $history,
        protected Dispatcher $events,
    ) {}

    /**
     * Invoke the agent and write the turn to the stream.
     */
    public function handle(
        DiscoveredAgent $discovered,
        string $message,
        ?string $conversationId,
        StreamEmitter $emitter,
    ): void {
        $conversation = null;
        $invocationId = null;

        try {
            $conversation = $this->writer->conversation($conversationId, $discovered->class, $message);

            // History must be read before the new user row exists, or the SDK's
            // own `new UserMessage($prompt)` would repeat the current message.
            $history = $this->history->for($conversation->id);

            $userMessage = $this->writer->storeUserMessage($conversation, $message);

            $emitter->conversationStarted($conversation->id, $userMessage->id);

            $agent = app($discovered->class);

            // Discovery only ever yields Agent implementations, but the
            // container's return type is `mixed` — make that guarantee real
            // rather than assumed, so a mis-bound class fails with a readable
            // card instead of a type error deep inside the SDK.
            if (! $agent instanceof Agent) {
                throw new RuntimeException($discovered->class.' does not implement '.Agent::class.'.');
            }

            $this->announceFailovers($emitter);
            $this->recordToolInvocations($conversation);

            if ($agent instanceof HasStructuredOutput) {
                $assistant = $this->promptOnce($agent, $message, $conversation, $emitter, $invocationId);
            } else {
                $assistant = $this->stream($agent, $history, $message, $conversation, $emitter, $invocationId);
            }

            // Attach this run's tool cards to the turn they produced.
            if ($assistant !== null && $invocationId !== null) {
                (new SynapseRecorder($conversation->id))->linkToMessage($invocationId, $assistant->id);
            }

            $emitter->finish($assistant?->id, $assistant?->usage, $assistant?->duration_ms);
        } catch (Throwable $e) {
            $this->fail($e, $conversation, $invocationId, $emitter);
        }
    }

    /**
     * Stream a turn, emitting protocol parts as the events arrive.
     *
     * @param  Message[]  $history
     */
    protected function stream(
        Agent $agent,
        array $history,
        string $message,
        SynapseConversation $conversation,
        StreamEmitter $emitter,
        ?string &$invocationId,
    ): ?SynapseMessage {
        // Only conversational agents get history. A stateless agent is invoked
        // exactly as it runs in production: current message, nothing else.
        $target = $agent instanceof Conversational
            ? SynapseConversationalAgent::for($agent, $history)
            : $agent;

        $startedAt = hrtime(true);
        $assistant = null;

        $response = $target->stream($message);

        $invocationId = $response->invocationId;

        $response->withinConversation($conversation->id)
            ->then(function (StreamedAgentResponse $streamed) use ($conversation, $startedAt, &$assistant): void {
                $assistant = $this->writer->storeAssistantMessage(
                    $conversation,
                    $streamed,
                    $this->elapsedMs($startedAt),
                );
            });

        $providerTools = new ProviderToolRecorder($conversation->id);

        foreach ($response as $event) {
            // Provider-native tools never fire Laravel events — the stream is
            // the only place they exist, so they are recorded as they pass.
            if ($event instanceof ProviderToolEvent) {
                $providerTools->record($event, $response->invocationId);

                $emitter->event($event);

                continue;
            }

            // A mid-stream error is reported and recorded, but the stream may
            // still continue — the SDK marks these `recoverable`.
            if ($event instanceof Error) {
                $errorMessage = $this->writer->storeStreamError(
                    $conversation,
                    $event->message,
                    $event->recoverable,
                );

                $emitter->streamError($event, $errorMessage->id);

                continue;
            }

            $emitter->event($event);
        }

        return $assistant;
    }

    /**
     * Run a structured-output agent in one shot.
     *
     * `StreamsText::stream()` throws `InvalidArgumentException` for any agent
     * implementing `HasStructuredOutput`, so these never take the streaming
     * path. They are also invoked undecorated: the decorator cannot carry
     * `HasStructuredOutput` (it would break every conversational stream), and no
     * agent is usefully both today. Epic 5 revisits the rendering.
     */
    protected function promptOnce(
        Agent $agent,
        string $message,
        SynapseConversation $conversation,
        StreamEmitter $emitter,
        ?string &$invocationId,
    ): SynapseMessage {
        $startedAt = hrtime(true);

        $response = $agent->prompt($message);

        $invocationId = $response->invocationId;

        $assistant = $this->writer->storeAssistantMessage(
            $conversation,
            $response,
            $this->elapsedMs($startedAt),
        );

        $this->emitPrompted($response, $emitter);

        return $assistant;
    }

    /**
     * Push a non-streamed response through the same protocol the client already
     * understands, so one reader serves both transports.
     */
    protected function emitPrompted(AgentResponse $response, StreamEmitter $emitter): void
    {
        $emitter->text($response->text, $response->invocationId);

        if ($response instanceof StructuredAgentResponse) {
            $emitter->structured($response->structured);
        }
    }

    /**
     * Record the failure, close out anything left hanging, and tell the browser.
     */
    protected function fail(
        Throwable $e,
        ?SynapseConversation $conversation,
        ?string $invocationId,
        StreamEmitter $emitter,
    ): void {
        $messageId = '';

        if ($conversation instanceof SynapseConversation) {
            $messageId = $this->writer->storeError($conversation, $e)->id;
        }

        $this->failDanglingInvocations($invocationId, $e);

        $emitter->error($e, $messageId);
        $emitter->finish();
    }

    /**
     * A tool that throws fired `InvokingTool` but never `ToolInvoked`, leaving
     * its row pending forever. Close those out so the card shows failed rather
     * than spinning (rows themselves arrive in Epic 4).
     */
    protected function failDanglingInvocations(?string $invocationId, Throwable $e): void
    {
        if ($invocationId === null) {
            return;
        }

        SynapseToolInvocation::query()
            ->where('invocation_id', $invocationId)
            ->where('status', 'pending')
            ->update([
                'status' => 'error',
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);
    }

    /**
     * Write a row for every tool the run invokes.
     *
     * Registered per invocation because a row belongs to a conversation, and a
     * globally-registered listener would have to guess which one.
     */
    protected function recordToolInvocations(SynapseConversation $conversation): void
    {
        $recorder = new SynapseRecorder($conversation->id);

        $this->events->listen(InvokingTool::class, $recorder->toolInvoking(...));
        $this->events->listen(ToolInvoked::class, $recorder->toolInvoked(...));
    }

    /**
     * Surface a provider failover as information, not as a failure — the run
     * carried on against the next configured provider.
     */
    protected function announceFailovers(StreamEmitter $emitter): void
    {
        $this->events->listen(
            AgentFailedOver::class,
            function (AgentFailedOver $event) use ($emitter): void {
                $emitter->notice(
                    sprintf(
                        'Failed over from %s (%s): %s',
                        $event->provider->name(),
                        $event->model,
                        $event->exception->getMessage(),
                    ),
                    ['provider' => $event->provider->name(), 'model' => $event->model],
                );
            }
        );
    }

    protected function elapsedMs(float|int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
