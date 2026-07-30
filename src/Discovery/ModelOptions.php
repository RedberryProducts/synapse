<?php

namespace Redberry\Synapse\Discovery;

use Laravel\Ai\Ai;
use Throwable;

/**
 * The models the playground offers for a given agent.
 *
 * The agent's own model is always first and always the default: an override is
 * a temporary experiment, and the agent's configuration stays the thing the
 * panel and the composer agree on.
 */
class ModelOptions
{
    /**
     * @return array<int, array{id: string, label: string, tier: string}>
     */
    public function for(DiscoveredAgent $agent): array
    {
        $tiers = $this->providerTiers($agent);
        $options = [];

        // An agent that names no model still has a default — the tier attribute
        // (`#[UseSmartestModel]`) or the provider's own default resolves it at
        // invocation time. Without this the first option in the list would be
        // whatever came next, and the composer would sit there labelled with a
        // model the agent is not going to use.
        $default = $agent->model ?? ($tiers[$agent->modelTier] ?? $tiers['default'] ?? null);

        if ($default !== null) {
            $options[] = $this->option($default, 'agent');
        }

        foreach ($tiers as $tier => $model) {
            if ($tier !== 'default') {
                $options[] = $this->option($model, $tier);
            }
        }

        foreach ((array) config('synapse.playground.models', []) as $model) {
            if (is_string($model) && $model !== '') {
                $options[] = $this->option($model, 'configured');
            }
        }

        // An agent already running the cheapest model should see one entry, not
        // the same model twice under different labels.
        return array_values(array_reduce(
            $options,
            function (array $unique, array $option): array {
                $unique[$option['id']] ??= $option;

                return $unique;
            },
            [],
        ));
    }

    /**
     * The provider's cheapest and smartest text models.
     *
     * Resolving a provider can throw for a driver the host app has not
     * configured. The composer degrading to the agent's own model is a far
     * better outcome than the playground refusing to render.
     *
     * @return array<string, string>
     */
    protected function providerTiers(DiscoveredAgent $agent): array
    {
        try {
            $provider = Ai::textProvider($agent->provider);

            return [
                'cheapest' => $provider->cheapestTextModel(),
                'smartest' => $provider->smartestTextModel(),
                // Not offered as a choice — only used to resolve the default of
                // an agent that names neither a model nor a tier.
                'default' => $provider->defaultTextModel(),
            ];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{id: string, label: string, tier: string}
     */
    protected function option(string $model, string $tier): array
    {
        return ['id' => $model, 'label' => $model, 'tier' => $tier];
    }
}
