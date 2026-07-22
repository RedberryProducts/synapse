<?php

namespace Workbench\App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetWeatherTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Get the current weather for a city.';
    }

    public function handle(Request $request): Stringable|string
    {
        $city = (string) ($request['city'] ?? 'unknown');

        return json_encode(['city' => $city, 'temp_c' => 21, 'conditions' => 'clear']);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string()->description('City name')->required(),
        ];
    }
}
