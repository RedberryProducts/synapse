<?php

namespace Redberry\Synapse\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Redberry\Synapse\Discovery\AgentDiscovery;
use Redberry\Synapse\Discovery\DiscoveredAgent;

class AgentsController
{
    /**
     * List every discovered agent with its card metadata.
     */
    public function index(AgentDiscovery $discovery): JsonResponse
    {
        return response()->json(
            array_map(
                fn (DiscoveredAgent $agent): array => $agent->toArray(),
                $discovery->all(),
            )
        );
    }
}
