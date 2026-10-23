<?php

namespace Redberry\Synapse\Tests\Fixtures;

use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Workbench\App\Tools\SearchProductsTool;

class ApprovalSearchTool extends SearchProductsTool implements Approvable
{
    use InteractsWithApprovals;
}
