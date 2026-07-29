# Epic 4 — Tool Inspection

**Goal:** watch a tool run. A card appears in the thread the moment the agent calls a tool, shows its arguments, and resolves in place to a result or a failure — for your own tools and for provider-native ones alike.

Delivers PRD [Feature 3](../../PRD.md#feature-3-inline-tool-call-inspector) · GOAL [Tool inspection](../../GOAL.md#tool-inspection) · Success Criterion **#3**; completes **#4** by giving a failed tool a card as well as an error message.

**Depends on:** Epic 2 (tool classification) · Epic 3 (the stream, the catch-all, the thread)
**Blocks:** Epic 6 (replay merges tool rows with messages chronologically).

---

## Design

- **Component sheet:** [Inline Tool Call cards](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=271-2353&m=dev) `271:2353` — local copy: [`screenshots/tool-call-cards.png`](screenshots/tool-call-cards.png)
- **In context:** [expanded card](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=324-11632&m=dev) `324:11632` · [collapsed success](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=324-12263&m=dev) `324:12263` · [error](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=324-13008&m=dev) `324:13008`

| Figma component | Node | Variants |
|-----------------|------|----------|
| `Inline Tool Call cards` | `271:2353` | **Open** `271:2351` · **Collapsed** `271:2352` · **Variant3** `630:6058` (provider tool, collapsed) · **Variant4** `630:6087` (provider tool, open) |
| `Status Badges` | `327:2670` | `success` (green) · `error` (red) · `pending` (amber) |
| `Copy` | `408:5907` | on every JSON block |

**What the design shows**

- **Header** — a wrench icon + tool name for user tools; a lightning icon + `provider / tool_name` for provider tools. On the right: `⏱ 99ms` once finished, or a status badge (`pending` / `error`), then the expand chevron.
- **Body (open)** — an `Arguments` label with a JSON block, then a `Result` label with a JSON block. Each block has a copy button. On failure the `Result` label and its block turn red.
- **Collapsed** is the default in the thread; the whole header is the toggle.

Both of the states this epic needed were **delivered by the designer** since [DESIGN_FEEDBACK.md](../../DESIGN_FEEDBACK.md) items 3 and 4 — the provider-tool variant and the pending state both exist now. No open design questions.

---

## Decisions

Confirmed before planning:

1. **Hand-rolled `JsonView`.** The design is a pretty-printed block with coloured tokens, not an interactive tree, so `@uiw/react-json-view` buys little for ~30–40KB in a bundle that is inlined into every page load — the same trade we made against `useChat` in Epic 3. → **Update** the `JsonView` row in [plans/FRONTEND.md](../FRONTEND.md), which still names the library.
2. **A sub-agent that throws shows as a *success*.** `AgentTool::handle()` catches every `Throwable` and returns `"Agent failed: <message>"` as an ordinary tool result, so that string is what the model received and reasoned from. Rendering it as an error would show a state the model never saw. The card shows success; the failure is legible in the result body. Documented in GOAL so it doesn't read as a bug.
3. **Provider-tool statuses are normalized on the way in** (see [Technical approach §3](#3-provider-tool-events-are-messier-than-the-prd-says)) — the provider vocabulary is open-ended and differs per gateway, so Synapse maps it to its own three and keeps the raw value in the stored payload.

---

## Scope

**In**

- `SynapseRecorder` — `InvokingTool` → pending row, `ToolInvoked` → success, provider-tool events upserted by `itemId`
- `message_id` stamping so cards attach to the assistant turn they belong to
- Tool parts on the SSE stream driving **live** cards (pending → resolved, in place)
- Tool invocations in the conversation replay payload, merged into the thread by `started_at`
- `ToolCard` with all four Figma variants + `JsonView` + copy
- The dangling-`pending` → `error` sweep already written in Epic 3 now has cards to affect

**Out**

- **Reasoning pane, attachments, model selector, structured-output JSON card** (Epic 5) — `JsonView` is built here and Epic 5 reuses it
- **History list / filters / replay page** (Epic 6) — this epic only extends the single-conversation payload
- Re-running or editing a tool call from the UI — Synapse observes, it does not intervene
- Deep-linking to a specific tool card

---

## Frontend components to use

Layers per [plans/FRONTEND.md](../FRONTEND.md). **Status:** `Done` = exists, used as-is · `Create` = build it · `Adjust` = exists, needs the stated change.

### 1. Elements (`resources/js/elements/`)

| Element | Status | Figma | Used by | Notes |
|---------|--------|-------|---------|-------|
| `Collapsible` | Done | `271:2353` | `ToolCard` | Built in Epic 3 for the error card's trace; the card header replaces the default chevron trigger |
| `Copy` | Done | `Copy` `408:5907` | JSON blocks | — |
| `Badge` | Done | `Status Badges` `327:2670` | status pill | **Adjust** below |
| `Card` · `Tooltip` | Done | — | card surface, truncated names | — |
| `JsonView` | **Create** | JSON blocks in `271:2351` | `ToolCard`, Epic 5's structured output | Pretty-print + token colouring on our own palette; `<pre>` with a max height and its own scroll. Non-JSON string results render verbatim rather than being force-parsed |

### 2. Components (`resources/js/components/`)

| Component | Status | Figma | Responsibility |
|-----------|--------|-------|----------------|
| `ToolCard` | **Create** | `271:2353` | One invocation: header (icon, name, duration or status badge, chevron) + Arguments/Result blocks. Handles user and provider variants and all three statuses |
| `ToolStatusBadge` | **Create** | `327:2670` | `pending` (amber, spinner) · `success` (green) · `error` (red) |
| `Badge` consumer styles | **Adjust** | `327:2670` | Add the `success` / `error` / `pending` status weights; today it has `chip` and `pill` only |
| `AssistantMessage` | **Adjust** | `324:12235` | Unchanged rendering, but the thread now interleaves cards around it — see `ChatThread` |
| `ErrorCard` | Done | — | A thrown tool produces **both** a failed card and an error card; that pairing is intentional and needs no change |

### 3. Composed (`resources/js/composed/`)

| Component | Status | Figma | Responsibility |
|-----------|--------|-------|----------------|
| `ChatThread` | **Adjust** | `324:11632` | Accept `tool` entries in the entry union and render them inline, ordered with messages |
| `PlaygroundShell` | Done | — | No change |

### 4. Pages (`resources/js/pages/`)

| Page | Status | Responsibility |
|------|--------|----------------|
| `Playground` | Done | No change — the new entries flow through `useConversation` |

### Data layer

| File | Status | Responsibility |
|------|--------|----------------|
| `types/chat.ts` | **Adjust** | Add `ToolEntry` to `ChatEntry`; add the tool stream-part shapes and `ConversationToolInvocation` |
| `lib/stream.ts` | **Adjust** | Dispatch `tool-input-available`, `tool-output-available`, `tool-output-error`, `data-provider-tool` — currently they fall through to `onPart` |
| `hooks/useConversation.ts` | **Adjust** | Fold tool parts into `ToolEntry`s; rebuild them from the replay payload |
| `lib/api.ts` | Done | `getConversation` already returns the payload; only its shape grows |

### Styling

`styles/app.css` — add `--color-warning` (amber) for the pending badge; `success` and `destructive` already exist. Plus the JSON token colours (key / string / number / boolean / null), defined for both themes.

---

## Configuration

**This epic introduces no new config keys.** It reads the same keys as Epic 3 (`synapse.storage.connection` via the models, `synapse.ui.*` for the route group).

---

## Technical approach

Verified against `references/laravel/ai` at v0.9.1.

### 1. The recorder, and the one event that never fires

```php
// GeneratesText::listenForToolInvocations()
invoking: fn (Tool $tool, array $arguments) => event(new InvokingTool($invocationId, $toolInvocationId, $agent, $tool, $arguments)),
invoked:  fn (Tool $tool, array $arguments, mixed $result) => event(new ToolInvoked(...)),
```

`InvokesTools::executeTool()` runs `invoking` → `$tool->handle()` → `tap(…, invoked)` inside `try/finally` with **no catch**. So `ToolInvoked` fires *only on success*, and a throwing tool leaves its row `pending`. Epic 3's catch-all already sweeps those to `error`; this epic is what finally makes that visible.

| Event | Row |
|-------|-----|
| `InvokingTool` | insert `status = pending`, `type = tool`, `name` via `ToolNameResolver::resolve()`, `arguments`, `started_at`, both ids |
| `ToolInvoked` | update by `tool_invocation_id` → `status = success`, `result`, `duration_ms`, `finished_at` |
| *(never fires on failure)* | Epic 3's catch-all flips remaining `pending` rows for the `invocation_id` |

### 2. Trap: `currentToolInvocationId` is shared, and nesting clobbers it

`GeneratesText` holds `protected string $currentToolInvocationId` **on the provider**, set in `invoking` and read in `invoked`. `AiManager` extends `MultipleInstanceManager`, which caches provider instances by name, and `textGenerationLoop()` is memoized per provider (`??=`). So when a sub-agent tool runs *another* prompt on the same provider:

```
outer invoking  → currentToolInvocationId = A   InvokingTool(A)
  inner invoking → currentToolInvocationId = B   InvokingTool(B)
  inner invoked  → ToolInvoked(B)               ✔
outer invoked   → ToolInvoked(B)                ✘ should be A
```

The SDK's `pushToolInvocationCallbacks()` stack correctly restores the *callbacks* across nesting, but the id is a plain property and is not part of that snapshot.

Consequence if we key blindly on `tool_invocation_id`: the outer sub-agent card never resolves and spins forever, while the inner card is written twice.

**Mitigation** — the recorder resolves the target row as:

1. the `pending` row matching `tool_invocation_id`, else
2. the most recent `pending` row for the same `invocation_id` **and** tool `name`.

Step 2 costs nothing in the common case and makes nesting correct. **This is invisible under `Agent::fake()`** — `textProviderFor()` returns `(clone $provider)` when the agent is faked, and the clone gets its own copy of the scalar — so no test can reproduce it. It is guarded by construction and by this note, not by a red test.

### 3. Provider-tool events are messier than the PRD says

The PRD states `$event->type` is like `anthropic.web_search` and `$event->status` is one of `in_progress | completed | failed`. **Neither is true.** Reading the gateways:

| Gateway | `type` | `status` values seen |
|---------|--------|----------------------|
| Anthropic | the raw content-block type — `server_tool_use`, or `*_tool_result` (e.g. `web_search_tool_result`) | `started` · `result_received` · `completed` |
| OpenAI | the item type ending `_call` — `web_search_call`, `file_search_call`, `code_interpreter_call` | `completed`, plus the third segment of `response.<x>_call.<status>` — an **open** set (`in_progress`, `searching`, `interpreting`, …) |
| xAI | same shape as OpenAI | same |

Three follow-ons:

- **The tool's real name is not in `type`.** For Anthropic, `server_tool_use` is generic and the name lives at `$data['name']`. The card label resolves `$data['name'] ?? $type`, and the provider prefix comes from the message's own `meta.provider` — not from parsing `type`.
- **Status must be normalized.** Synapse maps into its own three and stores the raw string inside the payload so nothing is lost:

  | Provider status | Synapse |
  |-----------------|---------|
  | `completed`, `result_received`, `done` | `success` |
  | `failed`, `error`, `incomplete` | `error` |
  | anything else (`started`, `in_progress`, `searching`, …) | `pending` |

  The default is `pending`, so an unrecognized new status shows an in-flight card rather than a wrong terminal one, and the catch-all still resolves it if the run dies.
- **`itemId` correlation is best-effort.** Anthropic emits the start block keyed on `content_block.id` and the result block on `tool_use_id ?? id`; those match only when the provider sends `tool_use_id`. The upsert therefore matches on `(invocation_id, tool_invocation_id)` and, failing that, inserts a second row — two cards is a better failure mode than one card with the wrong result welded on.

→ **PRD update required** for the `type` / `status` claims in Feature 3.

### 4. Live cards vs replayed cards

Two sources, one shape:

- **Live** — the stream already carries `tool-input-available` (from `Streaming\Events\ToolCall`), `tool-output-available`, our `tool-output-error`, and `data-provider-tool`. Epic 3's reader hands these to `onPart`; Epic 4 gives them real handlers. Cards appear pending on input and resolve on output.
- **Replay** — `GET /api/conversations/{id}` grows a `tool_invocations` array; `useConversation` rebuilds the same `ToolEntry`s from it.

The reducer keyed on `toolCallId` is shared by both paths, so a card looks identical whether you just watched it happen or opened the conversation a week later.

**Ordering.** Cards interleave by `started_at` against message timestamps. Live, they append in arrival order, which is the same thing. The assistant text of a multi-step run arrives in blocks around the tool calls, and the existing `blocks`/`order` structure already preserves that.

### 5. Linking a card to its turn

At stream close the recorder stamps `message_id` on every row for that `invocation_id`, so Epic 6 can replay a turn with its cards. Rows that never get a `message_id` — a run that failed before any assistant message — still render, ordered by `started_at`. Deletion already cascades through `ConversationRepository`.

---

## API

`GET /synapse/api/conversations/{id}` gains one array:

```jsonc
{
  "id": "0198f…",
  "messages": [ /* unchanged */ ],
  "tool_invocations": [
    {
      "id": "0198f…",
      "message_id": "0198f…",          // null when the run died before the turn landed
      "type": "tool",                   // tool | provider_tool
      "name": "SearchProductsTool",
      "provider": null,                 // provider tools only, from the turn's meta
      "arguments": { "query": "hoodie", "max_results": 5 },
      "result": "3 matches",            // string or JSON; null while pending
      "status": "success",              // pending | success | error
      "provider_status": null,          // the raw provider string, provider tools only
      "error": null,
      "duration_ms": 45,
      "started_at": "2026-07-29T10:14:02+00:00",
      "finished_at": "2026-07-29T10:14:02+00:00"
    }
  ]
}
```

Stream parts (already emitted by Epic 3's `StreamEmitter`, now consumed):

```jsonc
{"type":"tool-input-available","toolCallId":"call_1","toolName":"SearchProductsTool","input":{"query":"hoodie"}}
{"type":"tool-output-available","toolCallId":"call_1","output":"3 matches"}
{"type":"tool-output-error","toolCallId":"call_1","errorText":"Ledger service unavailable"}
{"type":"data-provider-tool","data":{"item_id":"srvtoolu_1","type":"server_tool_use","status":"started","data":{"name":"web_search"}}}
```

---

## Acceptance criteria

1. Sending a message to an agent that calls a tool shows a **collapsed card** in the thread, between the messages, naming the tool.
2. The card appears **pending** while the tool runs and resolves in place to `success` with a duration — without the thread jumping or re-ordering.
3. Clicking the card expands it to show `Arguments` and `Result` as formatted JSON; clicking again collapses it. Each block copies to the clipboard.
4. A tool that throws shows a card with an **error** badge and the exception message in place of the result, **and** an error card in the thread — the card says which tool, the error card says what went wrong.
5. An agent that calls several tools in one turn shows one card per call, in the order they ran.
6. A provider-native tool shows the lightning variant labelled `provider / tool_name`, with its payload in the body.
7. Refreshing the page restores every card — status, arguments, result, duration — in its original position in the thread.
8. A result that is not JSON (a plain string) renders as-is rather than as an error or an empty block.
9. A sub-agent tool whose agent throws shows a **success** card whose result reads `Agent failed: …` — what the model actually received.
10. Deleting the conversation removes its tool rows.
11. All of the above renders correctly in **both themes**, and the endpoints stay behind the `viewSynapse` gate.

---

## Code map

| Area | Path |
|------|------|
| Event listeners → rows | `src/Chat/SynapseRecorder.php` |
| Provider-tool upsert + status normalization | `src/Chat/ProviderToolRecorder.php` |
| Registration | `src/SynapseServiceProvider.php` |
| `message_id` stamping | `src/Chat/AgentInvoker.php` (Adjust) |
| Replay payload | `src/Http/Controllers/ConversationsController.php` (Adjust) |
| JSON element | `resources/js/elements/JsonView.tsx` |
| Cards | `resources/js/components/{ToolCard,ToolStatusBadge}.tsx` |
| Thread + reducer | `resources/js/composed/ChatThread.tsx` · `resources/js/hooks/useConversation.ts` (Adjust) |

---

## Tests

### Feature (`tests/Feature/Chat/`)

- **`RecorderTest`** — `InvokingTool` writes a pending row with arguments and `started_at`; `ToolInvoked` resolves it to success with `result`, `duration_ms`, `finished_at`. (AC 1, 2)
- **`RecorderTest`** — a throwing tool leaves exactly one row, flipped to `error` with the message, and a `role = error` message row alongside. (AC 4)
- **`RecorderTest`** — several tool calls in one turn produce one row each, ordered by `started_at`. (AC 5)
- **`RecorderTest`** — every row for the run carries `message_id` after the turn lands. (AC 7)
- **`ProviderToolTest`** — a synthetic `ProviderToolEvent` sequence (`started` → `completed`) upserts one row keyed by `itemId`, normalizes the status, and keeps `provider_status`. Cover an unknown status mapping to `pending`. (AC 6)
- **`ConversationsEndpointTest`** (Adjust) — the payload carries `tool_invocations` with the documented shape; deletion clears them. (AC 7, 10)
- **`SendTest`** (Adjust) — `tool-input-available` and `tool-output-available` parts appear on the stream in order. (AC 1, 2)

### Browser (`tests/Browser/ChatTest.php`, Adjust)

- Tool call → `@tool-card` appears with the tool name; expand shows `@tool-arguments` and `@tool-result`
- Throwing tool → `@tool-card` carries the error badge **and** `@error-card` is present
- Refresh → cards restored in place
- Provider-tool card renders the provider label
- Both themes

New testids: `tool-card`, `tool-status`, `tool-arguments`, `tool-result`.

### Workbench fixtures

- **`MultiToolAgent`** — two tools invoked in one turn, for ordering (AC 5).
- Existing `SupportAgent` (one tool), `FlakyToolAgent` (throws), `KitchenSinkAgent` (provider tool + sub-agent tool) cover the rest.
- Provider-tool events can't be produced by `Agent::fake()` — the fake gateway yields only text and tool calls — so `ProviderToolTest` drives the recorder with constructed `ProviderToolEvent`s directly.

---

## Risks

| Risk | Mitigation |
|------|------------|
| Nested sub-agent tools clobber `currentToolInvocationId` (§2) | Two-step row resolution, falling back to the newest pending row for the same invocation + tool name. Unverifiable under fakes, so it is documented at the call site as well as here |
| Provider status vocabulary grows | Normalization defaults unknown values to `pending`, which the catch-all can still resolve; the raw string is retained in `provider_status` for diagnosis |
| A card and its assistant text race in the live thread | Both paths run through one reducer keyed on `toolCallId`, and ordering falls out of arrival order live / `started_at` on replay |
| A huge tool result bloats the row and the page | `JsonView` caps rendered height and scrolls; row size is bounded by whatever the tool returned, which is already in the model's context anyway |
| Two cards for one provider tool when `tool_use_id` is absent (§3) | Accepted: two truthful cards beat one card with a mismatched result |

---

## Delivered

Shipped as planned, with three things worth recording:

1. **The thread reducer was restructured.** The plan assumed cards could be slotted in around a single assistant entry, but that would have grouped every card above the whole answer — wrong for a multi-step run, where the model narrates, calls a tool, then narrates again. Entries are now **appended in arrival order and never reordered**, with one assistant entry per generation step (`AssistantEntry.turnId` ties them to a send). Chronology is now a property of the structure rather than something the renderer has to reconstruct.
2. **`provider_status` became a real column**, not a field buried in the payload JSON. It's queryable, it survives in the API, and the UI surfaces it on hover — normalization is for layout, never for hiding what the provider said.
3. **`result` is cast `json`, not `array`.** A tool handler returns a string, and plenty return prose rather than JSON. `JsonView` pretty-prints what parses and shows the rest verbatim.

One test moved from stand-in to real: `ErrorTest`'s dangling-`pending` sweep previously inserted its own row to simulate a recorder that didn't exist yet. The recorder writes it now, so the test asserts the real path.

## Definition of done

- All 11 acceptance criteria verified
- `composer check` green and `composer test:e2e` green
- Feature tests for the recorder and the payload; browser test for the visible flow
- Both themes verified on pending, success, error, and provider variants
- `dist/` rebuilt and committed
- **PRD updated:** Feature 3's `ProviderToolEvent` `type` / `status` claims corrected (§3)
- **GOAL updated:** what a `pending` card means, and the sub-agent-failure-shows-as-success behaviour (Decision 2)
- **FRONTEND.md updated:** `JsonView` row no longer names `@uiw/react-json-view` (Decision 1)
- `plans/PLAN.md` Epic 4 row marked ✅ done
