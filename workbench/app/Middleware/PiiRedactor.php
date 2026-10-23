<?php

namespace Workbench\App\Middleware;

use Closure;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;

class PiiRedactor
{
    public function handle(PendingStep $step, Closure $next): StepResult
    {
        return $next($step->withInstructions(
            $step->instructions."\nNever include personal email addresses in your answer.",
        ));
    }
}
