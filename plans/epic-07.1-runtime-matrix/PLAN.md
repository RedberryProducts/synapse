# Epic 7.1 — Runtime Matrix

**Goal:** Synapse streams on every runtime we claim to support, we can prove it with a number, and where it doesn't stream it says so out loud instead of looking like a hang.

Delivers PRD [Feature 2](../../PRD.md#feature-2-chat-playground) (the streaming half) · GOAL [Chat playground](../../GOAL.md#chat-playground) · GOAL [Compatibility](../../GOAL.md#compatibility) · Success Criterion **#3** (watch a response stream token by token).

**Depends on:** `856b591` — the `StreamEmitter::shouldFlush()` fix.
**Blocks:** 7.4 (release).

---

## Why this exists

Synapse never streamed. From Epic 3 through Epic 6 the emitter guarded its flush
on `headers_sent()`, which is false under the stock `php.ini`
(`output_buffering=4096`): the first `echo` lands in PHP's own buffer, so the
headers are never sent, so the guard answered "no" — for every part, of every
run. Measured against a real server, time-to-first-byte **equalled** total time.
The dashboard waited out the agent and then painted the entire conversation at
once.

Nothing in the suite could catch it, and nothing can today:

| Tier | Runs Laravel | SAPI | Body |
|------|--------------|------|------|
| Feature | in-process | `cli` | `streamedContent()` collects it |
| Browser | in-process | `cli` | `ob_start()` → `sendContent()` → `ob_get_clean()` |

Both read the body back out of their own output buffer, so **no test ever
exercises a real SAPI** — and flushing is deliberately off under CLI, because
flushing there would push bytes past the harness's buffer to stdout.

The fix is committed and `StreamFlushTest` locks the *decision* in. What is still
unproven is the *behaviour*, on any runtime other than `artisan serve`. That is
this epic.

**The principle this serves:** it's a debugging tool — surface the truth as much
as it doesn't break UX. A dashboard that silently degrades to "hang, then paint"
is the exact opposite.

---

## Decisions

Confirmed before planning:

1. **Octane is not supported in v0.1.0, and says so.** This is MVP; we are not
   chasing every deployment target. Octane (Swoole / RoadRunner) runs under the
   **CLI SAPI**, so `shouldFlush()` returns false and Synapse will not stream
   there — which is the *safe* answer, since `echo`/`flush()` under Swoole
   wouldn't reach the client anyway and could corrupt the response. The gap is
   that it currently fails silently. 7.1 makes it explicit; a later release adds
   a maintained Octane pipeline.
2. **The matrix is a committed script, not a checklist.** A procedure nobody can
   re-run is a procedure that rots. `bin/check-streaming.sh` measures TTFB
   against a running server and prints pass/fail, so any contributor — and every
   future release — can re-verify in one command.
3. **A runtime that cannot stream gets a visible notice, not a silent
   degradation.** One line in the playground, from a capability the backend
   reports. Being told "this runtime buffers responses" costs a developer ten
   seconds; discovering it themselves cost us six epics.

---

## Scope

**In**

- `bin/check-streaming.sh` — measures TTFB vs total against a running server, exits non-zero when they converge
- Measured verification on: `artisan serve`, Herd/Valet (nginx + php-fpm), Sail (nginx + php-fpm in Docker), FrankenPHP
- Runtime capability detection + a playground notice when streaming is unavailable
- `X-Accel-Buffering` / `Cache-Control: no-transform` confirmed to survive each proxy (both are already sent)
- The support table, authored here, published in 7.3

**Out**

- **Octane support** — explicitly unsupported for v0.1.0 (Decision 1); a maintained pipeline comes later
- Apache + `mod_php` — not a target we claim; the matrix is written so adding a row is cheap
- CDN / Cloudflare in front of a dev dashboard — out of the product's stated use
- Any change to the protocol or to what is streamed; this epic only proves delivery

---

## Design

No new UI beyond one notice line, built from the existing `StatelessNotice`
pattern (`Badge` + icon + copy) in the playground header. No Figma component
exists for it; it reuses `agent-missing-notice`'s shape and tone.

---

## Frontend components to use

### 1. Elements (`resources/js/elements/`)

| Element | Status | Figma | Used by | Notes |
|---------|--------|-------|---------|-------|
| `Badge` | Done | `pill` variant | `StreamingNotice` | Same shape as the orphaned-agent notice |

### 2. Components (`resources/js/components/`)

| Component | Status | Figma | Responsibility |
|-----------|--------|-------|----------------|
| `StreamingNotice` | Create | — | "This runtime buffers responses — replies appear all at once." Rendered only when the backend reports streaming unavailable |
| `StatelessNotice` | Done | — | Reference for tone and markup |

### 3. Composed (`resources/js/composed/`)

| Component | Status | Figma | Responsibility |
|-----------|--------|-------|----------------|
| `PlaygroundShell` | Adjust | `355:8263` family | Render `StreamingNotice` beside the Stateless badge when `capabilities.streaming === false` |

### 4. Pages (`resources/js/pages/`)

| Page | Status | Responsibility |
|------|--------|----------------|
| `Playground` | Done | No change — the flag arrives on the agent detail payload it already fetches |

### Data layer

| File | Status | Responsibility |
|------|--------|----------------|
| `resources/js/types/agent.ts` | Adjust | Add `streaming: boolean` to the capabilities shape |

### Styling

None. The notice reuses existing tokens.

---

## Configuration

This epic introduces **no new config keys**.

| Key | Default | Use here |
|-----|---------|----------|
| `synapse.ui.path` | `synapse` | The script needs the dashboard path to build its URL |

---

## Technical approach

### 1. The flush decision (already shipped, restated so the matrix has a spec)

```php
public function shouldFlush(): bool
{
    return ! in_array($this->sapi, ['cli', 'phpdbg', 'embed'], true);
}
```

Guarded on `PHP_SAPI`, never on `headers_sent()`, matching Symfony's
`Response::send()`. Laravel's own `eventStream()` does the plain
`ob_flush(); flush();` with no `headers_sent()` check — it is the reference
implementation.

**Trap:** Octane reports `cli`. That is why Decision 1 is a documentation
decision and not a code decision — the guard is already correct for Octane; it
just needs to be honest about it.

### 2. Measuring

TTFB is the whole test. If `time_starttransfer ≈ time_total`, nothing streamed:

```bash
curl -sN -o /dev/null -X POST "$BASE/api/chat/$AGENT/send" \
  -b "$COOKIES" -H "X-XSRF-TOKEN: $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"message":"Query the analytics service with seconds=4."}' \
  -w 'ttfb=%{time_starttransfer} total=%{time_total}\n'
```

Reference numbers already measured on `artisan serve`:

| | TTFB | Total |
|---|---|---|
| Before the fix | 6.710s | 6.714s |
| After the fix | **0.023s** | 7.915s |

**Trap:** curl piped into another process block-buffers by default and will make
a healthy stream look batched — the first two attempts at this measurement were
both harness artifacts, not server behaviour. `-N` plus `os.read()` on the raw fd,
or `-w` with no pipe at all, are the only forms that tell the truth.

### 3. `SlowTool` is the instrument

Every other tool fixture returns in microseconds, so its `tool-input-available`
and `tool-output-available` land in the same TCP segment and the card renders
already green. `SlowTool` sleeps a requested 0–5s, holding the pending window
open. Measured end-to-end after the fix:

```
   0ms  data-synapse-start
1276ms  tool-input-available
5438ms  tool-output-available     ← 4.16s pending window, matching the 4s sleep
6575ms  text-start … 13 deltas through 6729ms
```

And in the browser DOM: `pending` at 11551ms → `success` at 15695ms, a **4.14s
visible amber window**.

### 4. Capability detection

```php
// Synapse::streams(): the same question shouldFlush() asks, exposed to the UI.
// Octane reports the CLI SAPI, which is why this is a capability rather than
// a version check.
```

Reported on the agent detail payload the playground already fetches, so no new
endpoint and no extra request.

### 5. Proxy headers

Already sent and confirmed present in the response: `X-Accel-Buffering: no`
(nginx), `Cache-Control: no-cache, no-transform` (proxies that would otherwise
re-chunk). The matrix confirms they survive each hop rather than assuming it.

---

## API

`GET /synapse/api/agents/{agent}` gains one field:

```jsonc
{
  "capabilities": {
    "conversational": true,
    "structured_output": false,
    "streaming": true      // false under CLI/Octane — the UI shows a notice
  }
}
```

---

## Acceptance criteria

1. `bin/check-streaming.sh` measures TTFB against a running dashboard and exits non-zero when TTFB and total converge.
2. Running it against `artisan serve` passes, with TTFB under 100ms.
3. Running it against Herd/Valet (nginx + php-fpm) passes.
4. Running it against Sail (nginx + php-fpm in Docker) passes.
5. Running it against FrankenPHP passes, or the runtime is recorded as unsupported with the measured evidence.
6. Sending a message on a passing runtime shows text arriving incrementally, not in one paint.
7. A tool taking four seconds shows an amber `pending` card naming the tool for the whole four seconds, then `success`.
8. On a runtime that cannot stream, the playground shows a notice saying replies will appear all at once — no silent degradation.
9. `php artisan about` (7.3) and the docs (7.3) state Octane as unsupported, sourced from the table authored here.
10. The notice renders correctly in **both themes** and behind the `viewSynapse` gate.

---

## Code map

| Area | Path |
|------|------|
| Flush decision | `src/Chat/StreamEmitter.php` (Done — `856b591`) |
| Capability | `src/Synapse.php` · `src/Http/Resources/AgentDetail.php` (Adjust) |
| Matrix script | `bin/check-streaming.sh` (Create) |
| Notice | `resources/js/components/StreamingNotice.tsx` (Create) · `resources/js/composed/PlaygroundShell.tsx` (Adjust) |
| Fixtures | `workbench/app/Tools/SlowTool.php` · `workbench/app/Agents/SlowToolAgent.php` (Done) |
| Support table | authored here, published by 7.3 |

---

## Tests

### Feature (`tests/Feature/Chat/`)

- `StreamFlushTest` — Done. Web SAPIs flush while `headers_sent()` is false; CLI/phpdbg/embed never flush; parts are still captured when flushing is off.
- Capability reporting: `streaming` is false under CLI and true for a web SAPI, on the agent detail payload.

### Browser (`tests/Browser/`)

- The notice renders when the capability is false, in both themes.
- **Not assertable here:** the streaming itself. The driver collects the whole body before the page sees it, so every part arrives in one chunk however long the run takes — a `pending` card is already `success` by the time the DOM exists. Assert end states; prove streaming with the script.

### Manual

- The matrix, once per runtime, recorded in the plan's Delivered section with the measured numbers.

### Fixtures

None new — `SlowTool` / `SlowToolAgent` already exist.

---

## Risks

| Risk | Mitigation |
|------|------------|
| A runtime buffers despite the headers, and we only learn at release | The script is the gate; run it per runtime before tagging |
| `bin/check-streaming.sh` measures its own pipe rather than the server | No pipes: `-w` writes to stdout with the body to `/dev/null`. Documented in the script's header, because this mistake was made twice already |
| Octane users install it and hit a silent hang | Decision 1 + AC 8: a notice, plus an explicit unsupported statement in the docs |
| CSRF makes the script fiddly to run | Script fetches the cookie jar and token itself; no manual step |
| The support table drifts from reality after a dependency bump | 7.4's release checklist re-runs the matrix; a later pipeline automates it |

---

## Delivered

Shipped as planned, with one deviation and one addition.

**Deviation: the capability rides `window.Synapse`, not the agent payload.** The
plan put `streaming` inside `capabilities` on the agent detail response. That is
a category error — `capabilities` describes the *agent* (`conversational`,
`has_tools`), while streaming is a property of the *deployment*. It now goes
through `Synapse::scriptVariables()`, which is cheaper still: no request at all,
available on every page. `Synapse::streams()` delegates to
`StreamEmitter::flushesUnder()` so the answer shown to the user and the behaviour
on the wire can never come from two different lists — asserted by a test.

**Addition: the gate was tested against a real regression.** An untested failure
path in a release gate is false confidence, so `flushesUnder()` was temporarily
forced to `false` and the script re-run: it reported `FAIL — first byte took
6971ms of a 6973ms run` and exited 1. Restored immediately.

### The matrix

| Runtime | SAPI | `output_buffering` | TTFB | Total | Result |
|---------|------|--------------------|------|-------|--------|
| `php artisan serve` | `cli-server` | 4096 | **7ms** | 7027ms | PASS |
| nginx + PHP-FPM | `fpm-fcgi` | 4096 | **2ms** | 4021ms | PASS |
| FrankenPHP | `frankenphp` | 4096 | **2ms** | 4012ms | PASS |
| Laravel Sail | `fpm-fcgi` | — | — | — | Same stack as the FPM row; not run separately |
| Laravel Octane | `cli` | — | — | — | Unsupported by decision; not run |

The `artisan serve` row is the full product — real agent, real provider,
`SlowTool` sleeping four seconds — measured with `bin/check-streaming.sh`.

The FPM and FrankenPHP rows are **transport probes**: a minimal SSE script in
Docker rather than the whole application. That is the layer under test — whether
PHP-FPM and the proxy in front of it forward a flushed stream — and it isolates
it from provider credentials and app boot. Both containers were configured with
`output_buffering = 4096`, and both were confirmed to report `ob_get_level() = 1`
before the first write, which is precisely the condition that broke the original
implementation.

**The original bug reproduces there, and only there.** Same stack, same script,
only the guard changed:

| Guard | TTFB | Total |
|-------|------|-------|
| `PHP_SAPI` (shipped) | 2ms | 4021ms |
| `headers_sent()` (original) | **4019ms** | 4019ms |

That is the whole epic in two rows: the fix is the cause of the improvement, not
something incidental that happened alongside it.

**A trap worth recording.** `php -r 'echo ini_get("output_buffering");'` reports
`0` inside a container whose ini sets 4096 — the CLI SAPI hardcodes it off
regardless of configuration. The first reading of the probe environment was
therefore wrong, and the value had to be read back *through FPM* to be true. Any
future check of buffering must go through the SAPI under test.

### Not verified

- **Laravel Sail** — nginx + PHP-FPM in Docker, which is the stack the FPM row measures. Listed as expected rather than verified.
- **Laravel Octane** — unsupported for v0.1.0 by decision, so not stood up. The reasoning is a SAPI fact (Swoole and RoadRunner run workers under CLI) rather than a measurement, and the plan should not pretend otherwise.
- **Apache + `mod_php`** — out of scope; adding a row is cheap when it becomes one.

## Definition of done

- All 10 acceptance criteria verified
- `composer check` green and `composer test:e2e` green
- The matrix run on every supported runtime, with numbers recorded in **Delivered**
- Notice verified in both themes
- `dist/` rebuilt and committed
- **PRD updated:** the streaming mechanism notes the SAPI guard and why `headers_sent()` is wrong
- **GOAL updated:** Compatibility gains a runtime support table; Octane listed as unsupported for v0.1.0
- **AGENTS.md** already carries the harness limitation and the TTFB recipe — confirm it still matches
