<?php

namespace Redberry\Synapse\Chat;

use Closure;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;

class RecordApprovalResults
{
    public function __construct(protected ApprovalContinuation $continuation) {}

    public function handle(PendingStep $step, Closure $next): StepResult
    {
        // Runs before application middleware and the provider request, so a
        // later failure cannot erase already executed approval results.
        $this->continuation->captureMessages($step->messages);

        return $next($step);
    }
}
