<?php

namespace Workbench\App\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;
use Workbench\App\Tools\SlowTool;

/**
 * A conversational agent whose only tool takes seconds to return.
 *
 * Kept separate from the other tool agents so nothing else pays the sleep — this
 * one exists purely to hold the `pending` tool card open long enough to assert
 * on, and to look at by hand.
 */
#[Provider('openai')]
#[Model('gpt-5.6-luna')]
class SlowToolAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You answer questions using the slow analytics service.';
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
            new SlowTool,
        ];
    }
}
