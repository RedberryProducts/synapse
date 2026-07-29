<?php

namespace Redberry\Synapse\Discovery;

use Laravel\Ai\Contracts\HasMiddleware;
use Throwable;

/**
 * Builds the full Info-panel payload for a single agent.
 *
 * Every section is resolved defensively: a misbehaving tool or middleware list
 * degrades that section rather than blanking the panel.
 */
class AgentDetail
{
    public function __construct(
        protected GenerationOptions $generation,
        protected ToolDetail $tools,
        protected ModelOptions $models,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(DiscoveredAgent $discovered): array
    {
        $payload = $discovered->toArray();

        if (! $discovered->available) {
            return array_merge($payload, $this->emptyDetail());
        }

        try {
            $agent = app($discovered->class);
        } catch (Throwable) {
            return array_merge($payload, $this->emptyDetail());
        }

        // array_merge (not +) so the detailed tool list replaces the summary one
        // that DiscoveredAgent::toArray() already provides.
        return array_merge($payload, [
            'instructions' => $this->instructions($agent),
            'generation' => $this->generation->for($agent),
            'provider_options' => $this->generation->providerOptions($agent, $discovered->provider),
            'middleware' => $this->middleware($agent),
            'tools' => $this->tools->all($agent, $discovered->provider),
            'output_schema' => $this->tools->outputSchema($agent),
            'models' => $this->models->for($discovered),
        ]);
    }

    protected function instructions(object $agent): ?string
    {
        try {
            return (string) $agent->instructions();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Middleware entries may be class strings or instances.
     *
     * @return array<int, string>
     */
    protected function middleware(object $agent): array
    {
        if (! $agent instanceof HasMiddleware) {
            return [];
        }

        try {
            $middleware = $agent->middleware();
        } catch (Throwable) {
            return [];
        }

        $names = [];

        foreach ($middleware as $entry) {
            $names[] = is_object($entry) ? $entry::class : (string) $entry;
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyDetail(): array
    {
        return [
            'instructions' => null,
            'generation' => null,
            'provider_options' => [],
            'middleware' => [],
            'tools' => [],
            'output_schema' => null,
            // An agent that cannot be constructed cannot be talked to either,
            // so there is nothing to offer a model selector.
            'models' => [],
        ];
    }
}
