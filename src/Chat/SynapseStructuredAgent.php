<?php

namespace Redberry\Synapse\Chat;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Messages\Message;

class SynapseStructuredAgent extends SynapseConversationalAgent implements HasStructuredOutput
{
    /** @param Message[] $history */
    public function __construct(protected Agent&HasStructuredOutput $structuredAgent, array $history = [])
    {
        parent::__construct($structuredAgent, $history);
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->structuredAgent->schema($schema);
    }
}
