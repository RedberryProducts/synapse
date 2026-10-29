# Issue #21 — per-response model step inspector

Status: implemented and verified on `feature/step-inspector`; ready for review.
Reviewed: 2026-10-29.
Issue: [#21](https://github.com/RedberryProducts/synapse/issues/21).
Baseline: merged `origin/main` at `f93352f` (PR #25). Its committed tree matches the current checkout. Existing unrelated release-documentation edits remain untouched.
SDK: installed Laravel AI 1.3.0 source; GitHub's latest release resolves to v1.3.0.

## Goal and UX

Add one collapsed **Steps (N)** inspector per saved assistant turn. It explains each recorded generation step without changing the ordinary answer/tool timeline or aggregate totals. It is available after completion, failure, or an approval pause; live per-step streaming is outside this issue.

Expanding it reveals ordered step headers: step number, provider/model, finish reason, and **Model-call time**. Expand a step to inspect returned text, available reasoning, inclusive input/output usage and reported subsets, and the calls/results belonging to that step. Show **Unavailable** for missing values. Distinguish **Incomplete** and historical completion **Unverified** from an observed completed call.

Display the inspector once at the turn footer, including tool-only or failed turns with saved steps. Tool duration and total response duration retain distinct labels; do not add model/tool durations together. Provider-hosted work may already be inside model-call duration. Opening an inspector never invokes the agent or executes a tool; approval buttons remain in the existing chat controls.

## Findings and decisions

1. `SynapseRecorder` already groups steps by attempt and step number, then flattens them. `StepCompleted` arrives before custom tools run and exposes a float duration in milliseconds. Capture it at that point, scoped to the root invocation by the existing `RecordingScope`.
2. Middleware can return a complete `StepResponse` without a provider attempt. The SDK deliberately emits no `StepCompleted` in that case. Such a response is inspectable, but has no observed model-call timing; absence of the event alone must not mean interrupted.
3. `failedAttempt()` can replace a stored step's finish reason with `error`, even after the model completed successfully and a later tool failed. Preserve the original observed model outcome separately for inspection. Do not change replay/failure behavior as part of this issue.
4. `snapshot()` can append a synthetic partial step with `Unknown` and a zero-valued `TextUsage`. Mark newly recorded partial entries explicitly; those placeholder zeros are not reported usage. Historical unknown/error steps cannot always be classified accurately, so show unverified completion rather than claiming a completed model call or guessing timing.
5. SDK step numbers restart across failover and approval continuation. Identity must include invocation/attempt and local step number before conversion to a stable message-local ordinal. Never deduplicate by step text, tool name, or model call ID alone.
6. `ApprovalContinuation` already updates the original paused step's results and observations. Keep those links, append only new generation steps, and preserve previous timings through repeated pauses.
7. The current presenter exposes `meta` wholesale. Avoid placing private inspection bookkeeping there. Recommended storage: an additive nullable `step_observations` text column (array cast) on `synapse_messages`, kept separate from SDK-shaped private `steps`. No history reset or SDK-table change.
8. The inspector needs a dedicated allow-listed projection; neither SDK `Step::toArray()` nor provider-tool `data` should be sent wholesale. Reuse existing safe tool display fields and recorded observation IDs. Do not introduce signatures, replay blocks, raw HTTP responses, request bodies, or provider configuration.

## Implementation sequence

### 1. Lock down the recorder boundary with failing regressions

Add a focused feature suite for a multi-step tool response, a model completion followed by a tool failure, an interrupted next answer, failover, and repeated approval continuation. Include identical same-name calls and reused IDs across attempts. Use SDK fakes for the network guard; timing tests must exercise the actual gateway/event path with fake provider HTTP, since short-circuit middleware does not emit completion events.

Verify the original model reason survives a later tool failure, intermediate rounds remain ordered, zero duration is distinguishable from absent duration, and synthetic partial usage is unavailable. Keep existing aggregate and replay expectations unchanged.

### 2. Persist private step observations

Add the self-contained migration and model cast. Capture per-step provenance, original model finish reason, SDK duration, requested provider/model when needed, completion evidence, and associated tool observation IDs. Bind observations to saved-step ordinals using the same flattening order as `snapshot()`; keep stable ordinals on resume.

Use separate completion evidence for observed model responses, complete SDK response steps without a model event, and Synapse-generated partial steps. Preserve observations across `capture()` replacing response objects, failover, and approval continuation. Finalize tool links after the existing correlation in `finish()`; do not create a second matching algorithm for new runs.

Verify migration preservation of existing messages, configured connections, fresh installation, and loading published migrations without the package. Verify root scoping excludes child agents and recording remains limited to playground runs. Never reconstruct model time from tool timestamps or total turn duration.

### 3. Add the safe shared API projection

Introduce `StepPresenter` (or a similarly focused service) returning `step_details` from `ConversationPresenter` for both saved completion and history. Selected fields: ordinal, completion evidence, provider/model, text, reasoning, normalized finish reason, nullable usage counts, nullable model-call duration, and ordered tool details/references.

Prefer recorded response provider/model; use observed requested values only when response metadata is absent. Do not substitute the agent's current discovery configuration into historical steps. Keep missing fields null; only calculate total tokens when both inclusive input/output counts exist. Show cache/reasoning counts only when reported and never add those subsets to the total.

Resolve custom tools through the existing finalized observation mapping. For old messages, use each stored step's calls/results directly and attach observation timing only when an unambiguous association is available. Preserve call IDs and denied/error meaning. Do not correlate old identical calls by name or position guesses. For provider-hosted tools, use recorded event links or explicit SDK IDs when reliable; otherwise show the recorded call with missing result/timing rather than exposing arbitrary provider payloads or inventing a match.

Old records remain readable without updates/backfills. Historical steps have unavailable model timing and honest completion uncertainty where provenance is missing. Existing messages with no steps have no inspector. Do not add new per-message queries; reuse the tool observations already loaded for completion/history.

Verification: equality of completion and reload projections; injected private markers absent from all new display fields; usage null/zero distinctions; tool identity and order; unchanged aggregate totals and chronological parts. Test historical malformed/missing optional data without using SDK hydration that invents default zero counts.

### 4. Build the collapsed inspector

Add frontend display types and hook mapping to attach step details once to the final assistant entry. Add a props-only composed `StepInspector` with focused step components. Wire it from `ChatThread` to preserve the repository's component layers. Reuse the existing Collapsible, Markdown, ReasoningPane, JsonView, and tool/status presentation where their contracts fit. Do not import a composed component into `AssistantMessage` or distort the aggregate-only `MessageMeta` API to represent nullable step counts.

Use accessible button controls with expanded state, keyboard operation, and clear model/tool/turn timing labels. Mount detailed content only when expanded; long text and arguments must remain usable on narrow screens. Keep expansion keyed by message/step identity across same-turn updates. Approval actions are not duplicated inside inspection.

Verify one inspector per turn, tool-only and partial entries, exact rendered output/counts/reasons, repeat-call separation, history parity, approval refresh, and no new agent/tool execution on expansion. Scope browser selectors to the inspector so duplicated read-only tool details do not make existing chat assertions ambiguous. Confirm both themes and narrow layouts.

### 5. Qualify and document

Update GOAL and PRD together, including observation storage, provenance, timing definitions, safe API fields, and historical limitations. Add the README capability and migration instructions appropriate to this feature without incorporating unrelated local release edits.

Run `composer check`, `npx tsc --noEmit`, `npm run build`, then `composer test:e2e`. Feature and browser suites run sequentially. Commit rebuilt assets only when requested. Verify a deterministic real HTTP run with delayed tool execution, followed by reload: model time must come from the SDK event, and the inspector must not change delivery or total accounting. No live API key is needed.

## Scope boundaries

No request viewer, editable replay, automatic retry, cost estimates, new production logger, or nested-agent tracing. No live inspector stream or extra polling. Existing finish notices from #20 remain turn-level; step finish reasons explain individual rounds and do not generate extra warning/error cards.

The main implementation risk is preserving step identity across failover and approval continuation. Complete and verify recording/correlation before wiring the UI. If exposing the finalized recorder mapping requires extraction of existing correlation logic, propose that narrow refactor before making it; do not silently rewrite the recorder.

## References

- [Issue #21](https://github.com/RedberryProducts/synapse/issues/21)
- [Latest stable SDK release](https://github.com/laravel/ai/releases/tag/v1.3.0)
- [StepCompleted](https://github.com/laravel/ai/blob/v1.3.0/src/Events/StepCompleted.php)
- [Generation loop](https://github.com/laravel/ai/blob/v1.3.0/src/Gateway/TextGenerationLoop.php)
- [Step data](https://github.com/laravel/ai/blob/v1.3.0/src/Responses/Data/Step.php)
- [Token usage semantics](https://github.com/laravel/ai/blob/v1.3.0/src/Responses/Data/TextUsage.php)


## Implementation refinements

- Use a dedicated `steps` display entry after the turn's existing parts, rather than attaching the inspector to the last assistant text fragment. This keeps it after intervening tools and notices without changing their order and allows composed UI to remain in `ChatThread`.
- The existing finalized recorder mappings are sufficient; no correlation extraction or rewrite was needed.
- Historical steps use their own saved calls/results and do not borrow timings from ambiguous observations.
- Both the inspector and individual steps start collapsed. Step headers are now compact clickable rows with short status and timing labels, wrapping to two lines on narrow screens. Full provider/model names and completion explanations remain in expanded details.


## Verification completed — 2026-10-29

- Initial projection regressions failed before implementation, then passed.
- `composer check`: Pint and PHPStan passed; 417 unit/feature tests passed (1,508 assertions).
- `npx tsc --noEmit`, `npm run build`, and `git diff --check`: passed. Both compiled assets rebuilt.
- `composer test:e2e`: 126 browser tests passed (779 assertions), after the feature gate. Includes default collapsed steps, keyboard expansion/collapse, both themes at narrow width, distinct repeated calls and rendered results, structured/partial responses, approval updates, historical fallback, and existing chat regressions. All gates passed again after the compact-header refinement; the local preview displays each collapsed step on one line.
- Additive migration published and applied to the existing local Laravel app without clearing history. Tests cover retained data, configured migration connections, and package-absent migration loading.
- Deterministic real HTTP smoke: first text at 261 ms; total 3,445 ms; SDK model calls 203.924666 ms and 154.692833 ms; local tool 3,006 ms. Exactly one saved completion and DONE marker; reloaded message projection matched. No external AI provider requests.
- Local preview: `build/laravel-ai13-dx/app/Ai/Agents/StepInspectorSmokeAgent.php` with `InspectorSlowTool` (both gitignored), on port 8014. Provider HTTP is faked, but the SDK generation loop, model events, real tool execution, persistence, SSE, and UI run normally. The saved two-step inspector was checked in the in-app browser.

The first browser-test attempt used Pest's `press` alias as a keyboard operation; it was stopped and corrected to `keys`. Parenthesized button labels also required explicit text selectors. These were test-driver corrections; final full-suite results above are green.
