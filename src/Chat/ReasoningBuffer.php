<?php

namespace Redberry\Synapse\Chat;

/**
 * Collects a run's reasoning as it streams past.
 *
 * Reasoning has no home in the SDK's response: `StreamedAgentResponse->text` is
 * `TextDelta::combine()`, which excludes it, and nothing else retains the
 * deltas. If Synapse does not gather them while they go by, a replayed
 * conversation quietly loses the thinking the developer watched.
 *
 * A small object rather than a captured string because the completion callback
 * that persists the turn is registered before the stream is iterated — the
 * accumulation happens between the two, and passing state through an object
 * makes that ordering explicit instead of relying on a by-reference capture.
 */
class ReasoningBuffer
{
    protected string $text = '';

    public function append(string $delta): void
    {
        $this->text .= $delta;
    }

    /**
     * The reasoning so far, or null when the model did none.
     */
    public function text(): ?string
    {
        return $this->text === '' ? null : $this->text;
    }
}
