<?php

namespace Redberry\Synapse\Chat;

use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Exceptions\ApprovalNotResumableException;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Streaming\Events\Citation;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Laravel\Ai\Tools\ToolNameResolver;
use Redberry\Synapse\Models\SynapseToolInvocation;
use Throwable;

class SynapseRecorder
{
    public ?string $invocationId = null;

    /** @var list<array{url: string, title: string|null}> */
    protected array $sources = [];

    protected int $attempt = -1;

    protected int $step = 0;

    protected int $streamStep = -1;

    protected string $provider = '';

    protected string $model = '';

    /** @var array<int, array<int, Step>> */
    protected array $attempts = [];

    /** @var list<array<string, mixed>> */
    protected array $parts = [];

    /** @var array<string, array{call: ToolCall, provider: string, group: string}> */
    protected array $calls = [];

    /** @var array<string, SynapseToolInvocation> Execution ID => observation. */
    protected array $executions = [];

    /** @var array<string, string> Execution ID => attempt/step. */
    protected array $groups = [];

    /** @var array<string, string> Model call key => observation ID. */
    protected array $observations = [];

    /** @var array<string, string> */
    protected array $text = [];

    protected ?string $lastBlock = null;

    /** @var array<string, true> */
    protected array $resumedExecutions = [];

    public function __construct(
        protected string $conversationId,
        protected bool $streaming = true,
        protected ?ApprovalContinuation $continuation = null,
    ) {
        $this->parts = array_map(fn (array $part): array => $part['type'] === 'notice' && $part['message'] === AgentInvoker::APPROVAL_NOTICE
            ? [...$part, 'message' => 'Tool approval decisions submitted.'] : $part,
            $continuation?->message->parts ?? []);
    }

    /** @return array{provider: string, model: string} */
    public function providerAndModel(): array
    {
        return ['provider' => $this->provider, 'model' => $this->model];
    }

    public function begin(string $invocationId, string $provider, string $model): void
    {
        $this->invocationId = $invocationId;
        $this->provider = $provider;
        $this->model = $model;
        $this->attempt++;
        $this->step = 0;
        $this->streamStep = -1;
        $this->attempts[$this->attempt] = [];
        $this->lastBlock = null;
    }

    public function startingStep(StartingStep $event): void
    {
        $this->step = $event->stepNumber;
        $this->provider = $event->provider->name();
        $this->model = $event->model;
        $this->lastBlock = null;
    }

    public function stepCompleted(StepCompleted $event): void
    {
        $response = $event->response;
        $this->sources = CitationSources::merge($this->sources, $response->meta->citations);
        $this->attempts[$this->attempt][$event->stepNumber] = new Step(
            $response->text, $response->toolCalls, [], $response->finishReason,
            $response->usage, $response->meta, $response->reasoning,
            $response->replayBlocks, $response->providerToolCalls,
        );

        if (! $this->streaming) {
            if ($response->reasoning !== '') {
                $this->delta('reasoning', 'prompt', $response->reasoning);
            }
            if ($response->text !== '') {
                $this->delta('text', 'prompt', $response->text);
            }
        }
        foreach ($response->toolCalls as $call) {
            $this->call($call);
        }
    }

    public function event(StreamEvent $event): void
    {
        if ($event->invocationId !== $this->invocationId) {
            return;
        }

        if ($event instanceof StreamStart) {
            $this->step = ++$this->streamStep;
            $this->lastBlock = null;
        } elseif ($event instanceof Citation) {
            $this->sources = CitationSources::merge($this->sources, [$event->citation]);
        } elseif ($event instanceof TextDelta) {
            $this->delta('text', $event->messageId, $event->delta);
        } elseif ($event instanceof ReasoningDelta) {
            $this->delta('reasoning', $event->reasoningId, $event->delta);
        } elseif ($event instanceof ToolCallEvent) {
            $this->call($event->toolCall);
        } elseif ($event instanceof ToolResultEvent && ! $event->preliminary) {
            $this->continuation?->capture($event->toolResult);
        } elseif ($event instanceof ProviderToolEvent) {
            $row = (new ProviderToolRecorder($this->conversationId))->record($event, $this->invocationId);
            $key = 'provider:'.$this->group().':'.$row->id;
            if (! isset($this->observations[$key])) {
                $this->observations[$key] = $row->id;
                $this->part(['type' => 'tool', 'tool_invocation_id' => $row->id]);
            }
        }
    }

    protected function call(ToolCall $call): void
    {
        $key = $this->group().':'.$call->id;
        if (isset($this->calls[$key])) {
            return;
        }
        $this->calls[$key] = ['call' => $call, 'provider' => $this->provider, 'group' => $this->group()];
        $this->part(['type' => 'tool', 'call_key' => $key]);
    }

