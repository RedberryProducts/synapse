<?php

namespace Redberry\Synapse\Discovery;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\McpTool;
use Throwable;

/**
 * Full detail for each of an agent's tools: description and parameters.
 *
 * Epic 1's ToolClassifier answers "what kind is this?"; this adds the detail the
 * Info panel needs, per kind. A ProviderTool has no description()/schema() at
 * all, so kind is always decided before any accessor is touched.
 */
class ToolDetail
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(object $agent, ?string $provider = null): array
    {
        if (! $agent instanceof HasTools) {
            return [];
        }

        $tools = [];

        foreach ($agent->tools() as $tool) {
            $tools[] = $this->detail($tool, $provider);
        }

        return $tools;
    }

    /**
     * The agent's structured output schema, when it declares one.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function outputSchema(object $agent): ?array
    {
        if (! $agent instanceof HasStructuredOutput) {
            return null;
        }

        try {
            return $this->parameters($agent->schema(new JsonSchemaTypeFactory));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function detail(mixed $tool, ?string $provider): array
    {
        return match (true) {
            $tool instanceof Agent => $this->agentTool($tool),
            $tool instanceof Tool => $this->userTool($tool, 'tool'),
            McpTool::supports($tool) => $this->userTool(new McpTool($tool), 'mcp'),
            McpServerTool::supports($tool) => $this->userTool(new McpServerTool($tool), 'mcp'),
            $tool instanceof ProviderTool => $this->providerTool($tool, $provider),
            default => $this->base(is_object($tool) ? class_basename($tool) : 'Unknown', 'unknown'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function userTool(Tool $tool, string $type): array
    {
        $detail = $this->base($this->name($tool), $type);

        try {
            $detail['description'] = (string) $tool->description();
        } catch (Throwable $e) {
            $detail['schema_error'] = $e->getMessage();
        }

        try {
            $detail['parameters'] = $this->parameters($tool->schema(new JsonSchemaTypeFactory));
        } catch (Throwable $e) {
            // One malformed tool must not blank the whole panel.
            $detail['schema_error'] = $e->getMessage();
        }

        return $detail;
    }

    /**
     * @return array<string, mixed>
     */
    protected function agentTool(Agent $agent): array
    {
        $tool = new AgentTool($agent);

        return array_merge($this->base($tool->name(), 'agent'), [
            'description' => (string) $tool->description(),
            'agent_slug' => AgentSlug::make($agent::class),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function providerTool(ProviderTool $tool, ?string $provider): array
    {
        // ProviderTool implements HasProviderOptions by definition, so options
        // are always available — but never description() or schema().
        $options = [];

        if ($provider !== null) {
            try {
                $options = $tool->providerOptions($provider);
            } catch (Throwable) {
                $options = [];
            }
        }

        return array_merge($this->base(class_basename($tool), 'provider_tool'), [
            'provider_options' => $options,
        ]);
    }

    /**
     * Flatten a tool's schema into displayable parameter rows.
     *
     * Goes through the SDK's ObjectSchema (the same path the provider gateways
     * use) because Type::toArray() deliberately strips `required` — JSON Schema
     * records it on the parent object, not the property.
     *
     * @param  array<string, mixed>  $schema
     * @return array<int, array<string, mixed>>
     */
    protected function parameters(array $schema): array
    {
        if ($schema === []) {
            return [];
        }

        $json = (new ObjectSchema($schema))->toSchema();
        $required = $json['required'] ?? [];
        $parameters = [];

        foreach ($json['properties'] ?? [] as $name => $property) {
            $type = $property['type'] ?? null;

            $parameters[] = [
                'name' => (string) $name,
                'type' => is_array($type) ? implode(' | ', $type) : (string) ($type ?? 'mixed'),
                'description' => $property['description'] ?? null,
                'required' => in_array((string) $name, $required, true),
            ];
        }

        return $parameters;
    }

    protected function name(Tool $tool): string
    {
        return is_callable([$tool, 'name']) ? $tool->name() : class_basename($tool);
    }

    /**
     * @return array<string, mixed>
     */
    protected function base(string $name, string $type): array
    {
        return [
            'name' => $name,
            'type' => $type,
            'description' => null,
            'parameters' => [],
            'provider_options' => null,
            'agent_slug' => null,
            'schema_error' => null,
        ];
    }
}
