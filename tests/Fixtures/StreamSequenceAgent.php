<?php

namespace Redberry\Synapse\Tests\Fixtures;

use Generator;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Workbench\App\Agents\SupportAgent;

class StreamSequenceAgent extends SupportAgent implements HasMiddleware
{
    /** @param list<StreamEvent> $events */
    public function __construct(public array $events, public ?StepResponse $response = null) {}

    public function middleware(): array
    {
        return [fn (PendingStep $step): StepResult => new StepResult($this->events($step))];
    }

    /** @return Generator<int, StreamEvent, mixed, StepResponse|null> */
    protected function events(PendingStep $step): Generator
    {
        foreach ($this->events as $event) {
            yield $event->withInvocationId($step->invocationId);
        }

        return $this->response;
    }
}
