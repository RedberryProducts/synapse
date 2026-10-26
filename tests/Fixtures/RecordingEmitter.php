<?php

namespace Redberry\Synapse\Tests\Fixtures;

use Laravel\Ai\Streaming\Events\StreamEvent;
use Redberry\Synapse\Chat\StreamEmitter;

/** Captures real SDK events while the wire serializer is migrated separately. */
class RecordingEmitter extends StreamEmitter
{
    /** @var list<StreamEvent> */
    public array $events = [];

    public function __construct()
    {
        parent::__construct(echo: false);
    }

    public function event(StreamEvent $event): void
    {
        $this->events[] = $event;
    }
}
