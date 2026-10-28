# Issue #19 — cited sources on agent responses

Status: implemented and verified; ready for review.
Reviewed: 2026-10-28.
Branch: `feature/cited-sources`, created from `origin/main` at `7175399` (merged PR #23).
Issue: [#19 — Show cited sources on agent responses](https://github.com/RedberryProducts/synapse/issues/19).

## Goal and scope

Show the URL citations actually reported by the SDK in a compact Sources section for each assistant turn, during streaming and after reload. Preserve sources across steps, failover, approval continuation, and partial failure. Sources describe what the provider reported; they do not establish that the answer is correct.

Keep this separate from completion notices (#20), the step inspector (#21), and inline citation highlighting. Do not infer citations from markdown links or tool results, fetch source pages, load external favicons, or add model requests. No dependency or database migration is expected.

## Findings before implementation

- `StreamEmitter::event()` already emits URL/title in `source-url` events. `StreamPart`, `StreamHandlers`, and the frontend reader do not consume these fields.
- `SynapseRecorder::event()` ignores Citation events. `capture()` retains response steps but not the streamed response's aggregate citations; `snapshot()` chooses the last step's Meta. Sources can therefore disappear even before rendering is considered.
- `ConversationWriter` can use the existing `meta.citations` field, but currently overwrites metadata during approval continuation. It must merge prior sources explicitly.
- `ConversationPresenter` returns metadata without a citation-specific projection. Add an explicit, validated source projection instead of exposing arbitrary citation fields.
- `AgentInvoker` only persists a failed partial assistant when it has text, reasoning, steps, display parts, or an existing resumed row. A citation-only failed turn needs to count as useful output.
- `useConversation` represents one turn with multiple assistant/tool entries. `completeTurn()` replaces the live projection from saved data; replay and empty-entry cleanup must preserve a turn that has only sources.
- SDK 1.3 streams citations for OpenAI, Anthropic, Gemini, OpenRouter, and xAI when the provider returns supported annotations. Some providers emit these near completion, so Synapse cannot promise earlier availability than the SDK provides. GOAL.md's older provider limitation is stale.
- The browser harness buffers SSE. Browser tests prove the completed display and reload behavior; real HTTP verification is required to prove visibility before completion.

## Design decisions

### Storage, identity, and safe projection

Use the existing `synapse_messages.meta.citations` for the ordered turn-level sources. Keep SDK replay steps separate and do not alter their serialization for this UI feature. Introduce a small shared PHP citation normalization/merge helper, since the recorder, continuation merge, emitter, and presenter need the same rules.

The public source shape is `{url: string, title: string | null}`. The URL is the stable deduplication identity. Keep first-seen order; only enrich an empty title with a later nonempty title. Do not strip query strings or fragments, rewrite URLs, or merge different URLs. Trim blank titles into null.

Accept absolute HTTP/HTTPS URLs with a host. Omit malformed URLs, other schemes, credential-bearing URLs, control characters, and browser-ambiguous backslashes. Use equivalent frontend validation before creating links. Titles render as plain text; fall back to hostname, then the accepted URL. Links use `target="_blank"` and `rel="noopener noreferrer"`.

Do not turn optional SDK character offsets into turn-wide offsets: they may refer to different text blocks or steps. Existing private SDK step metadata can retain ranges already present, but this issue will not add a range-indexing system or publish ranges in the Sources list.

Expose the normalized list as an explicit message `sources` field through `ConversationPresenter`, shared by reload and completion. Normalize any existing `meta.citations` output too so raw citation objects cannot bypass that projection. Existing valid metadata, or citation metadata in existing stored steps, can supply a best-effort read fallback; when historical data was never recorded, show no sources and do not invent them. No backfill is needed.

### Capture and continuation

Capture supported citations immediately from root-invocation stream events. Merge step metadata at step completion and response/step metadata during `capture()`, including non-streaming structured responses. Events determine first-seen order when available; metadata fills gaps without clearing prior observations.

Keep the citation accumulator across failover attempts within the same turn. Build a fresh final Meta with the accumulated citations instead of mutating a stored step's Meta. Merge prior saved citations with new citations when writing an approval continuation, preserving the same assistant row and first-seen order even if the resumed call fails.

Include citation presence in the partial-turn persistence decision. Sources must survive a terminal provider error, premature EOF, or an exception after a source event even if no text was generated. Child invocation citations must not be silently promoted into root answer sources.

### UI behavior

Render one Sources section for the whole turn, not one for each text block or citation event. Use a presentational `SourcesList` component and existing design tokens/icons. Show each title with its hostname or URL available, allow long labels to wrap, and hide the section entirely when empty.

Keep source merging and turn association in the hook/pure helpers. Associate sources with the terminal assistant display entry, transferring that association when subsequent answer blocks appear. Create/retain a source-only assistant entry when needed. Citation arrival must not split an existing text block, reorder tool/text/notice entries, or duplicate aggregate usage. Sources remain visible with a partial answer when an error follows.

Add typed `source-url` handling using the existing event name. Merge live sources by URL, then replace live turn state with the persisted canonical projection at completion. Seed approval continuation from saved sources. Reset sources with the rest of the conversation when switching agents or starting a fresh thread.

## Implementation sequence and verification

### 1. Establish the source contract and regression fixtures

Add the shared normalizer/merger and focused behavior tests for exact-URL deduplication, first-seen order, title enrichment, unsafe URLs, and missing metadata. Add regression cases exposing current source loss through normal completion and partial failure. Reuse `StreamSequenceAgent`, `StepSequenceAgent`, and the existing fake gateway rather than introduce a second provider abstraction.

Verify: focused tests demonstrate the persistence gap before the recorder changes; normalization behavior is explicit and deterministic.

### 2. Preserve citations through recording and persistence

Update `SynapseRecorder`, `ConversationWriter`, and the partial-output guard in `AgentInvoker`. Capture event-only and metadata-only citations; retain accumulated sources through failover; merge saved citations on approval resume. Keep model history, token accounting, and SDK tables untouched.

Verify: feature tests cover multiple steps, completion-only/structured citations, failover, repeated approval rounds, denied continuation, resumed failure, citation-only failure, and isolation between root/child invocations and separate turns. Assert assistant IDs, tool counts/results, and usage are unchanged where these regressions intersect existing behavior.

### 3. Connect the public stream and reload contract

Validate citation events before emitting `source-url`; project only URL/title through `ConversationPresenter`. Include `sources: []` for messages with no citations. Use the same message projection on stream completion and history reload. Make existing stored citations readable without rewriting records.

Verify: feature tests compare completion and reload sources exactly; invalid URLs and private replay/signature/raw-response sentinels stay out of the citation payload. HTTP-faked SDK gateway tests cover a mid-stream citation followed by an error and a provider that supplies citations only near completion.

### 4. Render Sources and maintain turn state

Update `types/chat.ts`, `lib/stream.ts`, `useConversation`, and the assistant presentation. Add `SourcesList`; deduplicate live updates and preserve source-only entries during cleanup. Cover text/tool/text turns, titles arriving later, pending approvals, resumed turns, multiple conversations, and transport-error fallback. Keep source links separate from markdown answer rendering.

Verify: browser tests assert actual title/hostname text, link destinations and safe attributes, duplicate counts, no empty section, sources with structured output, partial failure, approval resume, and identical results after refresh. Cover both themes and narrow layouts. Use `Agent::fake()` as the network guard; do not click external links during tests.

### 5. Reconcile docs and qualify the finished feature

Update GOAL.md and PRD.md together for the implemented Sources list, supported SDK event paths, persistence shape, safe projection, and the deliberate lack of inline range highlighting. Add a concise README feature note if appropriate. Preserve unrelated release-documentation edits already present in the checkout.

Run `composer check`, `npx tsc --noEmit`, `npm run build`, and then `composer test:e2e` sequentially with feature/browser suites never overlapping. Include rebuilt `dist/` assets. Use the installed preview app with a deterministic delayed citation fixture to verify a source event reaches HTTP/the browser before completion; check real streaming after the emitter change. No live provider key or spend is required.

Verify: every issue acceptance criterion is covered, all original behavior checks remain green, and the streaming check confirms early delivery plus reload parity.

## Acceptance-criteria mapping

| Issue requirement | Implementation / validation |
| --- | --- |
| Titles, fallback labels, clickable links | Safe projection + SourcesList; browser text/href assertions |
| Visible while streaming, no duplicate on completion | Typed source-url event + hook merge + real HTTP smoke |
| Exact-URL deduplication and title enrichment | Shared merge contract + feature/browser cases |
| Multiple steps and approval continuations | Recorder accumulator + writer merge + same-row tests |
| Partial-failure retention | Event-time capture + citation-only persistence/cleanup cases |
| Reload parity and older records | Shared presenter + historical metadata fallback tests |
| No empty or inferred Sources section | Empty-source and ordinary-link negative cases |
| Safe links and text | Server/client URL checks + escaped titles + new-tab attributes |
| No private provider state | Explicit projection + private-data sentinel tests |
| Themes, narrow layouts, keyboard access | Browser coverage of the Sources component |

## Implementation and verification — 2026-10-28

All five steps are implemented. Sources use the existing message metadata, with no database migration or new dependency. The recorder accumulates root-invocation citations, the writer merges approval continuations, and the presenter supplies the same safe projection to completion and history. The frontend keeps one Sources footer per turn and preserves citation-only failures. GOAL, PRD, README, and compiled assets are updated; pre-existing release-documentation edits remain untouched.

- `composer check`: passed, 341 unit/feature tests and 1,182 assertions, including lint and static analysis.
- `npx tsc --noEmit` and `npm run build`: passed.
- `composer test:e2e`: passed, 97 browser tests and 459 assertions. The feature and browser suites ran sequentially.
- Citation regressions cover exact URL identity, title enrichment, unsafe links, historical metadata, private-field exclusion, root invocation scope, multiple steps, partial failures, structured failover, and approved/denied continuations.
- Browser coverage includes both themes, narrow layouts, escaped titles, keyboard focus, link attributes, structured answers, approval continuation, empty-source behavior, and reload parity.
- A deterministic middleware fixture in the ignored preview app emitted a citation before a four-second delay. A real HTTP request received the first citation in 9 ms and completed in 4,024 ms; both saved sources matched the completion payload after reload.
- In the visible browser, the first source appeared while the response still showed Generating. After completion and reload, each turn retained its two links without duplication. This smoke test made no provider API calls.
- A separate, user-authorized live smoke test used a local `WebResearchAgent` with OpenAI `gpt-4.1-mini` and the SDK's hosted `WebSearch` tool. The request completed with a recorded search call, three citations to one URL displayed as one source, and unchanged sources after reload. The agent and credentials remain in the ignored local testing app; automated suites continue to use fakes.
- `git diff --check`: passed.

## Review fixes — 2026-10-28

- Unicode URLs: four regressions reproduced discarded Georgian paths, internationalized hostnames, query/fragment text, and IPv6 URLs with Unicode paths. Validation now uses Laravel's URI serialization to obtain an ASCII representation while preserving the exact original URL. Existing unsafe-URL checks remain in place; browser tests confirm Unicode links survive reload.
- Live ordering: browser-controlled streams reproduced answers appearing above custom tools, provider tools, and notices when a citation arrived first. Source-only entries now have a separate identity, and empty footers are removed when their sources transfer. Six browser regressions check text/structured answers both before completion and after interrupted transport, where saved replay cannot mask the live ordering.
- Final checks: `composer check` passed (345 tests, 1,194 assertions); `composer test:e2e` passed (105 tests, 563 assertions); TypeScript, production build, and diff whitespace checks passed. Feature and browser suites ran sequentially. These regressions use fakes and make no provider requests.

## References

- [SDK aggregate streaming citations](https://github.com/laravel/ai/blob/v1.3.0/src/Responses/StreamedAgentResponse.php)
- [SDK URL citation fields](https://github.com/laravel/ai/blob/v1.3.0/src/Responses/Data/UrlCitation.php)
- [SDK streaming citation event](https://github.com/laravel/ai/blob/v1.3.0/src/Streaming/Events/Citation.php)
