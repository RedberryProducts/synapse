<?php

namespace Redberry\Synapse\Chat;

use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Throwable;

/**
 * Writes the SSE stream the dashboard consumes, in the Vercel AI SDK UI message
 * protocol.
 *
 * Part names come from the SDK's own `toVercelProtocolArray()`, so the wire
 * format stays the SDK's. Synapse emits it rather than returning the SDK's
 * `usingVercelDataProtocol()` response because that serializer drops two things
 * a debugging tool cannot lose:
 *
 * 1. `ProviderToolEvent` has no Vercel mapping at all and is silently skipped.
 * 2. `ToolResult` is always serialized as `tool-output-available`, discarding
 *    `successful` / `error`.
 *
 * Everything else mirrors `Responses\Concerns\CanStreamUsingVercelProtocol`:
 * one `start` part, tool results without a preceding call are dropped, and the
 * `finish` part is held back until the very end.
 */
class StreamEmitter
{
    protected bool $started = false;

    /** @var array<string, true> */
    protected array $toolCalls = [];

    protected ?StreamEnd $streamEnd = null;

    /**
     * Captured output, used when no real output stream is available (tests).
     *
     * @var list<string>
     */
    protected array $written = [];

    /**
     * @param  string  $sapi  Injectable only so the flush decision can be tested;
     *                        production always passes the real SAPI.
     */
    public function __construct(protected bool $echo = true, protected string $sapi = PHP_SAPI) {}

    /**
     * Whether each part should be pushed to the client as it is written.
     *
     * Deliberately *not* `headers_sent()`. The stock php.ini sets
     * `output_buffering=4096`, so the first echo lands in PHP's own buffer and
     * the headers are never sent — a `headers_sent()` check therefore answers
     * "no" on every part for the whole run, and the dashboard sits blank until
     * the agent finishes and then paints the entire conversation at once.
     * Silent, and only on real deployments.
     *
     * The honest question is whether this is being served over HTTP at all.
     * Under CLI it is a test harness, which calls `sendContent()` inside its own
     * output buffer and reads the body back with `ob_get_clean()` — flushing
     * there would push our bytes past that buffer to stdout and hand the caller
     * an empty response. Symfony's `Response::send()` discriminates the same way.
     */
    public function shouldFlush(): bool
    {
        return ! in_array($this->sapi, ['cli', 'phpdbg', 'embed'], true);
    }

    /**
     * Announce the conversation before any model output, so the client can put
     * the id in the URL immediately — a refresh mid-stream still finds the thread.
     */
    public function conversationStarted(string $conversationId, string $userMessageId): void
    {
        $this->part([
            'type' => 'data-synapse-start',
            'data' => [
                'conversationId' => $conversationId,
                'userMessageId' => $userMessageId,
            ],
        ]);
    }

    /**
     * Translate one SDK stream event into zero or more protocol parts.
     */
    public function event(StreamEvent $event): void
    {
        // The gateway emits a StreamStart per step; the protocol wants one.
        if ($event instanceof StreamStart) {
            if ($this->started) {
                return;
            }

            $this->started = true;
        }

        if ($event instanceof ToolCall) {
            $this->toolCalls[$event->toolCall->id] = true;
        }

        // A result with no matching call would confuse the client's part state.
        if ($event instanceof ToolResult && ! isset($this->toolCalls[$event->toolResult->id])) {
            return;
        }

        // Gap 2: the SDK always reports success. Preserve the failure.
        if ($event instanceof ToolResult && ! $event->successful) {
            $this->part([
                'type' => 'tool-output-error',
                'toolCallId' => $event->toolResult->id,
                'errorText' => $event->error ?? 'The tool failed.',
            ]);

            return;
        }

        // Gap 1: no Vercel serialization exists, so carry the raw event.
        if ($event instanceof ProviderToolEvent) {
            $this->part([
                'type' => 'data-provider-tool',
                'data' => $event->toArray(),
            ]);

            return;
        }

        // Held back so `finish` is always the last model part.
        if ($event instanceof StreamEnd) {
            $this->streamEnd = $event;

            return;
        }

        $part = $event->toVercelProtocolArray();

        if (filled($part)) {
            $this->part($part);
        }
    }

