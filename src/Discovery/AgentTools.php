<?php

namespace Redberry\Synapse\Discovery;

use Laravel\Ai\Contracts\HasSkills;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Tools\LoadSkill;

class AgentTools
{
    /** @return array<int, mixed> */
    public function for(object $agent): array
    {
        $tools = $agent instanceof HasTools ? [...$agent->tools()] : [];

        return $agent instanceof HasSkills ? LoadSkill::mergeInto($tools, $agent) : $tools;
    }
}