    protected function delta(string $type, string $source, string $text): void
    {
        if ($type === 'text') {
            $this->text[$this->group()] = ($this->text[$this->group()] ?? '').$text;
        }
        $key = $this->group().':'.$type.':'.$source;
        if ($this->lastBlock === $key) {
            $this->parts[array_key_last($this->parts)]['text'] .= $text;
        } else {
            $this->part(['type' => $type, 'text' => $text]);
        }
        $this->lastBlock = $key;
    }

    /** @param array<string, mixed> $part */
    protected function part(array $part): void
    {
        $this->parts[] = ['id' => 'part-'.count($this->parts), ...$part];
        $this->lastBlock = null;
    }

    /** @param array<string, mixed> $data */
    public function notice(string $message, array $data = []): void
    {
        $this->part(['type' => 'notice', 'message' => $message, 'data' => $data]);
    }

    public function toolInvoking(InvokingTool $event): void
    {
        $row = $this->continuation?->invoking($event);
        if ($row !== null) {
            $this->resumedExecutions[$event->toolInvocationId] = true;
        }
        $row ??= new SynapseToolInvocation;
        $row->forceFill([
            'conversation_id' => $this->conversationId,
            'invocation_id' => $event->invocationId,
            'tool_invocation_id' => $event->toolInvocationId,
            'provider' => $this->provider,
            'type' => 'tool',
            'name' => ToolNameResolver::resolve($event->tool),
            'arguments' => $event->arguments,
            'status' => 'pending',
            'started_at' => now(),
        ]);
        $row->save();
        $this->executions[$event->toolInvocationId] = $row;
        $this->groups[$event->toolInvocationId] = $this->group();
    }

    public function toolInvoked(ToolInvoked $event): void
    {
        // The SDK dispatches this event before casting the handler result.
        $this->executions[$event->toolInvocationId]->forceFill([
            'status' => 'success', 'result' => (string) $event->result,
            'finished_at' => now(), 'duration_ms' => (int) round($event->time),
        ])->save();
    }

    public function toolFailed(ToolFailed $event): void
    {
        $this->executions[$event->toolInvocationId]->forceFill([
            'status' => 'error', 'error' => $event->exception->getMessage(),
            'finished_at' => now(), 'duration_ms' => (int) round($event->time),
        ])->save();
    }

    public function failedAttempt(AgentFailed $event): void
    {
        // The SDK's failure context retains results completed before a later
        // handler throws, even when those results never reached the stream.
        $response = $event->prompt->runContext()?->recordedResponse();
        if ($response !== null) {
            $this->capture($response);
        }
        $last = array_key_last($this->attempts[$this->attempt]);
        if ($last !== null && ! $event->exception instanceof ApprovalNotResumableException) {
            $this->attempts[$this->attempt][$last]->finishReason = FinishReason::Error;
        }
    }

    public function capture(AgentResponse $response): void
    {
        foreach ($response->steps as $number => $step) {
            $this->sources = CitationSources::merge($this->sources, $step->meta->citations);
            if (! $this->streaming && ! isset($this->attempts[$this->attempt][$number])) {
                $this->step = $number;
                $this->lastBlock = null;
                if ($step->reasoning !== '') {
                    $this->delta('reasoning', 'prompt', $step->reasoning);
                }
                if ($step->text !== '') {
                    $this->delta('text', 'prompt', $step->text);
                }
            }
        }
        $this->sources = CitationSources::merge($this->sources, $response->meta->citations);
        $this->attempts[$this->attempt] = $response->steps->map(fn (Step $step): Step => clone $step)->all();
    }

    public function snapshot(): AgentResponse
    {
        $steps = collect($this->steps());
        // A stream can yield visible text without completing its SDK step.
        // Keep that text in replay without inventing usage for the missing response.
        if (! isset($this->attempts[$this->attempt][$this->step]) && isset($this->text[$this->group()])) {
            $steps->push(new Step(
                $this->text[$this->group()], [], [], FinishReason::Unknown,
                new TextUsage, new Meta($this->provider, $this->model), '', [],
            ));
        }
        $usage = $steps->reduce(fn (TextUsage $total, Step $step): TextUsage => $total->add($step->usage), new TextUsage);
        $text = implode("\n\n", array_filter($this->text, fn (string $text): bool => trim($text) !== ''));
        $reasoning = collect(array_slice($this->parts, count($this->continuation?->message->parts ?? [])))->where('type', 'reasoning')->pluck('text')->implode("\n\n");
        $meta = $steps->last()->meta ?? new Meta($this->provider, $this->model);

        $meta = new Meta($meta->provider, $meta->model);
        foreach ($this->sources as $source) {
            $meta->citations->push(new UrlCitation($source['url'], $source['title']));
        }
        $response = new AgentResponse($this->invocationId ?? '', $text, $usage, $meta);
        $response->withSteps($steps)->withReasoning($reasoning);

        return $response;
    }

