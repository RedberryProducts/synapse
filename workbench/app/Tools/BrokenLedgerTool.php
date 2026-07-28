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
 * The SDK does not catch exceptions from `handle()` — `InvokesTools::executeTool()`
 * uses `try/finally` with no `catch` — so this exits `stream()` entirely. It is
 * the fixture behind Synapse's invocation-level catch-all.
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
