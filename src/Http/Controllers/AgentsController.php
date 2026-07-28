<?php

namespace Redberry\Synapse\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Redberry\Synapse\Discovery\AgentDetail;
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

    /**
     * Full detail for one agent: config, prompt, tools, middleware.
     */
    public function show(string $agent, AgentDiscovery $discovery, AgentDetail $detail): JsonResponse
    {
        $discovered = $discovery->find($agent);

        abort_if($discovered === null, 404, 'Agent not found.');

        return response()->json($detail->for($discovered));
    }
}