    /**
     * Associate settled model calls with executions only within their step.
     * Approval/skipped calls have no execution ID. Failed ambiguous calls keep
     * separate observations rather than guessing which identical call ran.
     *
     * @return list<array<string, mixed>>
     */
    public function finish(string $status, ?Throwable $failure = null): array
    {
        $results = [];
        foreach ($this->attempts as $attempt => $steps) {
            foreach ($steps as $number => $step) {
                foreach ($step->toolCalls as $call) {
                    $key = $attempt.':'.$number.':'.$call->id;
                    if (! isset($this->calls[$key])) {
                        $this->calls[$key] = ['call' => $call, 'provider' => $step->meta->provider ?? $this->provider, 'group' => $attempt.':'.$number];
                        $this->part(['type' => 'tool', 'call_key' => $key]);
                    }
                }
                foreach ($step->toolResults as $result) {
                    $results[$attempt.':'.$number.':'.$result->id] = $result;
                }
            }
        }

        $used = $this->resumedExecutions;
        foreach ($this->calls as $key => $entry) {
            $call = $entry['call'];
            $result = $results[$key] ?? null;
            $candidates = array_filter($this->executions, function (SynapseToolInvocation $row, string $id) use ($entry, $call, $result, $used): bool {
                return ! isset($used[$id]) && $this->groups[$id] === $entry['group']
                    && $row->name === $call->name && $row->arguments === $call->arguments
                    && ($result !== null ? $result->successful() && $row->status === 'success' && $row->result === $result->result : $row->status === 'error');
            }, ARRAY_FILTER_USE_BOTH);
            $unanswered = array_filter($this->calls, fn (array $other, string $otherKey): bool => $other['group'] === $entry['group'] && $other['call']->name === $call->name
                && $other['call']->arguments === $call->arguments && ! isset($results[$otherKey]), ARRAY_FILTER_USE_BOTH);
            $row = ($result !== null || (count($candidates) === 1 && count($unanswered) === 1)) ? reset($candidates) : false;
            if ($row !== false) {
                $used[$row->tool_invocation_id] = true;
                $row->forceFill(['tool_call_id' => $call->id])->save();
            } else {
                $row = SynapseToolInvocation::query()->create([
                    'conversation_id' => $this->conversationId, 'invocation_id' => $this->invocationId,
                    'tool_call_id' => $call->id, 'provider' => $entry['provider'],
                    'type' => 'tool', 'name' => $call->name, 'arguments' => $call->arguments,
                    'result' => $result?->result,
                    'status' => $result !== null ? ($result->denied ? 'denied' : ($result->successful() ? 'success' : 'error')) : ($status === 'paused' ? 'paused' : 'error'),
                    'error' => $result !== null ? $result->error() : ($status === 'paused' ? null : 'The tool call ended without a recorded result.'),
                    'finished_at' => $status === 'paused' && $result === null ? null : now(),
                ]);
            }
            $this->observations[$key] = $row->id;
        }
        foreach ($this->executions as $id => $row) {
            if ($row->status === 'pending') {
                $row->forceFill(['status' => 'error', 'error' => $failure?->getMessage() ?? 'The invocation ended before the tool returned.', 'finished_at' => now()])->save();
            }
            if (! isset($used[$id])) {
                $this->part(['type' => 'tool', 'tool_invocation_id' => $row->id]);
            }
        }
        SynapseToolInvocation::query()->where('conversation_id', $this->conversationId)
            ->where('invocation_id', $this->invocationId)->where('type', 'provider_tool')->where('status', 'pending')
            ->when($status !== 'paused', fn ($query) => $query->update(['status' => 'error', 'error' => $failure?->getMessage() ?? 'The provider ended without a tool result.', 'finished_at' => now()]));

        if ($status === 'failed') {
            $this->continuation?->fail();
        }

        return array_map(function (array $part): array {
            if (! isset($part['call_key'])) {
                return $part;
            }
            $id = $this->observations[$part['call_key']];
            unset($part['call_key']);

            return [...$part, 'tool_invocation_id' => $id];
        }, $this->parts);
    }

    public function linkToMessage(string $messageId): void
    {
        SynapseToolInvocation::query()->where('conversation_id', $this->conversationId)
            ->where('invocation_id', $this->invocationId)->whereNull('message_id')
            ->update(['message_id' => $messageId]);
    }

    /** @return list<Step> */
    protected function steps(): array
    {
        return array_merge([], ...array_values($this->attempts));
    }

    protected function group(): string
    {
        return $this->attempt.':'.$this->step;
    }
}
