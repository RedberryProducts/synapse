<?php

namespace App\Ai\Agents;

use App\Ai\Tools\RuntimeTool;
use Generator;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Streaming\Events\Citation;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;

#[MaxSteps(4)]
class RuntimeAgent implements Agent, Conversational, HasMiddleware, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Deterministic runtime qualification. No provider requests.';
    }

    public function provider(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return 'runtime-fixture';
    }

    public function messages(): iterable
    {
        return [];
    }

    public function tools(): iterable
    {
        return [(new RuntimeTool)->withoutApproval()];
    }

    public function middleware(): array
    {
        return [function (PendingStep $step): StepResult {
            Http::preventStrayRequests();

            return new StepResult($this->events($step));
        }];
    }

    private function events(PendingStep $step): Generator
    {
        $users = array_values(array_filter($step->messages, fn ($m) => $m->role->value === 'user'));
        $marker = end($users)->content;
        $done = false;
        foreach ($step->messages as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $result) {
                    if (($result->arguments['marker'] ?? null) === $marker) {
                        $done = true;
                    }
                }
            }
        }
        if (! $done && ! str_starts_with($marker, 'recall')) {
            $call = new ToolCall('call-'.substr(hash('sha256', $marker), 0, 16), 'RuntimeTool', ['marker' => $marker]);
            yield (new ToolCallEvent('call-event', $call, time()))->withInvocationId($step->invocationId);

            return new StepResponse('', [$call], FinishReason::ToolCalls, new TextUsage(10, 2), new Meta('openai', $step->model));
        }
        $text = 'answer:'.$marker.';users='.count($users);
        yield (new TextDelta('text-1', 'answer', $text, time()))->withInvocationId($step->invocationId);
        usleep(1000000);
        if (str_starts_with($marker, 'fail')) {
            throw new \RuntimeException('fixture failure:'.$marker);
        }
        yield (new TextDelta('text-2', 'answer', ';done', time()))->withInvocationId($step->invocationId);
        $citation = new UrlCitation('https://example.com/runtime', 'Runtime source');
        yield (new Citation('citation', 'answer', $citation, time()))->withInvocationId($step->invocationId);

        return new StepResponse($text.';done', [], FinishReason::Stop, new TextUsage(15, 5), new Meta('openai', $step->model));
    }
}
