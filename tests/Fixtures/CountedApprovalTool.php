<?php

namespace Redberry\Synapse\Tests\Fixtures;

use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Tools\Request;
use Stringable;

class CountedApprovalTool extends ApprovalSearchTool
{
    public int $executions = 0;

    public function handle(Request $request): Stringable|string
    {
        $this->executions++;

        return 'Approved result '.$this->executions;
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        return $request['query'] !== 'public';
    }
}
