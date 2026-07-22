<?php

namespace Workbench\App\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;
use Stringable;

/**
 * A conversational agent that declares a provider-native tool — exercises the
 * ⚡ provider-tool card.
 */
#[Provider('openai')]
#[Model('gpt-5.6-luna')]
class ResearchAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You are a research assistant. Use web search to find up-to-date information.';
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
            new WebSearch(maxSearches: 5),
        ];
    }
}
