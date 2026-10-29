<?php

namespace Redberry\Synapse\Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;

class StructuredStepSequenceAgent extends StepSequenceAgent implements HasStructuredOutput
{
    public function schema(JsonSchema $schema): array
    {
        return ['answer' => $schema->string()->required()];
    }
}
