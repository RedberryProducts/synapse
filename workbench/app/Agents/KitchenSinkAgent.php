<?php

namespace Workbench\App\Agents;

use Laravel\Ai\Attributes\UseSmartestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;
use Stringable;
use Workbench\App\Tools\GetWeatherTool;
use Workbench\App\Tools\SearchProductsTool;

/**
 * Many tools of mixed kinds — exercises the `+N` overflow chip, the tier pill
 * (no explicit model), and every branch of the tool classifier.
 */
#[UseSmartestModel]
class KitchenSinkAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You have a lot of tools at your disposal.';
    }

    /**
     * @return Message[]
     */
    public function messages(): iterable
    {
        return [];
    }

    public function tools(): iterable
    {
        return [
            new SearchProductsTool,
            new GetWeatherTool,
            new WebSearch,
            new WeatherAgent,
            new SearchProductsTool,
            new GetWeatherTool,
            new SearchProductsTool,
        ];
    }
}
