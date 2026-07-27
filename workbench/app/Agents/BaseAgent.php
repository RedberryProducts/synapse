<?php

namespace Workbench\App\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Abstract agent — must never appear on the dashboard (not instantiable).
 */
abstract class BaseAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Base instructions.';
    }
}
