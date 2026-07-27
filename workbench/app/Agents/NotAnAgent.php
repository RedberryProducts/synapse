<?php

namespace Workbench\App\Agents;

/**
 * Lives in the agents directory but implements nothing — must be ignored.
 */
class NotAnAgent
{
    public function handle(): string
    {
        return 'not an agent';
    }
}
