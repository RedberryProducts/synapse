# Laravel AI 1.3 migration

Status: steps 1–8 implemented on `codex/laravel-ai-13-migration`; deterministic qualification passed. Real-provider smoke testing is deferred at the user’s request. No tag or release has been created. See [QUALIFICATION.md](QUALIFICATION.md) for exact coverage and [PROGRESS.md](PROGRESS.md) for implementation history.

Migrate Synapse to `laravel/ai ^1.3`, preserving the current dashboard experience and its behavioral test coverage. This is a focused rewrite of the SDK boundary, persistence, and replay. Keep the existing React screens, routes, configuration, authorization, and package installation model unless a specific migration requirement changes them.

Backward compatibility with SDK 0.x and historical Synapse records is not required. Do not build dual SDK adapters, old usage readers, or a historical-data backfill. Existing installations will have an explicit, documented reset procedure; upgrading must never silently erase data.

## Evidence and baseline

Reviewed against Synapse commit `a1b24fa` and Laravel AI tag `v1.3.0`, commit `6837b4c557f67e04e8a13d882fe7724032278c27`.

| Check | Current SDK 0.11.2 | Isolated install of SDK 1.3.0 |
|---|---|---|
| Pint | Passed | Passed |
| PHPStan | Passed | Four errors |
| Unit and feature tests | 223 passed, 622 assertions | 174 passed, 49 failed |
| Browser tests | 76 passed, 271 assertions | Not run against the known-broken upgrade |

The four static errors are the two removed usage properties in `ConversationWriter` and the two removed `toVercelProtocolArray()` calls in `StreamEmitter`. Resolving them is necessary but insufficient: existing tests do not cover every changed SDK semantic.

Primary sources:

