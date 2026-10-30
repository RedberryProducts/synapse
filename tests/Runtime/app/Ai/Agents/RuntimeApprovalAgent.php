<?php

namespace App\Ai\Agents;

use App\Ai\Tools\RuntimeTool;

class RuntimeApprovalAgent extends RuntimeAgent
{
    public function tools(): iterable
    {
        return [(new RuntimeTool)->requireApproval('Approve the deterministic runtime tool?')];
    }
}
