<?php

namespace Workbench\App\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;
use Workbench\App\Contracts\UnresolvableDependency;

class IncomeProtectionRecommendationDataCollectorBrokenAgent implements Agent
{
    use Promptable;

    public function __construct(protected UnresolvableDependency $dependency) {}

    public function instructions(): Stringable|string
    {
        return 'Never reachable.';
    }
}
