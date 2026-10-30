# Runtime qualification

These fixtures test Synapse over real HTTP. They are separate from Pest because
its in-process response capture cannot prove incremental streaming.

## Repeat the check

1. Create a disposable Laravel application. Install the Laravel AI SDK and the
   Synapse release or candidate archive being qualified, then run
   `php artisan synapse:install`. Use SQLite, file sessions/cache, and
   `APP_ENV=local`. No provider credentials are needed.
2. Copy this directory's `app/Ai` into the application's `app/Ai`. Add the
   contents of `routes.php` to its web routes. These are test-only fixtures;
   do not install them in a real application's deployed routes.
3. Serve the app using the runtime under test, bound to loopback. For Octane,
   install its current supported server dependencies and start with one worker
   and at least 1,000 requests before recycling. Restart workers after changing
   the package or fixtures. Wait until `/runtime-info` responds before testing.
4. Run from the Synapse checkout with Python 3 (standard library only):

   ```bash
   mkdir -p build/runtime-results
   python3 tests/Runtime/check.py http://127.0.0.1:8000 runtime-name \
       --output build/runtime-results/runtime-name.json
   ```

   For RoadRunner's intentionally buffered result, add `--buffered`. This checks
   that both the observed delivery and dashboard capability flag report
   buffering; do not use it to excuse a streaming regression on another runtime.

5. Record the package versions, server versions/configuration, JSON results,
   and any limitations in the implementation or release PR. Stop the disposable
   servers when finished. Do not infer support for another Octane engine or
   hosting/proxy configuration from a passing run.

The fixtures supply public SDK step responses through middleware and block
stray Laravel HTTP requests. The actual SDK tool and approval loops execute.
The local tool waits two seconds; the answer sends one chunk, waits one second,
then sends its final chunk. Unique markers distinguish successive requests.

The check covers dashboard/discovery, one correctly correlated tool execution,
two model steps, citations, saved conversation replay, a partial failure followed
by a clean conversation, and approval/denial after reloading the paused turn.
It reads HTTP chunks directly and timestamps complete SSE events. Passing
streaming requires a tool-start event before one second, a tool-result gap over
1.5 seconds, and an answer-to-completion gap over 0.7 seconds. The JSON retains
all event timings; response headers arriving early are insufficient.

For Octane, confirm the before/after worker PID matches when running with a
single worker. This exercises multiple requests in one worker; it is not a
concurrent-load or memory-soak qualification.

## Qualified configurations — 2026-10-30

Laravel **13.39.0**, Laravel AI **1.3.0**, and Synapse **0.3.0** were installed
from Composer distribution archives. All non-Octane rows passed on the released
package. Octane rows require the 0.3.1 active-request recording fix;
Swoole also requires the corrected dashboard streaming flag. The complete
matrix was rerun with the 0.3.1 candidate installed through a Composer artifact
archive, including the committed dashboard assets. No Synapse-specific Octane
configuration was added.

| Server | PHP | Configuration | Result |
|---|---|---|---|
| Artisan development server | 8.4.27 | `output_buffering=4096` | Incremental SSE; all scenarios pass |
| nginx 1.30.4 + PHP-FPM | 8.4.24 | FastCGI buffering on; Synapse's `X-Accel-Buffering: no` honored; `output_buffering=4096` | Incremental SSE; all scenarios pass |
| Sail runtime image 8.4; Sail package 1.69.0 | 8.4.16 | Default Supervisor/`artisan serve` command; SQLite; `output_buffering=4096` | Incremental SSE; all scenarios pass |
| FrankenPHP 1.12.6 / Caddy 2.11.4 | 8.5.9 | Classic `php-server`; `output_buffering=4096` | Incremental SSE; all scenarios pass |
| Octane 2.22.0 + FrankenPHP 1.13.4 / Caddy 2.11.7 | 8.5.12 | One worker, 1,000-request limit | Incremental SSE; all scenarios pass after fixes |
| Octane 2.22.0 + Swoole 6.1.4 | 8.4.16 | One worker, no task workers, 1,000-request limit | Incremental SSE; all scenarios pass after fixes |
| Octane 2.22.0 + RoadRunner 3.0.1 | 8.4.16 | One worker, 1,000-request limit | All conversation scenarios pass after fixes; SSE arrives together at completion |

These were direct HTTP/1.1 checks on macOS and Linux ARM64 containers, without
an additional CDN or compression proxy. Herd, Valet, Forge deployments,
Open Swoole, Apache/mod_php, and arbitrary proxy configurations are not covered
by this matrix. Qualify the actual deployment before extending these claims.

## Why the Octane results differ

Octane retains the boot-time event dispatcher while resolving requests in a
cloned application container. Synapse's class-based SDK listeners previously
resolved the recording scope in the original container, missing the active
invocation. Resolving the scope at event delivery fixes the observed missing
invocation IDs and failed tool persistence.

FrankenPHP uses its own web SAPI. Swoole uses CLI but forwards echoed output
through its response buffer callback; PHP's native flush calls are unnecessary.
RoadRunner's Octane adapter only takes its incremental response path for
callbacks with a generator/string return type. Synapse's current callback
writes output and returns void, so RoadRunner buffers it. Adding RoadRunner
streaming requires a separate response-adapter change, not merely removing the
dashboard notice or calling `flush()`.

References: [Octane worker lifecycle](https://github.com/laravel/octane/blob/v2.22.0/src/Worker.php),
[Swoole response handling](https://github.com/laravel/octane/blob/v2.22.0/src/Swoole/SwooleClient.php),
[RoadRunner response handling](https://github.com/laravel/octane/blob/v2.22.0/src/RoadRunner/RoadRunnerClient.php).
