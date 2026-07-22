<?php

namespace Workbench\App\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
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
 */
#[Provider('openai')]
#[Model('gpt-5.6-luna')]
#[Temperature(0.4)]
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
