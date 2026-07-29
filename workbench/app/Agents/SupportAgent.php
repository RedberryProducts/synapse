<?php

namespace Workbench\App\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;
use Workbench\App\Tools\SearchProductsTool;

/**
 * A conversational agent with a user-defined tool — exercises multi-turn
 * history and inline tool cards.
 *
 * Deliberately sets no temperature: this is the fixture used for hands-on
 * testing against a real provider, and several current models reject the
 * parameter outright. ConfiguredAgent is where every generation option lives.
 */
#[Provider('openai')]
#[Model('gpt-5.6-luna')]
#[MaxTokens(4096)]
#[MaxSteps(6)]
class SupportAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You are a friendly customer support agent. Use the product search tool to answer questions about the catalog.';
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
        ];
    }
}
