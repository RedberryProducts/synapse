<?php

namespace Workbench\App\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;
use Workbench\App\Tools\SearchProductsTool;

/**
 * A conversational agent carrying `#[Strict]`.
 *
 * `Strict::isAppliedTo()` reads the attribute off the instance with no method
 * fallback, so this fixture is what proves the history decorator preserves it
 * across the wrap rather than silently dropping strict schemas.
 */
#[Provider('openai')]
#[Model('gpt-5.6-luna')]
#[Strict]
class StrictAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You answer with strictly-typed tool arguments.';
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
