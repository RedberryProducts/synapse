<?php

namespace Workbench\App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * A tool that takes long enough to watch.
 *
 * Every other tool fixture returns in well under a millisecond, which makes the
 * `pending` state unobservable: Synapse writes `tool-input-available` and
 * `tool-output-available` microseconds apart, they land in the same TCP segment,
 * and the browser applies both in one tick — the card renders already resolved.
 *
 * That is fine for a `json_encode`, but the tool inspector exists for tools that
 * call an API or hit a database, where the developer stares at the thread for
 * seconds. This fixture is the only way to prove they see a spinner naming the
 * tool rather than a blank thread.
 */
class SlowTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Query the slow analytics service. Takes a few seconds to respond.';
    }

    public function handle(Request $request): Stringable|string
    {
        // Clamped so a hallucinated argument cannot hang the playground: the
        // point is a window long enough to see, not an arbitrary sleep.
        $seconds = min(5.0, max(0.0, (float) ($request['seconds'] ?? 3)));

        usleep((int) round($seconds * 1_000_000));

        return json_encode(['waited_seconds' => $seconds, 'rows' => 42]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'seconds' => $schema->number()->description('How long the query should take, 0-5'),
        ];
    }
}