- [Version 1.3 upgrade guide](https://github.com/laravel/ai/blob/v1.3.0/UPGRADE.md)
- [Version 1.3 release](https://github.com/laravel/ai/releases/tag/v1.3.0)
- [SDK protocols](https://github.com/laravel/ai/tree/v1.3.0/src/Streaming/Protocols)
- [SDK conversation storage](https://github.com/laravel/ai/blob/v1.3.0/src/Storage/DatabaseConversationStore.php)
- [SDK generation loop](https://github.com/laravel/ai/blob/v1.3.0/src/Gateway/TextGenerationLoop.php)
- [SDK prompt and runtime configuration](https://github.com/laravel/ai/blob/v1.3.0/src/Promptable.php)

## Scope and decisions

1. Require `laravel/ai ^1.3`. Retain PHP 8.3+ and Laravel 12/13, subject to the install matrix. Declare the actual Laravel patch floor implied by the SDK's `illuminate/json-schema` dependency: Laravel 12.62 / 13.15 or later. Verify Composer resolution on each line.
2. Preserve the visible product: discovery, info panel, model selection, conversational/stateless behavior, attachments, streamed text, reasoning, tools, structured output, errors, history, navigation, themes, and retention.
3. Rename token data to input/output throughout storage and API. Update UI labels to Input/Output and explain that cache/reasoning are subsets, not additional tokens. This is an intentional terminology/semantics change; preserve where counts appear and how they update.
4. Keep Synapse-owned persistence. Do not rebind the application's `ConversationStore`, change `ai.conversations` table configuration, call `forUser()`/`continue()` to borrow SDK storage, or migrate `agent_conversations` tables.
5. Retain the conversational decorator initially. `Promptable::withMessages()` throws for `Conversational` agents and is not a replacement. Keep stateless agents undecorated. Keep structured-output agents on the existing single-shot path, including the documented no-history exception; changing that behavior is a separate feature.
6. Keep the current SSE transport and React reader. Replace removed serialization calls with a small, explicit mapping from SDK event objects to the parts Synapse consumes. Do not introduce `useChat`, AG-UI, or a new frontend dependency. The SDK protocol's mapping helpers are protected, and its response wrapper masks exceptions; directly returning it would change Synapse's debugging behavior.
7. Store ordered SDK steps for model-history replay and a compact, client-safe display transcript for UI replay. They serve different purposes. Do not store an unbounded raw-event log or create a general tracing subsystem.
8. Defer approval controls/resume endpoints, nested-agent UI, a Skills tab, classification, tool-selection controls, and CI infrastructure. Preserve SDK capabilities during invocation, including skills, without building those new screens. Approval handling remains tracked in [issue #1](https://github.com/RedberryProducts/synapse/issues/1).

## SDK findings that shape the implementation

### Streaming and completion

- Events no longer implement `toVercelProtocolArray()`. `VercelDataProtocol` maps events, recognizes failed/denied/preliminary tool results, and serializes provider tools.
- Preserve Synapse's one start, conversation announcement, rich error payload, provider/failover notices, completion metadata, and `[DONE]` terminator. Only emit completion after persistence finishes.
- Preserve the SAPI-based flush guard, disconnect behavior, and anti-buffering headers. Neither in-process feature tests nor ordinary browser SSE tests prove flushing over HTTP.
- `StreamableAgentResponse::then()` only executes on successful completion. `catch()` observes and rethrows a failure. Do not move all persistence into `then()` or let several callbacks independently report the same failure.
- The SDK now exposes response reasoning and steps. The old claim that reasoning exists only as transient deltas is obsolete. A completion-only replacement still needs to preserve reasoning already emitted when a run fails.

### Nested agents

The release guide describes streamed sub-agent activity broadly. The v1.3.0 generation loop is more specific: `preliminaryToolResults()` consumes child events and emits preliminary output snapshots for the parent tool call. It does not simply pass every child event into the parent response.

Preserve the existing top-level sub-agent card and final result. Ignore preliminary snapshots for terminal status, persisted final results, and token aggregation; optionally retain them as pending-card content only if needed for parity. Use prompt parent IDs and invocation IDs to scope any captured child events. A child provider announcement or failure must not overwrite the root run's provider or fail all root tools. Full child-tree inspection stays outside this migration.

### Wrapper fidelity and middleware

The wrapper must still preserve provider/model/failover selection, timeout, model tier, strictness, tools, instructions, provider options, and generation settings. Add `HasSkills` forwarding, because the SDK injects `LoadSkill` by checking that interface.

Audit attribute-only cache options as well as `Strict`: `TextGenerationOptions::forAgent()` reflects `CacheInstructions` and `CacheToolDefinitions` without a forwarding-method fallback. These are an existing coverage gap, not a new 1.3 feature. Resolve this in the early integration spike before treating the wrapper as transparent. Prefer a narrow options-forwarding step middleware using public SDK value objects over generated subclasses or copied provider loops; preserve step-specific SDK adjustments and run it before application middleware. Prove the resulting provider request matches an unwrapped agent, including cache TTLs.

Application middleware now receives `PendingStep` and executes once per generation step. Forward it once, in order; do not wrap the entire run in the old middleware contract. The current no-op `PiiRedactor` fixture does not prove middleware compatibility and needs a meaningful typed replacement fixture.

### Storage and usage

`TextUsage` has inclusive `inputTokens`/`outputTokens`; optional cache and reasoning counts can be null. Named constructor arguments avoid the changed cache-read/cache-write positional order. Totals are input plus output, without adding subsets again.

The SDK has two distinct step shapes: `Responses\Data\Step::toArray()` and its database store's compact representation. Choose the public response serialization for Synapse's private `steps` column, retaining per-step usage, provider/model, finish reason, reasoning, calls/results, and provider calls. Do not mix those two shapes or assume `Step::fromArray()` exists. Rehydrate messages using the SDK's public message constructors and `ToolCall::fromArray()` / `ToolResult::fromArray()`.

Keep raw replay blocks server-side, provider-bound, and only as long as required by the open turn. Preserve serialized tool-call fields needed by providers, such as Gemini thought signatures. The API must project safe display fields explicitly rather than expose serialized steps wholesale.

### Error and recorder assumptions

Several comments describe older SDK behavior. In the inspected 0.11.2 and 1.3.0 sources, `ToolFailed` already exists and tool execution IDs are locally scoped; the old provider-global ID explanation is stale. Use exact IDs and SDK timings where available, and prove same-name/nested-call behavior before deleting the fallback.

Keep one invocation-level catch-all responsible for the error row/card and terminal cleanup. Distinguish a tool handler failure, an SDK-reported failed result, a recoverable event, a terminal stream exception, and a successful failover. Do not count an error event followed by its wrapping `StreamErrorException` as two failures.

## Implementation sequence

### 1. Freeze the acceptance contract

Before replacing code, inventory existing tests by behavior using the coverage table below. Capture the clean 223-test and 76-browser-test baselines and preserve their names and assertions wherever possible.

Add focused missing coverage first: multiple text/tool steps after reload; reasoning through the real endpoint; middleware execution across steps; repeated sends without recorder leakage; SDK-table isolation; and preliminary sub-agent results. Use the installed SDK where supported, with 1.3-specific cases added alongside the dependency change.

Verification: the unchanged baseline remains green. If a new regression test exposes a pre-existing gap such as reload ordering, record that failure explicitly rather than weakening the assertion; it must pass before migration completion. Every existing behavior has a retained test or an explicitly identified equivalent replacement.

### 2. Move the dependency and prove the SDK boundary

Update Composer to `laravel/ai ^1.3` and resolve from Packagist. Keep unrelated dependency changes minimal. Port fake response constructors to `TextUsage`, update event constructor fixtures, and correct obsolete SDK assumptions in test helpers.

Run a small integration spike covering wrapper fidelity, step middleware, real-loop failures, and preliminary sub-agent output. Settle the cache-attribute forwarding approach here, before storage/UI work. This is the only deliberate architecture checkpoint; do not proceed with a wrapper known to change the agent's request.

Verification: focused decorator, model-override, middleware, and nested-agent tests; provider request comparison with `Http::fake()` where `Agent::fake()` cannot exercise request construction. Static errors and remaining integration failures are tracked, not suppressed. Intermediate work can be red; no intermediate state is a releasable upgrade.

### 3. Establish the new storage contract

Retain the three Synapse tables. On messages, replace flat replay `tool_calls`/`tool_results` columns with private `steps`; add `status` and a display `parts` payload; rename promoted counts to `input_tokens`/`output_tokens`. Retain content for search/summary, SDK file serialization for attachments, full usage, provider/model metadata, duration, and error details.

The display payload contains only the information needed to recreate existing thread entries: ordered text/reasoning blocks and references to tool observations, plus relevant notices/errors. Give blocks stable per-turn identities and ordering. Keep execution timing/status/results in tool observations; do not create two competing sources for a tool's final state.

Add a self-contained follow-up migration instead of rewriting already-published migration history. For legacy installations, require explicitly clearing Synapse data before migration; reject a nonempty legacy schema with actionable instructions. Do not automatically delete anything. Fresh installs run the complete migration sequence. No legacy reader or data backfill ships.

Verification: real sqlite migration/model tests, configured connection, fresh install, empty legacy upgrade, refusal of nonempty legacy upgrade, package-absent migration loading, clear/prune cascades and attachment deletion. Published migrations must not reference Synapse or SDK classes.

### 4. Rewrite recording and history around turns and steps

Keep `AgentInvoker` as the coordinator and `ConversationWriter` as the write owner. Keep `MessageHistory` responsible for provider-bound history. Add only a small turn accumulator/serializer if collecting live parts and step snapshots requires it; no generic event bus or adapter hierarchy.

Store a user message before invocation. On success, persist ordered steps, display parts, structured data, usage, actual provider/model, and duration once. On failure, retain useful completed steps/display output and write exactly one rich error. Do not invent a successful assistant reply when the provider fails before producing anything.

Rebuild model history in step order: assistant content/calls, matching results, then the next step. Exclude Synapse error cards and notices. Never replay an unanswered failed call as though it completed; follow the SDK's interrupted-call semantics when retained failed steps need a result placeholder. Stateless agents receive no prior turns; structured agents retain the existing single-shot exception.

Use a recorder active only during a Synapse invocation, with cleanup in `finally`. Register listeners in a way that does not accumulate closures across requests or remove the host application's listeners. Scope root/child invocations explicitly. Keep provider identity on observations rather than inferring every tool's provider from the final message after failover.

Keep model tool-call IDs distinct from execution-event tool-invocation IDs. Establish their association within the ordered invocation/step; verify repeated identical calls, skipped/invalid tools, and nested tools. Do not assume those IDs are interchangeable or join globally by tool name.

Verification: persistence, recorder, memory, attachment, reasoning, error, provider-announcement and provider-tool suites. Add failure after a successful tool, duplicate failure suppression, same-name calls, and multiple sends in one process. Confirm no SDK-table reads/writes and no generated-title request.

### 5. Replace event serialization while preserving the wire behavior

Implement the explicit mapping in `StreamEmitter` using v1.3 event classes. Keep the established part names where they still represent the same behavior. Account for nullable usage, multiple steps, provider identity, tool failures, and preliminary outputs. Use invalid-UTF-8 substitution when encoding streamed text.

Let one path finish the stream after persistence, on both success and failure. Preserve exactly one conversation announcement/start and one terminator. Provider/failover notices and rich exception data remain Synapse-specific parts.

Until issue #1 ships, surface an approval pause as an explicit unsupported-approval message and stop the loading state. Never auto-approve, label an unexecuted tool successful, or silently leave a run spinning. Keep its paused state distinct from a completed tool. Do not add resume endpoints or a claim of approval support to this migration.

Verification: existing send/flush/error tests and exact part-order tests. Exercise a real SDK loop with HTTP-faked provider streams for boundaries the agent fake does not represent. Test premature stream termination, a terminal error after emitted content, and nested preliminary results. Preserve CLI test buffering.

### 6. Reconnect replay and token display to the existing UI

Update API payloads and TypeScript types together. Change input/output usage fields, preserve nullable breakdowns, and update history/recent-conversation aggregates. Attach a turn's aggregate usage to one display entry only, so multiple text blocks do not multiply the total.

Replace UUID-merge replay with the persisted display sequence. Reuse `AssistantMessage`, `ToolCard`, `ReasoningPane`, `StructuredOutputCard`, `ErrorCard`, and the existing layout. A reload should preserve text/tool order, reasoning placement, results, provider labels, and counts.

Preserve the loading placeholder, URL transition guard, last-conversation restoration, fresh-thread state, model selector reset, file chips, error recovery, both themes, and mobile truncation behavior. Do not redesign the page or upgrade React Router as part of this work.

Verification: all browser tests, including history/navigation/info-panel/themes, plus new reload-order and nullable-usage cases. Rebuild `dist/` whenever frontend source changes. Multipart upload behavior remains covered by feature tests, not simulated browser uploads.

### 7. Reconcile discovery and package integration

Audit `AgentMetadata`, `GenerationOptions`, `ModelOptions`, `ToolClassifier`, and `ToolDetail` against the 1.3 SDK. Preserve current card/config/tool/schema displays. For skill-enabled agents, include the SDK-injected `LoadSkill` tool using the SDK's merge behavior; test skills-only agents and an explicit LoadSkill tool to avoid duplication. Share tool resolution only where the actual duplicated shape warrants it.

Do not eagerly resolve optional providers just to build the dashboard. Verify missing AWS SDK gives the normal actionable diagnostic when Bedrock is actually used. Test MCP 1.x classification in an appropriate integration installation without making MCP a mandatory production dependency.

Verification: discovery/info-panel tests, model-selector tests, configured/missing provider cases, fresh package installation and production boot after removal of dev dependencies.

### 8. Update the specification and run release qualification

Update PRD and GOAL together for SDK requirements, inclusive usage terminology, step/display persistence, actual middleware behavior, serialization ownership, replay, and the remaining approval/structured-output limitations. Update README, DEV, changelog/release notes, and applicable AGENTS/laravel-package guidance for changed conventions. Remove only comments and helpers made obsolete by this migration.

Document the old-install procedure: clear Synapse data and stored attachments using the old installation, update the package, publish the new migration, then migrate. A fresh installation must work directly. Never recommend `migrate:fresh` on a host application. The host application's own SDK conversation migration is separate and linked to Laravel's guide.

Final gates:

- `composer check` on the completed implementation.
- `npm run build` and current committed `dist/` artifacts.
- `composer test:e2e`: all original behavioral cases plus new cases; no new skips or todos.
- Laravel 12 and 13 / PHP 8.3 and 8.4 compatibility combinations supported by those frameworks; exact SDK 1.3.0 floor and latest allowed 1.x resolution where applicable.
- Real Laravel app install, production boot/migrations after `composer install --no-dev`, and configured storage connection.
- Real HTTP streaming: `bin/check-streaming.sh` or an equivalent deterministic slow fixture; first byte must precede the slow tool's completion. Do not use a piped curl stream as evidence. Prefer a local fake-backed slow tool to avoid provider spend.
- Diff review confirming no SDK-table access, accidental API calls in browser tests, weakened assertions, suppressed analysis errors, or unrelated dependency/UI changes.

Select a breaking Synapse release number when preparing the release. Do not commit, tag, or publish as part of implementation without a separate instruction.

## Existing test coverage to preserve

| Behavior | Existing feature coverage | Existing browser coverage | Allowed migration edits |
|---|---|---|---|
| Send, stream order, start/end, disconnect | `Chat/SendTest`, `Chat/StreamFlushTest` | `ChatTest` send/loading/order/slow-tool cases | SDK fixtures and serializer calls; retain order and content assertions |
| Conversational vs stateless; settings fidelity | `Chat/ConversationMemoryTest`, `Chat/DecoratorTest`, `Chat/ModelOverrideTest` | `ChatTest` stateless/model cases; `InfoPanelTest` | New SDK message fixtures and assertions on effective requests |
| User/assistant persistence and totals | `Chat/PersistenceTest`, `Chat/ConversationsEndpointTest` | `ChatTest` counts/reload; `HistoryTest` | New schema and usage names, unchanged counting intent |
| Tools, provider tools, failures | `Chat/RecorderTest`, `Chat/ProviderToolTest`, `Chat/ProviderAnnouncementTest`, `Chat/ErrorTest` | `ChatTest` card/expand/order/failure/reload | Updated event constructors; preserve results, states and rich errors |
| Reasoning and structured output | `Chat/ReasoningTest`, `Chat/StructuredOutputTest` | `ChatTest` reasoning/JSON/reload cases | Replace buffer-only tests with end-to-end behavior if buffer is removed |
| Attachments and repeat-turn context | `Chat/AttachmentTest` | `ChatTest` picker/drop/removal | SDK file fixtures only; retain MIME pass-through, download and cleanup coverage |
| Discovery and configuration inspection | `Discovery/*`, `InfoPanel/*` | `DiscoveryTest`, `InfoPanelTest` | Updated SDK metadata fixtures, not removal of displayed fields |
| History, filters, pagination, rename/delete, orphaned agents | `History/*`, `Chat/ConversationsEndpointTest` | `HistoryTest` | New count fields and replay payload; retain query-count ceiling |
| Shell, routing, fresh thread, themes, responsive names | `DashboardTest` | All six browser files | Keep navigation/selectors/text behavior unless token terminology changes |
| Installation, access, storage and retention | `InstallTest`, `ModelTest`, `RetentionTest`, auth cases | `DashboardTest` | Add schema-reset/1.3 install cases; retain no-dev and idempotency checks |

Test rules for the rewrite:

- Keep existing test names and user assertions unless an explicitly documented semantic change requires an update. SDK constructor/schema changes are legitimate fixture changes.
- If an implementation-specific helper is removed, replace its test with the same behavioral assertion at the public route/stream boundary in the same change. Do not merely delete the coverage.
- Do not loosen exact text/count/status checks to generic presence checks, add sleeps/retries to hide regressions, or regenerate expected values from the new implementation.
- Use SDK fakes for all browser agent calls. Use `AgentResponse::fakeWithReasoning()` for endpoint-level reasoning where suitable. Retain deliberate intercepted-fetch tests for loading/pre-stream failures.
- Use `Http::fake()` plus stray-request prevention for real gateway/loop contract tests. These complement, rather than replace, the browser tier. SDK fake approval resumes bypass real execution, so approval resumption must not be declared verified by that fake in the later feature.
- Browser tests observe completed buffered streams, not real tool-running timing. Upload requests and HTTP flushing retain their separate feature/real-server gates.
- Test count is an audit aid, not the acceptance criterion: preserve the 223 + 76 behaviors and strengthen missing cases. Any removed/replaced case must be traceable.

## Definition of complete

The 1.3-only implementation passes the full acceptance matrix; existing dashboard flows remain available; fresh and explicitly reset installations work; the wire/API/storage changes are documented; and browser reload matches what the user saw live. New product features remain separate follow-ups.
