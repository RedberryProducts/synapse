<?php

namespace Workbench\App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchProductsTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Search the product catalog for matching items.';
    }

    public function handle(Request $request): Stringable|string
    {
        $query = (string) ($request['query'] ?? '');

        return json_encode([
            ['id' => 42, 'name' => "Sony WH-1000 ({$query})"],
            ['id' => 87, 'name' => 'AirPods Max'],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Search query text')->required(),
            'max_results' => $schema->integer()->description('Maximum results to return'),
        ];
    }
}
