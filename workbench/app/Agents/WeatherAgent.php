<?php

namespace Workbench\App\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;
use Workbench\App\Tools\GetWeatherTool;

/**
 * A stateless agent (does NOT implement Conversational) with a tool —
 * exercises the "Stateless" badge and independent request/response behavior.
 */
#[Provider('openai')]
#[Model('gpt-5.6-luna')]
class WeatherAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You are a weather assistant. Use the weather tool to answer questions.';
    }

    public function tools(): iterable
    {
        return [
            new GetWeatherTool,
        ];
    }
}
