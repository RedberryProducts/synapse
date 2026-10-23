# Laravel AI 1.3 — migration progress

Implemented on `codex/laravel-ai-13-migration`, from `a1b24fa`. Steps 1–3 were committed as **`1ff2f5c`** at the user’s request. Steps 4–8 are implemented and included with the remaining migration changes for pull-request review. The UI is connected to SDK 1.3 and the PHP/browser gates pass. No release has been made; deterministic qualification is complete; real-provider testing is deferred at the user’s request. See [QUALIFICATION.md](QUALIFICATION.md) for the exact matrix.

## 1. Acceptance contract

The pre-upgrade baseline was green: **223 unit/feature tests (622 assertions)** and **76 browser tests (271 assertions)**. [BASELINE.json](BASELINE.json) records all **282 original test declarations**; datasets expand these to 299 executions. Every original declaration remains present. Existing assertions remain except for the explicitly changed SDK constructors, message-storage fixture fields, and published migration count. No tests were skipped or marked todo for this migration.

New coverage:

| Behavior | Test | Milestone result |
|---|---|---|
| Interleaved text/tool steps retain their order after reload | `tests/Browser/ChatTest.php`: preserves interleaved text and tool steps | Red, blocked by legacy writer; must pass in steps 4–6 |
| Reasoning survives the real endpoint and reload | `tests/Feature/Chat/MigrationAcceptanceTest.php` | Red, blocked by legacy writer |
| Repeated sends do not attach observations to an earlier conversation | `MigrationAcceptanceTest.php` | Red, blocked by legacy writer |
| Full send/replay does not consult SDK conversation tables | `MigrationAcceptanceTest.php` | Red, blocked by legacy writer |
| Decorator bypasses a remembering agent's preconfigured SDK conversation without mutating it | `tests/Feature/Chat/SdkBoundaryTest.php` | Green |
| Application middleware runs once per generation step and its changes reach the provider | `SdkBoundaryTest.php` | Green |
| Streamed child activity is preliminary; only the settled parent result enters final aggregates | `SdkBoundaryTest.php` | Green |

The new endpoint/browser regressions are acceptance tests, not proof that those flows have already been migrated. Their first failure currently occurs before agent execution; later failures may become visible as steps 4–6 are implemented.

## 2. Dependency and SDK boundary

- Composer now requires `laravel/ai ^1.3` and `laravel/framework ^12.62|^13.15`. Installed SDK: **v1.3.0**, from Packagist. The ignored local lockfile was refreshed; no path repository was introduced.
- Minimal dependency resolution updated AI, CommonMark, and Symfony YAML, and removed the SDK's former AWS transitive dependencies plus unused Symfony Filesystem. No frontend dependency changed.
- The conversational wrapper forwards `HasSkills`, including SDK-injected `LoadSkill`, and `Promptable::withTools()` runtime replacements. The nested-agent spike exposed the latter gap.
- `ForwardCacheOptions` forwards the original attribute-only cache settings before application middleware. It copies the current step's other options, so it does not restore a forced tool choice after the SDK releases it. Application middleware can still modify the step afterward.
- HTTP-faked Anthropic requests from wrapped and unwrapped agents match exactly after JSON decoding, across both tool and final-answer steps. Assertions also check cache TTLs, injected skill tools, effective instructions, and tool-choice release.
- A real HTTP-faked SDK loop proves a throwing tool still raises its exception and dispatches one `ToolFailed` event. The sub-agent spike uses SDK fakes and proves preliminary/final distinction across the wrapper. No provider API calls are used.
- `PiiRedactor` now uses typed `PendingStep`/`StepResult` and changes instructions, instead of being an untyped no-op. Existing discovery/info-panel names remain.
- Fake usage constructors use named `TextUsage` arguments; provider-tool event fixtures supply the now-required provider. Old token-display/API assertions remain pending step 6.

Composer compatibility checks on PHP 8.4.25:

| Resolution | Result |
|---|---|
| AI 1.3.0 + Laravel 12 line | Passed dry-run, selected Laravel 12.70.1 / Testbench 10.12.0 |
| AI 1.3.0 + Laravel 13 line | Passed dry-run, retained Laravel 13.32.0 / Testbench 11.1.0 |
| Exact Laravel 12.62.0 / 13.15.0 floors | Blocked by Composer advisory `PKSA-d5tc-s1qs-h781`; protections were not disabled |

The declared floors express the SDK dependency minimums; exact-floor runtime compatibility is not claimed. Full PHP/Laravel install and execution matrices remain step 8. The complete tests below ran on PHP 8.4.25 / Laravel 13.32.0 / AI 1.3.0.

