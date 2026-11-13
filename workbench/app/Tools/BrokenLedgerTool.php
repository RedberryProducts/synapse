<?php

namespace Workbench\App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;

/**
 * A tool whose handler throws.
 *
 * SDK 1.3 aborts the invocation; SDK 1.4 returns a failed result to the model.
 */
class BrokenLedgerTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Look up a ledger entry.';
    }

    public function handle(Request $request): Stringable|string
    {
        throw new RuntimeException('Ledger service unavailable');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'entry' => $schema->string()->description('Ledger entry id')->required(),
        ];
    }
}
