<?php

namespace Redberry\Synapse\Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class StringableResultTool implements Tool
{
    public function description(): string
    {
        return 'Return a custom stringable inventory result.';
    }

    public function handle(Request $request): Stringable
    {
        return new class implements Stringable
        {
            public string $stock = '12';

            public function __toString(): string
            {
                return "Hoodie stock: {$this->stock}.";
            }
        };
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
