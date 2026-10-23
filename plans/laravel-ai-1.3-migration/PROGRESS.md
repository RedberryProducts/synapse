# Laravel AI 1.3 — steps 1–3

Implemented on `codex/laravel-ai-13-migration`, from `a1b24fa`. No commit or release has been made. This is the intermediate milestone requested in the plan; the application is **not ready to run or release on SDK 1.3** until the writer, stream serializer, and replay/API work is complete.

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

## Verification and remaining failures

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

Next: implement step 4's turn writer, ordered provider-history replay, and scoped recorder lifecycle; then step 5's event mapping and step 6's API/UI replay and token terminology. Re-run the full acceptance matrix without reducing the retained assertions.
