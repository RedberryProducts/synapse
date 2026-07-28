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
use Workbench\App\Tools\BrokenLedgerTool;

/**
 * A conversational agent whose only tool throws — exercises the invocation-level
 * catch-all, the inline error card, and the dangling-`pending` sweep.
 */
#[Provider('openai')]
#[Model('gpt-5.6-luna')]
class FlakyToolAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You look up ledger entries for the finance team.';
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
            new BrokenLedgerTool,
        ];
    }
}
