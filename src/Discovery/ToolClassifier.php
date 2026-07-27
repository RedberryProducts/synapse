<?php

namespace Redberry\Synapse\Discovery;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\McpTool;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * Classifies the entries returned by an agent's tools().
 *
 * The list is heterogeneous: user tools, provider-native tools, sub-agents, and
 * MCP references. The match below mirrors the SDK gateway's resolveTool() so we
 * never call description()/schema() on a ProviderTool — it implements only
 * HasProviderOptions and has none of those methods.
 */
class ToolClassifier
{
    /**
     * @return array<int, array{name: string, type: string}>
     */
    public function classify(object $agent): array
    {
        if (! $agent instanceof HasTools) {
            return [];
        }

        $tools = [];

        foreach ($agent->tools() as $tool) {
            $tools[] = $this->classifyTool($tool);
        }

        return $tools;
    }

    /**
     * The SDK types tools() as array<Tool|ProviderTool>, but the gateway also
     * accepts Agent instances and MCP references, so the entry is treated as
     * mixed here and matched in the same order resolveTool() uses.
     *
     * @return array{name: string, type: string}
     */
    protected function classifyTool(mixed $tool): array
    {
        return match (true) {
            $tool instanceof Agent => ['name' => (new AgentTool($tool))->name(), 'type' => 'agent'],
            $tool instanceof Tool => ['name' => ToolNameResolver::resolve($tool), 'type' => 'tool'],
            McpTool::supports($tool) => ['name' => (new McpTool($tool))->name(), 'type' => 'mcp'],
            McpServerTool::supports($tool) => ['name' => (new McpServerTool($tool))->name(), 'type' => 'mcp'],
            $tool instanceof ProviderTool => ['name' => class_basename($tool), 'type' => 'provider_tool'],
            is_object($tool) => ['name' => class_basename($tool), 'type' => 'unknown'],
            default => ['name' => 'Unknown', 'type' => 'unknown'],
        };
    }
}