    /**
     * Emit a whole answer as one text block.
     *
     * Used for structured-output agents, which the SDK refuses to stream, so a
     * single `prompt()` result still reaches the client through the same pipe.
     */
    public function text(string $text, string $messageId): void
    {
        if (! $this->started) {
            $this->started = true;
            $this->part(['type' => 'start', 'messageId' => $messageId]);
        }

        $this->part(['type' => 'text-start', 'id' => $messageId]);
        $this->part(['type' => 'text-delta', 'id' => $messageId, 'delta' => $text]);
        $this->part(['type' => 'text-end', 'id' => $messageId]);
    }

    /**
     * Emit the structured payload of a `HasStructuredOutput` agent.
     *
     * Rendered as a JSON card in Epic 5; carried from Epic 3 so the data is
     * never silently dropped on the way to the browser.
     *
     * @param  array<string, mixed>  $structured
     */
    public function structured(array $structured): void
    {
        $this->part(['type' => 'data-structured-output', 'data' => $structured]);
    }

    /**
     * Report a failed run inline, as a part rather than an HTTP status.
     *
     * By the time the stream closure runs the response headers are already sent,
     * so throwing here would truncate the stream with no explanation. This is
     * the only way an error can reach the developer.
     */
    public function error(Throwable $e, string $messageId): void
    {
        $detail = ProviderErrorDetail::for($e);

        $this->part([
            'type' => 'error',
            'errorText' => $e->getMessage(),
            'data' => [
                'messageId' => $messageId,
                'exceptionClass' => $e::class,
                'stackTrace' => $e->getTraceAsString(),
                // The provider's own words, which getMessage() drops entirely
                // on an HTTP failure.
                'responseStatus' => $detail['status'] ?? null,
                'responseBody' => $detail['body'] ?? null,
                'recoverable' => false,
            ],
        ]);
    }

    /**
     * Report a mid-stream error event, which carries no exception.
     */
    public function streamError(Error $event, string $messageId): void
    {
        $this->part([
            'type' => 'error',
            'errorText' => $event->message,
            'data' => [
                'messageId' => $messageId,
                'exceptionClass' => Error::class,
                'stackTrace' => null,
                'responseStatus' => null,
                'responseBody' => null,
                'recoverable' => $event->recoverable,
            ],
        ]);
    }

    /**
     * Report an informational event that is not a failure — a provider failover,
     * for instance, where the run carried on against the next provider.
     *
     * @param  array<string, mixed>  $data
     */
    public function notice(string $message, array $data = []): void
    {
        $this->part([
            'type' => 'data-synapse-notice',
            'data' => ['message' => $message, ...$data],
        ]);
    }

    /**
     * Close the stream: the held `finish` part, Synapse's own completion data,
     * then the protocol terminator.
     */
    /**
     * @param  array<string, mixed>|null  $usage
     */
    public function finish(?string $assistantMessageId = null, ?array $usage = null, ?int $durationMs = null): void
    {
        if ($this->streamEnd instanceof StreamEnd) {
            $part = $this->streamEnd->toVercelProtocolArray();

            if (filled($part)) {
                $this->part($part);
            }

            $this->streamEnd = null;
        }

        $this->part([
            'type' => 'data-synapse-end',
            'data' => [
                'assistantMessageId' => $assistantMessageId,
                'usage' => $usage,
                'durationMs' => $durationMs,
            ],
        ]);

        $this->raw('[DONE]');
    }

    /**
     * Everything written so far, for tests and for the non-echoing path.
     *
     * @return list<string>
     */
    public function written(): array
    {
        return $this->written;
    }

    /**
     * @param  array<string, mixed>  $part
     */
    protected function part(array $part): void
    {
        $this->raw((string) json_encode($part));
    }

    protected function raw(string $payload): void
    {
        $this->written[] = $payload;

        if (! $this->echo) {
            return;
        }

        echo 'data: '.$payload."\n\n";

        if (! $this->shouldFlush()) {
            return;
        }

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
