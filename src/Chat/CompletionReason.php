<?php

namespace Redberry\Synapse\Chat;

use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Redberry\Synapse\Models\SynapseMessage;

class CompletionReason
{
    public static function forResponse(AgentResponse $response): ?FinishReason
    {
        if ($response instanceof StreamedAgentResponse) {
            $end = $response->events->whereInstanceOf(StreamEnd::class)
                ->where('invocationId', $response->invocationId)->last();

            return $end instanceof StreamEnd ? self::parse($end->reason) : null;
        }

        return $response->steps->last()?->finishReason;
    }

    public static function forMessage(SynapseMessage $message): ?FinishReason
    {
        if (array_key_exists('finish_reason', $message->meta ?? [])) {
            return self::parse($message->meta['finish_reason']);
        }

        $step = last($message->steps ?? []);

        return self::parse(is_array($step) ? ($step['finish_reason'] ?? null) : null);
    }

    protected static function parse(mixed $reason): ?FinishReason
    {
        return is_string($reason) ? FinishReason::tryFrom($reason) : null;
    }
}
