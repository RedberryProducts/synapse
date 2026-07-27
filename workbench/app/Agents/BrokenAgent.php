<?php

namespace Workbench\App\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;
use Workbench\App\Contracts\UnresolvableDependency;

/**
 * Declares a dependency the container cannot build — must be reported as
 * unavailable rather than breaking discovery.
 */
class BrokenAgent implements Agent
{
    use Promptable;

    public function __construct(protected UnresolvableDependency $dependency) {}

    public function instructions(): Stringable|string
    {
        return 'Never reachable.';
    }
}
