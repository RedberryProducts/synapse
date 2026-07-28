<?php

namespace Workbench\App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;

/**
 * A tool whose schema() throws — the Info panel must degrade this one tool
 * rather than blanking the whole panel.
 */
class BrokenSchemaTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Its schema cannot be built.';
    }

    public function handle(Request $request): Stringable|string
    {
        return '';
    }

    public function schema(JsonSchema $schema): array
    {
        throw new RuntimeException('Schema could not be built.');
    }
}
