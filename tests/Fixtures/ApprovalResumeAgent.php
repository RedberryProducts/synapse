<?php

namespace Redberry\Synapse\Tests\Fixtures;

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Redberry\Synapse\Chat\SynapseConversationalAgent;

/** UI fixture only: real approval execution is covered by HTTP-faked feature tests. */
class ApprovalResumeAgent extends StreamSequenceAgent
{
    /** @param list<ToolResult> $results */
    public function __construct(array $results, string $text = 'Continued after your decision.', ?TextUsage $usage = null)
    {
        // The SDK fake bypasses decisions and otherwise marks the pending
        // calls abandoned. This fixture supplies their public result events.
        Event::listen(StreamingAgent::class, function (StreamingAgent $event): void {
            $agent = $event->prompt->agent;
            if ($agent instanceof SynapseConversationalAgent && $agent->wrapped() === $this) {
                $last = collect($agent->messages())->whereInstanceOf(AssistantMessage::class)->last();
                $last?->toolCalls->splice(0);
            }
        });
        parent::__construct([
            ...array_map(fn (ToolResult $result): ToolResultEvent => new ToolResultEvent(
                'result-'.$result->id, $result, $result->successful(), $result->error(), time(), denied: $result->denied,
            ), $results),
            new TextDelta('answer', 'answer', $text, time()),
        ], new StepResponse($text, [], FinishReason::Stop, $usage ?? new TextUsage(20, 5), new Meta('openai', 'test')));
    }
}
