<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class RuntimeTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Deterministic slow runtime fixture.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['marker' => $schema->string()->required()];
    }

    public function handle(Request $request): string
    {
        usleep(2000000);

        return 'tool-result:'.$request['marker'];
    }
}
