<?php

namespace Redberry\Synapse\Tests\Fixtures;

use Generator;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use RuntimeException;
use Workbench\App\Agents\SupportAgent;

class InterruptedAnswerAgent extends SupportAgent implements HasMiddleware
{
    public function __construct(public string $partial = 'The hoodie stock is') {}

    public function middleware(): array
    {
        return [function (PendingStep $step): StepResponse|StepResult {
            if (collect($step->messages)->last()->content === 'Continue') {
                $previous = collect($step->messages)->last(fn ($message): bool => $message instanceof AssistantMessage);

                return new StepResponse('Continuing: '.$previous?->content, [], FinishReason::Stop, new TextUsage(20, 4), new Meta('openai', 'test'));
            }

            return $step->number === 0
                ? new StepResponse('Searching.', [new ToolCall('search', 'SearchProductsTool', ['query' => 'hoodie'])], FinishReason::ToolCalls, new TextUsage(10, 2), new Meta('openai', 'test'))
                : new StepResult($this->interrupted($step));
        }];
    }

    protected function interrupted(PendingStep $step): Generator
    {
        yield (new StreamStart('start-answer', 'openai', 'test', time()))->withInvocationId($step->invocationId);
        yield (new TextDelta('answer', 'answer', $this->partial, time()))->withInvocationId($step->invocationId);

        throw new RuntimeException('Answer interrupted');
    }
}
