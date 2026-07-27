<?php

namespace Workbench\App\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * A valid agent used to exercise `synapse.discovery.ignore`.
 */
#[Provider('openai')]
#[Model('gpt-5.6-luna')]
class HiddenAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You should not be listed when ignored.';
    }
}
