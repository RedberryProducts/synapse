<?php

namespace Workbench\App\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * A structured-output agent — exercises the JSON response card.
 */
#[Provider('openai')]
#[Model('gpt-5.6-luna')]
class ExtractorAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Extract structured contact details from the given text.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Full name')->required(),
            'email' => $schema->string()->description('Email address'),
            'company' => $schema->string()->description('Company name'),
        ];
    }
}
