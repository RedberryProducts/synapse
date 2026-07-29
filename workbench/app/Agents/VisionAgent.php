<?php

namespace Workbench\App\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * A conversational agent with no tools, for exercising attachments.
 *
 * Conversational on purpose: the interesting case is the *second* turn, where a
 * follow-up question has to still carry the file the first turn attached.
 */
#[Provider('openai')]
#[Model('gpt-5.6-luna')]
class VisionAgent implements Agent, Conversational
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You describe images and documents the user attaches.';
    }

    /**
     * @return Message[]
     */
    public function messages(): iterable
    {
        return [];
    }
}