## 3. Storage contract

The original three published migrations are unchanged. The new follow-up migration:

- Replaces flat `tool_calls`/`tool_results` columns with private `steps` and ordered display `parts`.
- Adds `status`, defaulting to `completed`, and renames promoted counts to `input_tokens`/`output_tokens`.
- Keeps content, attachments, usage, metadata, duration, timestamps, indexes, and all three tables.
- Refuses upgrade or rollback if **any** Synapse table has data, before schema mutation. It never automatically clears or backfills records.
- Resolves the configured connection for both the data guard and schema operations; references no Synapse or SDK classes.

Model casts and documentation match the new columns. A real SDK `Step::toArray()` round-trip includes reasoning, replay blocks, calls/results, provider/model, and nullable usage subsets. Separate display parts preserve explicit sequence and observation references. Generating those parts and using them in API/UI replay remain steps 4–6.

Migration tests cover fresh installation, empty legacy upgrade, refusal with conversation/message/tool rows (including orphans), refusal of populated rollback, connection isolation, explicit legacy clear with attachment deletion, new-format pruning with attachment deletion, and running the full migration sequence up/down in a separate process that rejects Synapse/SDK class autoloading.

The [explicit reset procedure](../../DEV.md#laravel-ai-13-storage-reset) is documented. No existing application database was migrated or cleared during this task; tests used isolated SQLite databases and fake storage.

## Steps 1–3 verification (commit `1ff2f5c`)

| Gate | Result |
|---|---|
| Focused foundation suite | **33 passed, 115 assertions** |
| Pint | Passed |
| `composer validate --no-check-publish` | Passed |
| `composer check` | Failed at PHPStan: **6 errors**, all in deferred components below |
| Complete unit/feature run | **148 passed / 94 failed**, 242 executions |
| Complete browser run | **43 passed / 34 failed**, 77 executions |
| Original declaration audit | All 282 retained |
| `git diff --check` | Passed |

The six static errors are the two removed `TextUsage` properties in `ConversationWriter`, two removed message replay columns in `MessageHistory`, and two removed `toVercelProtocolArray()` calls in `StreamEmitter`. No suppression or old-property annotation was added.

Feature failures currently break down into **90 legacy `tool_calls` writes** and **4 query-budget fixtures using `prompt_tokens`**. The browser failures are chat-dependent cases: 13 fail during server-side seeding through the old writer; 21 cannot render the expected response after a failed send. Those test intentions must be retained when the writer and read side are migrated. Earlier failures can mask later SDK/runtime differences.

Local run artifacts (temporary, not committed): `/tmp/synapse-foundation-tests.log`, `/tmp/synapse-step123-check.log`, `/tmp/synapse-step123-feature.log`, `/tmp/synapse-step123-feature.xml`, `/tmp/synapse-step123-browser.log`, `/tmp/synapse-step123-browser.xml`, and `/tmp/synapse-step123-laravel{12,13}-current.log`.

No frontend source changed, so `dist/` was not rebuilt. No stream serializer or controller closure changed; real HTTP flush qualification remains required when step 5 changes them. Approval controls, a skills UI, child-tree inspection, and release/version selection remain outside this milestone.

## 4. Recording and provider-history replay

- `AgentInvoker` coordinates one turn. The user row precedes invocation, history is read before that row is added, and the SDK completion callback only captures the response. One writer persists completed/paused turns or useful partial assistant output followed by one rich error. A provider failure before output produces only user/error rows. Partial output without any completed usage keeps promoted counts null.
- `SynapseRecorder` collects ordered text, reasoning, tool references, and notices alongside SDK steps. Content joins blocks within each step without adding paragraph breaks. Usage sums the root steps once; preliminary sub-agent snapshots do not add usage or complete observations.
- `RecordingScope` is container-scoped. Class listeners register once and activate only within `during()`, which restores the previous scope in `finally`. Root prompt identity, parent IDs, and invocation IDs prevent nested/other agents from changing root provider announcements or tool rows. Application listeners remain intact after repeated sends and errors.
- Tool executions use exact SDK execution IDs and SDK durations. Model call IDs are stored separately and associated within the same attempt/step using name, arguments, and settled result. Identical successful calls follow SDK execution order; ambiguous failed executions remain unpaired. Rejected/skipped model calls can have observations without execution IDs. Provider tools retain their own provider identity, including when providers reuse an item ID.
- A fifth self-contained migration adds nullable `tool_call_id`/`provider` fields and permits a null `tool_invocation_id`. It refuses rollback with observations present. Fresh install, migration rollback/loadability without package classes, and explicit-clear tests cover the full five-migration sequence.
- `MessageHistory` replays assistant/call → matching results → next step using SDK deserializers. Interrupted failed calls get explicit failed placeholders. Error cards and notices are excluded. Provider tool-call fields, including thought signatures and result IDs, survive. Raw replay blocks stay server-side, are discarded for closed turns, and expire when a new prompt follows a paused turn; generic history excludes unanswered paused calls and raw blocks.
- Conversational agents get prior turns, stateless agents remain independent, and structured agents keep their undecorated single-shot behavior. Recording does not access SDK conversation tables or generate a title request.
- The same-step failure path uses `AgentPrompt::runContext()->recordedResponse()`, an SDK-internal accessor. Public `StepCompleted` fires before tools, and execution events do not carry model call IDs; the context provides exact results that completed before a later handler threw. This narrow dependency is documented and covered by regression tests. Cloning prevents modifications to SDK-owned steps.

### Retained tests and fixture changes

All **282 original test declarations remain**, with no migration skips/todos. Persistence assertions now use inclusive input/output counts and nullable usage subsets; the tool-history fixture uses SDK steps instead of removed flat columns. Installation expects five published migrations. The interleaved browser regression retains its assertions but uses public `StepResponse` middleware fixtures: SDK 1.3's text-response fake discards attached tool calls. Fake agents remain registered, and no provider API calls are made.

`TurnRecordingTest` and `StepHistoryTest` exercise the real SDK loop, database, invoker, writer, and history. Their `RecordingEmitter` captures SDK events without invoking the old wire serializer; these are focused backend tests, **not replacements** for endpoint or browser acceptance tests. Added cases cover ordered steps, reasoning, inclusive usage, immediate/partial failures, error-event deduplication, same-step successful work before failure, unfinished/invalid tools, identical calls, nested success/handled failure, failover, provider identity, scope cleanup, SDK-storage isolation, raw-block expiry, and provider-specific call fields.

### Step 4 verification

| Gate | Result |
|---|---|
| Combined foundation + recording/history suite | **61 passed, 244 assertions** |
| Pint | Passed |
| `composer check` | Fails at PHPStan: **2 errors**, both removed serializer methods in `StreamEmitter` (step 5) |
| Complete unit/feature run | **216 passed / 54 failed**, 270 executions, 707 assertions |
| Browser gate | Incomplete; full run stalled during history tests; see browser notes below |
| Original declaration audit | All 282 retained |
| `git diff --check` | Passed |

**Browser notes:** the full run completed Chat, Dashboard, and Discovery (**27 passed / 18 failed**) before stalling within History. It was stopped after several minutes without progress. Info Panel and Theme were then run separately: **16 passed, 44 assertions**. The empty-history probe passes in isolation. A bounded History diagnostic reproduced `no such column: synapse_messages.prompt_tokens` in the unmigrated aggregate queries, then timed out; no complete History result is claimed. No tests were skipped or assertions changed to obtain these results.

The feature failures remain in endpoint streaming and the old API/history queries. Five fixture writes still use removed columns (one history-status fixture and four query-budget cases); those belong to the read-side migration in step 6. The full run dropped from 94 failures to 54. No suppression, legacy property aliases, or assertions weakened to hide migration failures.

No frontend source or stream serializer changed, so `dist/` was not rebuilt. Real HTTP flush qualification remains required for step 5. No host application database was migrated or cleared. The user-authorized commit of steps 1–3 bypassed the pre-commit hook once because that documented intermediate state had known-red gates; step 4 remains uncommitted.

Temporary verification artifacts: `/tmp/synapse-step4-focused.log`, `/tmp/synapse-step4-check.log`, `/tmp/synapse-step4-feature.{log,xml}`, `/tmp/synapse-step4-browser.log`, `/tmp/synapse-step4-browser-remaining.{log,xml}`, and `/tmp/synapse-step4-browser-history-debug.log`.

Next: step 5’s explicit SSE event mapping and completion/error lifecycle, then step 6’s API/UI projection of ordered parts and inclusive token counts. Endpoint/browser parity and release qualification remain required; this backend milestone is not a releasable upgrade.

## Steps 5–6 — stream mapping and UI connection

Implemented in the working tree on 2026-10-23; not committed or released.

- `StreamEmitter` explicitly maps SDK 1.3 text, reasoning, tool, citation, and provider-tool events. It ignores preliminary child results, handles denied/failed results, substitutes invalid UTF-8, and emits completion only after persistence. Multiple model steps share one start and one terminator.
- Approval requests produce an explicit unsupported-approval notice and paused, unexecuted tool cards. This also handles the SDK's stateless approval exception. Loading stops; no approval is granted and no resume endpoint is introduced.
- `ConversationPresenter` supplies the same public turn to stream completion and conversation reload. Steps, raw replay blocks, and private provider call fields stay server-side. Live completion reconciles against saved parts and observations; reload no longer sorts messages and tools by UUID.
- Input/output fields now reach history queries, API types, and token displays. Cache/reasoning counts are inclusive subsets, nullable breakdowns remain unknown, and multi-step usage is attached to one display entry. Actual provider identity comes from each tool observation.
- Terminal provider errors and premature streams retain partial output and finish with one error. A provider response with an unknown completion reason is treated as incomplete; missing usage on an incomplete first step stays null. The browser also reports EOF without Synapse completion/termination.
- Existing regression declarations and user-facing assertions are retained. Necessary fixture/assertion changes cover SDK serialization, input/output terminology, and the new persisted partial assistant preceding its error. The interleaved reload regression now also proves that three steps contribute usage exactly once.

Verification on SDK **1.3.0**, PHP **8.4**, Laravel **13.32**:

| Gate | Result |
|---|---|
| `composer check` | Passed: Pint, PHPStan, **280 tests / 890 assertions** |
| `npx tsc --noEmit` | Passed |
| `npm run build` | Passed; `dist/app.js` rebuilt, CSS unchanged; existing bundle-size warning remains |
| `composer test:e2e` | Passed: **81 tests / 310 assertions** |
| Original declaration audit | All **282** baseline declarations retained; no migration skips/todos |
| HTTP-faked real provider loop | Passed: terminal error and premature EOF retain partial text, emit one error and terminator, and keep unknown usage null |
| Real HTTP slow-tool check | Passed: **46 ms** first byte, **4073 ms** total, HTTP 200 |
| `git diff --check` | Passed |

The HTTP check used the gitignored `testing-laravel-project` updated to SDK 1.3.0 / Laravel 13.38, PHP's built-in server with `output_buffering=4096`, an agent fake plus the real four-second `SlowTool`, and stray-request prevention. All five migrations ran against `/tmp/synapse-step56-stream.sqlite`; the existing application database was neither migrated nor cleared. The saved turn and tool were both verified completed/successful, and the temporary server was stopped. This verifies streaming, not the entire fresh-install/no-dev release matrix.

At this milestone, next: step 7 discovery/package integration, then step 8 release qualification across supported PHP/Laravel versions and SDK resolutions. Approval controls remain issue #1; structured agents retain their existing single-shot behavior.

## Step 7 — discovery and package integration

Implemented and verified:

- Shared `AgentTools` resolution applies the SDK's `LoadSkill::mergeInto()` to skills-only and ordinary tool agents, including explicit-loader deduplication.
- SDK-wrapped `AgentTool`, `McpTool`, and `McpServerTool` retain sub-agent/MCP classification and details rather than appearing as generic tools.
- Metadata uses the SDK's provider/model-list normalization. Provider maps show the primary provider/model pair; provider lists use their defaults instead of an unrelated model method.
- Provider options receive the SDK `Lab` enum for standard providers and preserve custom provider names. Bedrock discovery works without AWS installed; invocation surfaces the normal actionable dependency error without sending HTTP.
- Nine focused discovery regressions were added. Existing discovery, model-selector, info-panel, and installation coverage remains passing.

Created a completely separate Laravel application at `build/laravel-ai13-dx`, using Laravel **13.38.0**, SDK **1.3.0**, and optional MCP **1.1.1**. Eleven sample agents cover conversational/stateless behavior, tools, skills, structured output, approvals, failures, sub-agents, MCP, and available/unavailable long names. Responses are explicitly local demonstrations, with stray HTTP requests prevented; actual tool execution and persistence still run through the installed package.

The fresh install and repeat install passed. Removing dev dependencies really removed Synapse; production boot, config caching, and all five published migrations then passed on a separate fresh database. Restored dev dependencies afterward and left the app running on **http://127.0.0.1:8013/synapse** for inspection. All commands and application edits after installing the SDK are recorded in [INSTALLATION-WALKTHROUGH.md](INSTALLATION-WALKTHROUGH.md).

Reviewed every closed GitHub production issue, **#7–#10**, and verified their fixes remain covered. See [REGRESSION-AUDIT.md](REGRESSION-AUDIT.md) for issue-by-issue evidence. Also corrected the earlier documentation claim about structured streaming: the installed SDK 1.3.0 explicitly rejects it; Synapse's existing single-shot structured path is unchanged.

### Verification

| Check | Result |
|---|---|
| `composer check` | Passed: Pint, PHPStan, **289 tests / 916 assertions** |
| `composer test:e2e` | Passed: **81 tests / 310 assertions** |
| Real MCP 1.x integration | Passed: raw and SDK-wrapped server/client tool classification and schema details |
| Fresh installation / repeat installation | Passed; five migrations, automatic guarded registration, no duplicate edits |
| Actual `--no-dev` deployment rehearsal | Passed: package absent, production boot/config cache, all migrations, no Synapse routes |
| Fresh-app HTTP scenarios | Passed: nine scenarios, history modes, ordered replay, structured output, safe approval pause, errors, sub-agents, MCP |
| SDK table isolation | Both SDK conversation tables remain empty after all preview scenarios |
| Manual browser inspection | Discovery, loading, tool arguments/results, saved replay, and Info panel checked; screenshot retained in the preview app |

No frontend source changed in step 7; the current step 6 build was used. Steps 4–7 remain uncommitted. Step 8 still owns final release qualification across supported PHP/Laravel combinations and allowed SDK versions, release documentation, and the final release review. No release is claimed.

## Step 8 — deterministic release qualification

Completed on 2026-10-23. The user explicitly deferred real API-key testing; no provider credentials were used.

- All **eight** PHP 8.3/8.4 × Laravel 12/13 × framework minimum/latest combinations pass `composer check`: **289 tests / 916 assertions per row**, plus Pint and PHPStan. SDK **1.3.0** is both the exact floor and the latest stable available resolution.
- Browser checks pass on both latest Laravel majors: **81 tests / 310 assertions each**. The main checkout also passes its PHP/browser gates, TypeScript, Composer validation, and rebuilt assets.
- Real buffered HTTP streaming passes on PHP 8.3 (**75 ms / 3700 ms**) and PHP 8.4 (**62 ms / 3653 ms**). Completed turns and successful tools were verified in storage.
- A fresh Laravel 12 / PHP 8.3 installation, repeat installation, and actual production `--no-dev` boot/migration rehearsal pass, complementing step 7's Laravel 13 installation.
- Reviewed the implementation and preserved all **282 original test declarations**. Removed the obsolete `streamError()` helper; no assertions were weakened. Closed production issue regressions remain green.
- Added release-facing [UPGRADE.md](../../UPGRADE.md) and [CHANGELOG.md](../../CHANGELOG.md), with **0.2.0** as the planned breaking pre-1.0 release. Updated specification/status, developer workflow, and package skill guidance. No version tag or publication was made.

Historical framework minimums require an exact advisory exception in disposable test copies; current Laravel 12/13 resolutions have clean dependency audits. Package/preview audit settings were not relaxed. Setup failures (GD, concurrent Testbench migration mutation, document root, sandbox port access) were corrected and affected gates rerun completely. See [QUALIFICATION.md](QUALIFICATION.md) for versions, evidence, commands, and limitations.

The temporary qualification servers are stopped; the user's deterministic preview remains on **http://127.0.0.1:8013/synapse**. The migration is prepared for pull-request review. Real-provider smoke testing and any tag/publish action remain separate next steps.


## Live-provider follow-up

The user subsequently authorized real OpenAI testing. All 11 test agents were exercised in the visible browser using gpt-4.1-mini: ten callable scenarios and the intentional constructor failure. See [LIVE-PROVIDER-TEST.md](LIVE-PROVIDER-TEST.md) for the execution record. Two UI gaps were found (live paused/failed usage and the unavailable-agent sidebar route); attachment transport/persistence passed, while the initial color answer was inaccurate. The 8013 preview now uses real OpenAI calls. No package implementation, automated test, tag, or release changed in this session.

### Live-test fixes and MCP advisory

Fixed both UI findings and added four browser regressions, preserving the existing Info panel assertions with explicit panel scoping. Live OpenAI rechecks passed without reload (approval 103 tokens, failure 79, then recovery total 206). Added the optional MCP conflict `<1.1.0` for GHSA-m25c-38v7-94p8; Composer rejects 1.0.0 and accepts the patched 1.1.0 floor. The preview retains MCP 1.1.1 and a clean audit. Final gates: 289 unit/feature tests, 85 browser tests, Pint, PHPStan, TypeScript, build, Composer validation, and diff checks pass. See [LIVE-PROVIDER-TEST.md](LIVE-PROVIDER-TEST.md) for evidence and remaining model/provider limits.
