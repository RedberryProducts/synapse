# Epic 5 — Chat Advanced

**Goal:** send an agent a file, run the same prompt on a different model without touching your code, and watch a reasoning model think out loud — all inside the playground you already have.

Delivers PRD [Feature 2](../../PRD.md#feature-2-chat-playground) (attachments, model selector, reasoning, structured output) · GOAL [Chat playground](../../GOAL.md#chat-playground). Completes the chat surface begun in Epic 3.

**Depends on:** Epic 3 (stream, thread, persistence) · Epic 4 (`JsonView`, tool cards)
**Blocks:** Epic 6 — replay has to render every message type, and after this epic that set is complete.

---

## Design

- **Composer, all five states:** [Chat Input](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=400-6362&m=dev) `400:6362` — [`screenshots/chat-input-variants.png`](screenshots/chat-input-variants.png)
- **Reasoning:** [Thinking state](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=531-5178&m=dev) `531:5178` — [`screenshots/reasoning-pane.png`](screenshots/reasoning-pane.png)
- **Model selector:** [Models](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=355-18178&m=dev) `355:18178`
- **File chip:** [File chip](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=623-27074&m=dev) `623:27074`

| Figma component | Node | Variants |
|-----------------|------|----------|
| `Chat Input` | `400:6362` | Default · Empty · Filled · **File attached** `623:26936` · **Drop File** `623:27150` |
| `Models` | `355:18178` | Collapsed `355:18177` · Open `355:18176` |
| `File chip` | `623:27074` | Default `623:27073` · Variant2 `623:27075` · Variant3 `623:27140` — image / audio / document |
| Reasoning | `531:5178` | `✦ Thinking…` only — an in-file WIP state, never published |

**What the design shows**

- The `+` button opens a three-item menu: **Attach File** · **Audio** · **Image & Video**. Each sets a different accept filter rather than doing anything different afterwards.
- **File attached** puts chips on a row between `+` and the model chip, each with an icon and a `✕`.
- **Drop File** replaces the whole composer with a dashed drop zone and "Drop your file here".
- The **model chip** sits left of Send, showing the current model with a provider glyph and a chevron.
- **Reasoning** is only `✦ Thinking…` in muted text where the answer will appear. There is no expanded state.

**Design gaps handled here** (see [Decisions](#decisions))

- No **expanded** reasoning pane, and no reasoning-token display. The PRD requires both.
- The menu offers **Video**, which the SDK cannot represent.
- No **structured-output JSON card** — PRD lists it under "implement without dedicated designs".

---

## Decisions

To confirm before implementing:

1. **"Image & Video" drops the video.** The SDK models exactly three attachment kinds — `StoredImage`, `StoredDocument`, `StoredAudio` (`File::fromArray()` has no video branch), and no provider in the SDK accepts video on a text call. The menu item becomes **Image**, and video files go through **Attach File** like any other document, where the provider will reject them and the error card will say so. → **DESIGN_FEEDBACK item**, not a silent divergence.
2. **Reasoning gets the designed collapsed state plus an expansion we design.** `✦ Thinking…` while it streams, exactly as drawn; once finished it becomes a `Collapsible` labelled "Thought for Ns" holding the reasoning text, with the reasoning-token count beside the other counts. Built from `Collapsible` + `MessageMeta`, no new Figma dependency.
3. **The model list comes from the agent-detail endpoint**, not a new one — the Info panel already fetches it and the composer needs the same three facts (the agent's own model, its provider's cheapest and smartest, plus configured extras).

---

## Scope

**In**

- Attachments: `+` menu, drag-and-drop, chips, upload to the configured disk, `Stored*` classes, thumbnails on replay, cleanup on prune/clear
- Model selector in the composer; the chosen model recorded per message and shown on replay
- Reasoning: live `Thinking…`, collapsible transcript, reasoning-token count
- Structured-output JSON card (replacing the raw-text rendering Epic 3 shipped)
- `GET /api/attachments/{message}/{index}` to serve a stored file back to the thread

**Out**

- **History list, filters, per-agent resume** (Epic 6)
- Attachment *previews* beyond an inline image thumbnail — no PDF viewer, no audio scrubber; a chip that downloads is enough
- Editing or re-sending a previous message with different attachments
- Model selection that persists across sessions — it resets to the agent's own model each time, because the agent's configuration is the truth and an override is a temporary experiment

---

## Frontend components to use

Layers per [plans/FRONTEND.md](../FRONTEND.md). **Status:** `Done` · `Create` · `Adjust`.

### 1. Elements (`resources/js/elements/`)

| Element | Status | Figma | Used by | Notes |
|---------|--------|-------|---------|-------|
| `Textarea` · `Button` · `Card` · `Badge` | Done | `Chat Input` `400:6362` | composer | — |
| `DropdownMenu` | Done | `Dropdown item` `324:25180` | `+` menu, model selector | Already used by `ConversationMenu` |
| `Collapsible` | Done | — | reasoning transcript | Built in Epic 3 |
| `JsonView` | Done | — | structured-output card | Built in Epic 4 |
| `Copy` · `Tooltip` | Done | — | JSON card, truncated file names | — |
| `Select` | **Create** | `Models` `355:18178` | `ModelSelector` | Collapsed chip + open list with a check on the current entry. Wraps `DropdownMenu`; exists so the model chip isn't a bespoke widget |
| `FileChip` | **Create** | `File chip` `623:27074` | composer, user messages | Icon by kind (image / audio / document), name, optional `✕`. Three variants map to the three kinds the SDK has |

### 2. Components (`resources/js/components/`)

| Component | Status | Figma | Responsibility |
|-----------|--------|-------|----------------|
| `ChatComposer` | **Adjust** | `400:6362` | Add the `+` menu, the attachment chip row, the drop zone, and the model chip — the slots Epic 3 deliberately left empty |
| `AttachmentDropZone` | **Create** | `623:27150` | Full-composer dashed overlay on drag-over; releases into the pending list |
| `ModelSelector` | **Create** | `355:18178` | Current model + the list; emits the chosen model to the page |
| `ReasoningPane` | **Create** | `531:5178` | `✦ Thinking…` while streaming; collapsed "Thought for Ns" after |
| `StructuredOutputCard` | **Create** | — (PRD: no design) | `JsonView` in a titled card |
| `MessageAttachments` | **Create** | `623:27074` | Chips (and inline thumbnails for images) on a user message |
| `AssistantMessage` | **Adjust** | — | Render `ReasoningPane` above the text and `StructuredOutputCard` in place of the raw JSON |
| `UserMessage` | **Adjust** | `324:11771` | Render `MessageAttachments` under the bubble text |
| `MessageMeta` | **Adjust** | `324:12263` | Add reasoning tokens, and the model actually used when it differs from the agent's own |

### 3. Composed (`resources/js/composed/`)

| Component | Status | Figma | Responsibility |
|-----------|--------|-------|----------------|
| `ChatThread` | Done | — | The entry union already covers this epic |
| `PlaygroundShell` | **Adjust** | — | Own the selected model; pass it and the attachments through to `onSend` |

### 4. Pages (`resources/js/pages/`)

| Page | Status | Responsibility |
|------|--------|----------------|
| `Playground` | **Adjust** | Pass attachments + model override into `send()` |

### Data layer

| File | Status | Responsibility |
|------|--------|----------------|
| `types/chat.ts` | **Adjust** | `Attachment`, `ModelOption`; reasoning + attachments on the entries |
| `types/agent.ts` | **Adjust** | `models: ModelOption[]` on `AgentDetail` |
| `lib/stream.ts` | **Adjust** | Send `FormData` when attachments are present; dispatch `reasoning-start` / `-delta` / `-end` |
| `hooks/useConversation.ts` | **Adjust** | `send(message, { attachments, model })`; fold reasoning into the assistant entry; rebuild attachments on replay |
| `lib/api.ts` | **Adjust** | `attachmentUrl(messageId, index)` |

### Styling

`styles/app.css` — the drop-zone dashed border state. No new tokens; the JSON card reuses Epic 4's.

---

## Configuration

**No new keys.** This epic is the first to *read* two that already exist:

| Key | Default | Use here |
|-----|---------|----------|
| `synapse.storage.attachments_disk` | `local` | Where uploads land, under a `synapse/` prefix |
| `synapse.playground.models` | `[]` | Extra models appended to the selector |

| Host-app key | Use here |
|--------------|----------|
| `filesystems.disks.*` | The chosen disk must exist; `StoredImage::toArray()` falls back to `filesystems.default` |

---

## Technical approach

Verified against `references/laravel/ai` at v0.9.1.

### 1. Attachments are mostly the SDK's job

`Promptable::stream(string $prompt, array $attachments = [], …)` takes them directly, and the decorator inherits that signature — Epic 3's invoker just never passed any.

Upload → `Stored*` → `stream()`:

```php
$attachments = collect($request->file('attachments', []))
    ->map(fn (UploadedFile $file) => $this->store($file))   // → synapse/{uuid}.{ext}
    ->all();
```

`StoredImage` / `StoredDocument` / `StoredAudio` each take `(string $path, ?string $disk)` and serialize to `{type, name, path, disk}` — **the exact shape `File::fromArray()` reads back**, which is why the user row stores that verbatim rather than a Synapse-shaped record.

Kind is chosen from the upload's MIME: `image/*` → `StoredImage`, `audio/*` → `StoredAudio`, everything else → `StoredDocument`. No allowlist — an unsupported type reaches the provider and comes back as an error card, which is the honest outcome and (per the PRD) the point of the tool.

**Trap: `MessageHistory` currently drops attachments.** Epic 3 wrote `new Message('user', $content)` unconditionally, which is right only when there are none. It must now mirror `DatabaseConversationStore::rehydrateAttachments()`:

```php
$attachments = collect($record->attachments)
    ->map(File::fromArray(...))
    ->filter()
    ->values();

return $attachments->isNotEmpty()
    ? [new UserMessage($record->content, $attachments)]
    : [new Message('user', $record->content)];
```

Without this, a follow-up turn silently loses the image the developer attached two messages ago — the model would answer about a picture it can no longer see.

**Serving them back.** `GET /api/attachments/{message}/{index}` looks the row up, reads `attachments[$index]`, and streams from `Storage::disk($disk)`. The path is never taken from the request, so there is nothing to traverse.

### 2. Model override

`stream($prompt, $attachments, provider: null, model: $override)` — the SDK's own parameter. Following it through `Promptable::getProvidersAndModels()`:

```php
if (! is_array($provider) && is_null($model)) { /* read the agent's model() / #[Model] */ }
```

A non-null `$model` skips the agent's own resolution, so the override wins without touching the agent. The decorator delegates this method to the wrapped agent with the same arguments, so overrides survive the wrap — covered by `DecoratorTest`.

**Building the list.** The agent's own model, plus its provider's tiers, plus config:

```php
$provider = Ai::textProvider($discovered->provider);

[
    ['id' => $discovered->model, 'label' => $discovered->model, 'tier' => 'agent'],
    ['id' => $provider->cheapestTextModel(), 'tier' => 'cheapest'],
    ['id' => $provider->smartestTextModel(), 'tier' => 'smartest'],
    ...config('synapse.playground.models'),
]
```

De-duplicated by id — an agent already on the cheapest model should see one entry, not two. Resolving the provider can throw for a misconfigured driver, so it degrades to just the agent's own model rather than blanking the composer.

**Recording it.** `meta` already stores the provider and model that actually ran (`Meta::toArray()`), written by Epic 3. `MessageMeta` starts showing it when it differs from the agent's configured model — a replayed conversation should never let you mistake which model produced an answer.

### 3. Reasoning

The events already reach the browser — Epic 3's emitter passes `reasoning-start` / `reasoning-delta` / `reasoning-end` through from the SDK's own serialization, and the client drops them on the floor. This epic gives them handlers.

| Event | Vercel part | Use |
|-------|-------------|-----|
| `ReasoningStart` | `{type: reasoning-start, id}` | open the pane, show `✦ Thinking…` |
| `ReasoningDelta` | `{type: reasoning-delta, id, delta}` | append |
| `ReasoningEnd` | `{type: reasoning-end, id}` | collapse to "Thought for Ns" |

**Trap: reasoning is not persisted today.** The deltas exist only in the stream; `StreamedAgentResponse->text` is `TextDelta::combine()`, which excludes them. So a refresh currently loses the thinking entirely. Two options, and this plan takes the second:

- Store nothing, and accept that reasoning is live-only. Cheap, but a replayed conversation quietly differs from the one you watched.
- **Persist it**: the invoker already iterates every event, so accumulate `ReasoningDelta`s per `reasoningId` and write them to the assistant row's `meta.reasoning`. No schema change (`meta` is a JSON column), and replay matches what you saw.

Reasoning tokens are already stored — `Usage::reasoningTokens` is part of the `usage` JSON Epic 3 writes. `MessageMeta` only has to display it.

### 4. Structured output

Epic 3 emits `data-structured-output` and Epic 4's `JsonView` can render it; Epic 3 currently prints it as a `<pre>` and suppresses the duplicate raw text. This epic swaps the `<pre>` for `StructuredOutputCard`.

It is **not** persisted yet: `StructuredAgentResponse->text` holds the JSON string, so `content` round-trips the data but the parsed array is lost. Store it in `meta.structured` alongside reasoning so replay renders a card rather than falling back to text.

### 5. Multipart

The chat endpoint becomes `multipart/form-data` when files are attached. `$request->validate()` handles both, and `lib/stream.ts` sends `FormData` (omitting `Content-Type` so the browser sets the boundary) only when there is something to attach — a plain text turn keeps its JSON body.

---

## API

### `POST /synapse/api/chat/{agent}/send` (extended)

```jsonc
// multipart/form-data
message:          "What's in this screenshot?"
conversation_id:  "0198f…"        // optional
model:            "gpt-5.6-luna"  // optional override; omit to use the agent's own
attachments[]:    (binary)        // optional, repeated
```

Stream parts are unchanged except that `reasoning-*` now matter to the client.

### `GET /synapse/api/agents/{agent}` (extended)

```jsonc
{
  "models": [
    { "id": "gpt-5.6-luna", "label": "gpt-5.6-luna", "tier": "agent" },   // agent | cheapest | smartest | configured
    { "id": "gpt-5-mini",   "label": "gpt-5-mini",   "tier": "cheapest" }
  ]
}
```

### `GET /synapse/api/conversations/{id}` (extended)

```jsonc
{
  "messages": [
    {
      "role": "user",
      "attachments": [
        { "type": "stored-image", "name": "screenshot.png", "url": "/synapse/api/attachments/0198f…/0" }
      ]
    },
    {
      "role": "assistant",
      "meta": { "provider": "openai", "model": "gpt-5.6-luna", "reasoning": "…", "structured": { } }
    }
  ]
}
```

`path` and `disk` are deliberately **not** exposed — the browser gets a URL, not a filesystem location.

### `GET /synapse/api/attachments/{message}/{index}`

Streams the file with its stored name and MIME. 404 when the row, the index, or the file is gone.

---

## Acceptance criteria

1. The `+` menu offers **Attach File**, **Audio** and **Image**; each opens a picker with the matching filter.
2. Choosing a file shows a chip with its name and kind icon; `✕` removes it before sending.
3. Dragging a file over the composer shows the dashed "Drop your file here" state; dropping adds it as a chip.
4. Sending with an image attached shows the image on the user bubble, and the agent's answer demonstrably refers to it.
5. Reopening that conversation after a refresh still shows the attachment, and a **follow-up turn still has the file in context**.
6. Deleting the conversation removes the stored files, not just the rows.
7. The composer's model chip defaults to the agent's own model and lists the cheapest and smartest tiers plus any configured extras, with no duplicates.
8. Choosing a different model and sending runs on that model; the message records it, and a replay shows which model produced the answer.
9. A reasoning model shows `✦ Thinking…` while it thinks, then a collapsible "Thought for Ns" containing the reasoning, with reasoning tokens in the per-message counts.
10. Refreshing shows the same reasoning transcript that was streamed live.
11. A structured-output agent renders a formatted JSON card, live and on replay, with a working copy button.
12. An attachment the provider rejects produces an inline error card naming the reason, and the thread stays usable.
13. All of the above in **both themes**, behind the `viewSynapse` gate; `/api/attachments/*` is not reachable when the gate denies.

---

## Code map

| Area | Path |
|------|------|
| Upload → `Stored*` | `src/Chat/AttachmentStore.php` |
| Attachments through the invoker | `src/Chat/AgentInvoker.php` (Adjust) |
| Attachment rehydration | `src/Chat/MessageHistory.php` (Adjust) |
| Reasoning + structured persistence | `src/Chat/AgentInvoker.php` · `src/Chat/ConversationWriter.php` (Adjust) |
| Model list | `src/Discovery/ModelOptions.php` · `src/Discovery/AgentDetail.php` (Adjust) |
| Serving files | `src/Http/Controllers/AttachmentsController.php` |
| Request | `src/Http/Controllers/ChatController.php` (Adjust) |
| Composer UI | `resources/js/components/{ChatComposer,AttachmentDropZone,ModelSelector,ReasoningPane,StructuredOutputCard,MessageAttachments}.tsx` |
| Elements | `resources/js/elements/{Select,FileChip}.tsx` |

---

## Tests

### Feature (`tests/Feature/Chat/`)

- **`AttachmentTest`** — an upload lands on the fake disk under `synapse/`; the row stores `{type, name, path, disk}`; the right `Stored*` class is chosen per MIME. (AC 2, 4)
- **`AttachmentTest`** — `MessageHistory` rehydrates a `UserMessage` with its files, so a second turn still carries them. **The one that matters** — losing it is invisible until a model answers about an image it can't see. (AC 5)
- **`AttachmentTest`** — `synapse:clear` and conversation delete remove the files from the disk. (AC 6)
- **`AttachmentsEndpointTest`** — serves the file with its name/MIME; 404s on unknown row, index and missing file; forbidden when the gate denies. (AC 13)
- **`ModelOverrideTest`** — sending with `model` invokes on that model (asserted through the prompt the gateway received) and records it in `meta`. (AC 8)
- **`ModelOptionsTest`** — the list contains agent + cheapest + smartest + configured, de-duplicated, and degrades to the agent's own model when the provider can't be resolved. (AC 7)
- **`ReasoningTest`** — reasoning deltas are stored on the assistant row and returned by the replay endpoint. (AC 9, 10)
- **`StructuredOutputTest`** (Adjust) — the parsed payload is persisted, not just the text. (AC 11)

### Browser (`tests/Browser/ChatTest.php`, Adjust)

- Attach a file → `@file-chip` appears, `✕` removes it
- Send with an attachment → `@message-attachments` on the user bubble; still there after refresh
- Model selector → open, pick, send; `@message-meta` names the model used
- Reasoning → `@reasoning` shows Thinking, then the collapsed transcript
- Structured agent → `@structured-card`
- Both themes

New testids: `file-chip`, `drop-zone`, `model-selector`, `reasoning`, `structured-card`, `message-attachments`.

### Workbench fixtures

- **`VisionAgent`** — stateless, no tools, instructions about describing images. Drives the attachment path.
- **`ReasoningAgent`** — provider options requesting reasoning, for the pane.
- `Agent::fake()` produces no `ReasoningDelta`s, so `ReasoningTest` drives the invoker with constructed events, as `ProviderToolTest` does for provider tools.

---

## Risks

| Risk | Mitigation |
|------|------------|
| Attachments silently dropped from history (§1) | A dedicated test asserting the rehydrated `UserMessage` carries its files; the failure is otherwise invisible until a model contradicts itself |
| Reasoning lost on refresh | Persisted to `meta.reasoning` — no schema change, and replay matches the live view |
| A large upload exhausts memory or the request limit | Files stream to disk via Laravel's own upload handling and are never read into a string by Synapse; PHP's `upload_max_filesize` remains the limit, and exceeding it surfaces as a validation error rather than a stack trace |
| An attachment URL leaks a filesystem path | The API returns a URL only; `path`/`disk` never leave the server |
| Video files reach a provider that rejects them (Decision 1) | Intended — the error card explains it, exactly as production would |

---

## Definition of done

- All 13 acceptance criteria verified
- `composer check` green and `composer test:e2e` green
- Feature tests for upload, rehydration, cleanup, override and reasoning; browser test for the visible flow
- Both themes verified on chips, drop zone, selector, reasoning and JSON card
- `dist/` rebuilt and committed
- **DESIGN_FEEDBACK.md updated:** the Video menu item (Decision 1) and the reasoning expansion we designed ourselves (Decision 2)
- **PRD updated** if any SDK finding contradicts Feature 2
- **GOAL updated:** attachments, model override, reasoning, and what happens to an attachment a provider rejects
- `plans/PLAN.md` Epic 5 row marked ✅ done
