# Issue #20 — explain why an agent response stopped

Status: implemented and verified on `feature/completion-reasons`.
Reviewed: 2026-10-28; implementation started 2026-10-29.
Issue: [#20](https://github.com/RedberryProducts/synapse/issues/20).
Baseline: merged `origin/main` at `83e4f67` (PR #24). The current feature checkout has the same committed file tree. Existing unrelated release-documentation edits must remain untouched.
SDK inspected: installed Laravel AI 1.3 source, particularly `FinishReason`, `Step`, `StreamEnd`, `StreamedAgentResponse`, `StepResult`, and both paths of `TextGenerationLoop`.

## Goal and boundaries

Explain exceptional endings without changing the meaning of the answer, tool results, or usage. Output limits and content filtering should produce concise notices. Errors keep their existing error cards, and approval pauses keep their approval controls.

No database migration, new dependency, new lifecycle status, automatic retry/continuation, stop button, provider-error inference, or step-inspector UI is proposed. Only report reasons the SDK actually supplies. This feature does not promise to diagnose every incomplete-looking answer.

## Findings that affect the design

1. Private `steps` already store `finish_reason`, but `ConversationPresenter` does not expose it. Completion and history share this presenter, so they can share one projection.
2. Steps from failed attempts are retained before successful failover steps. Selecting the first exceptional reason anywhere in the list would misreport a successful recovery.
3. `AgentInvoker` rejects a terminal streamed `unknown`, but currently does not apply that check to structured responses or explicitly handle a bare `error` finish reason without an accompanying exception/error event.
4. Cancellation is not necessarily a model step. Streaming middleware can return `StepResult::stop()`: the SDK emits a final `StreamEnd` with `cancelled`, potentially with no steps or only earlier completed steps. Reading only the last step loses that fact.
5. The non-streaming loop can stop without exposing an explicit cancellation reason. Do not infer cancellation from an empty structured response, a browser navigation, or a transport interruption.
6. `StreamEmitter::finish()` currently maps unrecognized reasons to `stop`; structured responses do not provide its captured StreamEnd. That wire fallback must not become the new UI's source of truth.
7. Approval continuation updates the same assistant row and appends replay steps. A new outcome must replace the old outcome, not accumulate another warning or fall back to an earlier paused turn when there are no new steps.
8. Sources and usage currently attach to the terminal assistant display entry. Finish notices must follow that same turn-level placement, including responses without answer text, without reintroducing the source-placeholder chronology bug fixed in PR #24.

## Outcome contract

Add a nullable, allow-listed `finish_reason` field to the assistant message projection. Reuse SDK `FinishReason` values; do not expose raw steps, provider responses, or replay blocks. Keep this separate from `status` (`completed`, `paused`, `failed`). A completed invocation can still have been limited or explicitly cancelled; it does not mean the model reported a normal `stop`.

Record the latest invocation's terminal reason in the existing message metadata (`meta.finish_reason`). Use a small resolver in `src/Chat` to centralize normalization and historical projection. Implementation refinement: the completed SDK response already retains its event collection. Resolve its final root StreamEnd directly, avoiding duplicate recorder state and selecting only the completed response's invocation. After a successful streaming invocation, that final event takes precedence over the last step. For non-streaming responses, use the final current-response step when available. Do not synthesize replay steps or tokens to represent cancellation.

Pass the resolved value explicitly through `ConversationWriter`; do not add ad hoc properties to SDK Meta objects. Always persist the key, including explicit null when the new invocation did not report a terminal reason. On approval continuation, replace it. The presenter falls back to the last stored step only for historical rows where the key is absent; explicit null must not revive an earlier attempt or approval step. Missing/unrecognized historical values stay null. Reading old history never rewrites its status or records.

Actual errors and pauses take precedence over finish notices. Do not infer an SDK terminal reason merely because a tool threw or the browser connection ended. Existing failure recording remains authoritative.

| Reason / lifecycle | Proposed UI and behavior |
| --- | --- |
| `length`, completed | “Output token limit reached.” Informational warning; preserve completed status and partial content. |
| `content_filter`, completed | “Stopped by content filtering.” Informational warning; preserve completed status and partial content. |
| `cancelled`, completed | “Generation was cancelled.” Neutral notice; do not claim the user cancelled it or mark it as an agent failure. |
| `stop` | No finish notice. |
| `tool_calls` / `continue` | No truncation notice. Do not relabel these as stop or guess that a step limit was reached. |
| Any reason, paused | Approval controls and existing approval notice remain authoritative. No finish notice. |
| Any reason, failed | Existing error card remains authoritative. No duplicate finish notice. |
| `error` on a new invocation without an existing failure | Route through the existing single catch-all as a failed invocation, with a precise fallback error message. Preserve partial output and report one error. |
| `unknown` on a new invocation | Preserve the existing incomplete-stream failure behavior and apply the same explicit handling to structured responses; one error, no fabricated normal completion. |
| Historical completed `error` / `unknown` | Read-only explanation: “SDK reported an error completion.” / “Completion reason unavailable.” Do not silently present stop or retroactively change status. |
| Missing historical reason | No guessed explanation. |

The new invocation checks apply after pending approvals and explicit terminal cancellation are distinguished. A root cancellation must not be mistaken for a stale earlier unknown step. Existing thrown/provider errors retain their original details rather than being replaced with a generic finish-reason message. Output-limit and content-filter reasons never cause fabricated exceptions.

## Implementation sequence

### 1. Lock down the SDK boundary with regression fixtures

Add `tests/Feature/Chat/CompletionReasonTest.php` using the existing step/stream fixtures and SDK fakes as a network guard. Cover terminal length/content_filter/stop, multi-step tool_calls/continue then stop, empty output, structured responses, and StreamEnd cancellation without a new step. Exercise actual SDK middleware `StepResult::stop()` rather than only handcrafting a cancelled event.

Also capture the existing behavior of explicit provider errors and unknown streams. Include a middleware stop after a completed step to prove that the terminal event wins over the earlier step.

Verify: new tests fail for the absent projection or incorrect lifecycle handling, while existing partial-output and approval expectations are preserved.

### 2. Capture and persist the terminal reason

Update the small finish-reason resolver and `ConversationWriter`. Scope the completed response's events to its root invocation and save the explicit reason/null alongside existing metadata. `SynapseRecorder` does not need another state tracker. Preserve private SDK-shaped steps, source accumulation, usage sums, and tool observations.

Verify: final attempt wins; child events are ignored; cancellation with zero steps is retained; new null does not reuse an earlier paused reason; no raw replay/provider fields reach the projection. Assert historical fallback is read-only.

### 3. Make terminal handling and the public contract consistent

Update `AgentInvoker` to distinguish approvals, reported cancellation, explicit errors, and unknown endings before saving the final outcome. Reuse the existing catch-all for genuine error/unknown outcomes and retain detailed provider exceptions. Add the allow-listed field in `ConversationPresenter` for both completion and history.

Review `StreamEmitter::finish()` so its legacy finish frame no longer invents stop for error/cancelled/unknown or merely missing reasons. Keep the existing SSE frame order and final persisted `data-synapse-end` contract; the saved turn projection is authoritative for the UI. Use the same resolved reason for structured and streamed responses. Cover the exact legacy-frame mapping with contract tests, without turning every raw model-step finish into a visible notice.

Verify: no duplicate error card; length/filter do not become failures; pending approvals remain resumable; final completion is still emitted only after persistence. Successful failover retains its existing notice and shows only the final attempt's completion explanation.

### 4. Render one turn-level explanation

Extend `ConversationMessage`/`AssistantEntry` types and `useConversation` replay/completion mapping. Add a small presentational `CompletionNotice` component used by `AssistantMessage`, beside the existing Sources/usage footer. It receives only the projected reason and lifecycle status, maps fixed copy, uses design tokens and a Lucide icon, and has accessible status text.

Use the persisted completion projection to show the notice at the end of a run. Do not add a second transient notice from each raw SDK step. Ensure an explanation-only response gets a display entry, but never reuse a source-only placeholder across intervening events. Existing reasoning, sources, tool chronology, and token totals stay intact. Approval resume replaces the explanation on the same turn.

Verify: browser assertions for exact notice text, one notice per turn, no normal-stop warning, zero-text outcomes, structured responses, approval continuation, and refresh parity. Cover both themes and narrow widths. A transport interruption must retain its transport error and must not invent a finish reason.

### 5. Qualify edge cases and document the behavior

Extend feature coverage for:

- Length/filter after tool steps, with unchanged text, reasoning, sources, tool results, and usage.
- Approval pending, repeated pause, approve/deny, successful continuation, and failed continuation; same assistant row throughout.
- Successful failover ending normally or with a limit/filter, plus failed fallback with no terminal response. Earlier attempt reasons must not leak into the final explanation.
- Explicit error events plus error/unknown finish reasons, ensuring one authoritative error card.
- Historical missing/malformed reasons, cancelled/error/unknown outcomes, and explicit-null metadata precedence.

Update GOAL.md and PRD.md together; add a short README capability note. Run `composer check`, `npx tsc --noEmit`, `npm run build`, then `composer test:e2e`, keeping feature and browser suites sequential. Include rebuilt `dist/` assets. If StreamEmitter's finish path changes, verify delayed real HTTP delivery using a deterministic local fixture; confirm one final completion after persistence. No live API key or provider spend is required.

## Acceptance-criteria coverage

| Issue requirement | Planned verification |
| --- | --- |
| Length/filter notices, no stop warning | Terminal-reason feature matrix + browser text/absence checks |
| Ignore intermediate tool_calls/continue | Multi-step fixtures ending with stop |
| Approval pause/resume | Existing approval fixtures, same-row and notice replacement assertions |
| Final failover outcome wins | Multiple attempts with different reasons, existing failover notice retained |
| Explicit error/cancelled/unknown handling | Real SDK middleware cancellation + error event/finish combinations + structured path |
| Limits/filter are not failures | Status and error-row assertions |
| Preserve output/tools/reasoning/usage | Existing regressions plus payload-preservation checks |
| Completion/history parity, old rows | Presenter equality, missing-data and explicit-null cases |
| Accessible themes and narrow layouts | Browser coverage with visible text and no horizontal overflow |

## Verification completed — 2026-10-29

- `composer check`: Pint and PHPStan passed; 402 unit/feature tests passed (1,421 assertions).
- `npx tsc --noEmit` and `npm run build`: passed; rebuilt `dist/app.js`.
- `composer test:e2e`: 119 tests passed (680 assertions), run after the feature suite.
- `git diff --check`: passed.
- Deterministic local HTTP smoke covered length, content filtering, cancellation without a step, and normal stop. All four emitted one saved completion and one DONE sentinel; reloaded messages matched the completion payload exactly. Delayed responses delivered their first text in 7–93 ms and finished after 3.0–3.1 seconds. No provider calls were made.
- Local preview: `build/laravel-ai13-dx/app/Ai/Agents/CompletionSmokeAgent.php` (gitignored), served on port 8014. Send `length`, `filter`, `cancel`, or `stop`. Verified the saved length notice in the actual Synapse UI.

Browser qualification also exposed the test HTTP transport trimming the final SSE separator. The reader now accepts an exact trailing `data: [DONE]` at EOF, while still requiring the saved completion frame. A focused browser regression covers this; incomplete transport retains its existing error and does not invent a completion notice.

## References

- [Issue #20](https://github.com/RedberryProducts/synapse/issues/20)
- [SDK finish reasons](https://github.com/laravel/ai/blob/v1.3.0/src/Responses/Data/FinishReason.php)
- [SDK step serialization](https://github.com/laravel/ai/blob/v1.3.0/src/Responses/Data/Step.php)
- [SDK streaming/non-streaming loop](https://github.com/laravel/ai/blob/v1.3.0/src/Gateway/TextGenerationLoop.php)
- [SDK final stream event](https://github.com/laravel/ai/blob/v1.3.0/src/Streaming/Events/StreamEnd.php)
