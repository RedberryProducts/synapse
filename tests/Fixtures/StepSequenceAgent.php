<?php

namespace Redberry\Synapse\Tests\Fixtures;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;
use Workbench\App\Agents\SupportAgent;

/** SDK fakes discard TextResponse tool calls; step middleware can supply them. */
#[MaxSteps(6)]
class StepSequenceAgent extends SupportAgent implements HasMiddleware
{
    /** @param list<StepResponse|StepResult> $steps */
    public function __construct(public array $steps) {}

    public function middleware(): array
    {
        return [fn (PendingStep $step): StepResponse|StepResult => $this->steps[$step->number]];
    }
}
