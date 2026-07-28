# Epic 3 — Chat MVP

**Goal:** open an agent's playground, send it a message, and watch the real answer stream in — with token counts, inline error cards, and a thread that survives a refresh.

Delivers PRD [Feature 2](../../PRD.md#feature-2-chat-playground) · [Feature 6](../../PRD.md#feature-6-error-display) · [Feature 7](../../PRD.md#feature-7-token-counter) · GOAL [Chat playground](../../GOAL.md#chat-playground) · Success Criteria **#2** (chat works) and **#5** (token awareness); lays the persistence half of **#6**.

**Depends on:** Epic 1 (slug → class resolution, capability flags) · Epic 2 (playground shell, Info panel, `conversational` flag in the detail payload)
**Blocks:** Epic 4 (tool cards need the stream + the recorder hook), Epic 5, Epic 6 (history replays what this epic writes).

---

## Design

- **Empty:** [Playground_Empty](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=307-2834&m=dev) `307:2834` — [`screenshots/playground-empty.png`](screenshots/playground-empty.png)
- **Conversation:** [Playground_Conversation](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=324-11632&m=dev) `324:11632` — [`screenshots/playground-conversation.png`](screenshots/playground-conversation.png)
- **Full width (per-message tokens, filled composer):** [324:12263](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=324-12263&m=dev) — [`screenshots/playground-full-width.png`](screenshots/playground-full-width.png)

| Figma component | Node | Variants |
|-----------------|------|----------|
| `Chat Input` | `400:6362` | **Default** · **Empty** · **Filled** · *File attached* / *Drop File* (Epic 5) |
| Conversation token row | `453:5296` | `Total` (Database) · `Prompt` (SquareGanttChart) · `Completion` (CopyCheck) |
| User bubble | `324:11771` | right-aligned, muted surface, rounded — no avatar |
| Assistant turn | `324:12235` | plain markdown, full width, **no** bubble |
| Per-message meta | `324:12263` | `Prompt: 142   Completion: 89   Total: 231` |
| Hero empty state | `429:5327` | sparkle mark + “Explore, test, and debug your AI agents” + centred composer |
| `More` + `Dropdown item` | `656:7139` · `324:25180` | conversation actions menu |

**What the design shows**

- The header carries the agent name + provider chip, and directly under it the **conversation** token row. Per-message counts sit under the assistant turn.
- The composer is a rounded panel pinned to the bottom: `+` attach (Epic 5) bottom-left, model chip (Epic 5) and red `Send →` bottom-right. In *Filled* it grows to several lines and scrolls.
- The empty state replaces the thread with a centred hero and centres the composer vertically.

**Design gaps handled here** (see [Decisions](#decisions))

- No **New / Clear conversation** control exists anywhere in the designs.
- No **agent-level error card** — PRD explicitly lists it under [“Implement without dedicated designs”](../../PRD.md#implement-without-dedicated-designs-use-existing-design-language).
- No **Stateless** badge — new, built from `Badge`.
- The empty screen puts the provider chip *above* the agent name; the conversation screen puts it *beside*. We follow the conversation screen in both, since the header is one component.

---

## Decisions

Confirmed before planning:

1. **Hand-rolled SSE reader, no `ai` / `@ai-sdk/react` dependency.** `dist/app.js` is inlined into every dashboard page load, and since Synapse emits the stream itself, `useChat()` would only be buying us the parsing half. We keep emitting **Vercel-protocol part names** (via the SDK's own `toVercelProtocolArray()`) so the wire format stays the SDK's, and consume them with ~120 lines in `lib/stream.ts`. → **PRD update:** Feature 2 currently names `useChat()`; reword to "a Vercel-UI-message-protocol stream consumed by Synapse's own reader".
2. **Structured-output agents fall back to `prompt()`.** The SDK *throws* on streaming them (see [Technical approach §2](#2-structured-output-agents-cannot-stream)). Detect `HasStructuredOutput`, call `prompt()`, and emit the result as a single text part so one pipeline serves both. Epic 5 upgrades the rendering to a JSON card.
3. **New / Clear conversation live in a `⋮` More menu** in the playground header, beside the Info button — reusing the `More` + `Dropdown item` components the sidebar already uses. Epic 6 adds Rename to the same menu.
4. **A fresh playground starts a fresh thread; the conversation id goes in the URL.** `/playground/{slug}` opens empty. The first send returns a conversation id which is pushed to `?c={id}`, so refresh and deep links restore that exact thread. Reopening an old conversation is Epic 6's sidebar/history job — nothing resurrects unbidden.

---

## Scope

**In**

- Conversation + message persistence (`synapse_conversations`, `synapse_messages`)
- `SynapseConversationalAgent` decorator + the conversational/stateless split, with the **Stateless** notice in the UI
- The single invocation-level catch-all (PRD Feature 6) and the inline error card
- SSE emitter speaking the Vercel UI-message protocol, including the two parts the SDK drops
- `POST /api/chat/{agent}/send`, `GET /api/conversations/{id}`, `DELETE /api/conversations/{id}`
- Chat UI: hero empty state, thread, streaming assistant text, composer, per-message + conversation token counts, error cards, New/Clear conversation
- Thread survives refresh via `?c={id}`

**Out**

- **Inline tool cards** (Epic 4) — tool *events* still stream and the catch-all still marks failures; nothing renders them yet
- **Attachments**, **model selector**, **reasoning pane**, **structured-output JSON card** (Epic 5) — the composer leaves visual slots for `+` and the model chip but does not build them
- **History list, search, rename, sidebar recents** (Epic 6)
- `SynapseRecorder` and `synapse_tool_invocations` writes (Epic 4) — except the catch-all's "flip dangling pending rows" clause, which is written here because it belongs to the catch-all
- Conversation titles beyond "truncated first user message"

---

## Frontend components to use

Layers per [plans/FRONTEND.md](../FRONTEND.md). **Status:** `Done` = exists, used as-is · `Create` = build it · `Adjust` = exists, needs the stated change.

### 1. Elements (`resources/js/elements/`)

| Element | Status | Figma | Used by | Notes |
|---------|--------|-------|---------|-------|
| `Button` | Done | `CTA Button` `279:2791` | Send, header actions | `primary` variant is already the accent red |
| `Card` | Done | `Card` `367:11252` | error card, composer panel | — |
| `Badge` | Done | `Tool Tag` `171:1262` | Stateless notice | — |
| `Markdown` | Done | — | assistant turns | Already used by the Prompt tab |
| `Copy` | Done | `Copy` `408:5907` | error message / stack trace | — |
| `DropdownMenu` | Done | `More` `329:3275`, `Dropdown item` `324:25180` | conversation actions | — |
| `Tooltip` · `Skeleton` | Done | — | token labels, thread loading | — |
| `Textarea` | **Create** | `Chat Input` `400:6362` | `ChatComposer` | Auto-growing to a max height then scrolls; `Enter` sends, `Shift+Enter` newline |
| `Collapsible` | **Create** | `Inline Tool Call cards` `271:2353` | `ErrorCard` | **Moved forward from Epic 4** — the error card needs a collapsible stack trace. Update the inventory row in [FRONTEND.md](../FRONTEND.md) from epic 4 → 3 |

### 2. Components (`resources/js/components/`)

| Component | Status | Figma | Responsibility |
|-----------|--------|-------|----------------|
| `ChatComposer` | **Create** | `Chat Input` `400:6362` | Textarea + Send. Renders the disabled-looking left/right slots only when their epic lands — no dead controls in Epic 3 |
| `UserMessage` | **Create** | `324:11771` | Right-aligned bubble |
| `AssistantMessage` | **Create** | `324:12235` | Markdown body + `MessageMeta` footer; shows a caret while streaming |
| `MessageMeta` | **Create** | `324:12263` | `Prompt: n · Completion: n · Total: n` |
| `ConversationTokens` | **Create** | `453:5296` | Header row: Total / Prompt / Completion with their lucide icons, `k`-abbreviated |
| `ErrorCard` | **Create** | — (PRD: no design) | Exception class + message always visible, stack trace in a `Collapsible`, copy button. `recoverable` mid-stream errors get the quieter weight |
| `ChatEmptyState` | **Create** | `429:5327` | Sparkle mark + headline. Distinct from `EmptyState` (dashed box) — this is a hero |
| `StatelessNotice` | **Create** | — | “Stateless — each message is sent independently.” Only for agents that are not `Conversational` |
| `ConversationMenu` | **Create** | `656:7139` | `⋮` → New conversation · Clear conversation (with confirm) |

### 3. Composed (`resources/js/composed/`)

| Component | Status | Figma | Responsibility |
|-----------|--------|-------|----------------|
| `ChatThread` | **Create** | `324:11632` | Ordered render of user / assistant / error entries + the in-flight assistant turn; sticks to the bottom while streaming unless the user has scrolled up |
| `PlaygroundShell` | **Adjust** | `324:11632` | Replace the “arrives in the next release” placeholder with `ChatThread` + `ChatComposer`; add `ConversationTokens` and `ConversationMenu` to the header |
| `InfoPanel` | Done | `248:5998` | Unchanged |

### 4. Pages (`resources/js/pages/`)

| Page | Status | Responsibility |
|------|--------|----------------|
| `Playground` | **Adjust** | Owns `useConversation`; syncs `?c={id}` with `useSearchParams`; passes thread + send/new/clear handlers down |

### Data layer

| File | Status | Responsibility |
|------|--------|----------------|
| `types/chat.ts` | **Create** | `ChatEntry` union (user / assistant / error), `Usage`, `Conversation`, and the stream part types |
| `lib/stream.ts` | **Create** | `streamChat(url, body, handlers)` — `fetch` + `ReadableStream` + an SSE line parser dispatching by part `type` |
| `hooks/useConversation.ts` | **Create** | Thread state, send, new, clear, load-by-id; the reducer that folds stream parts into entries |
| `lib/api.ts` | **Adjust** | Add `getConversation(id)`, `deleteConversation(id)`; export `chatUrl(slug)` for the streaming call, which bypasses the JSON `api()` helper |

### Styling

`styles/app.css` — add the streaming caret keyframe and the error-card surface tokens (`--color-danger-*` if not already present from the tool-card palette). No new colours: both are drawn from the Figma red used by the failed tool result block.

---

## Configuration

**This epic introduces no new config keys.**

| Key | Default | Use here |
|-----|---------|----------|
| `synapse.storage.connection` | `null` | Read indirectly — every model resolves it in `SynapseModel::getConnectionName()` |
| `synapse.ui.path` / `synapse.ui.middleware` | `synapse` / `['web']` | The chat route lives in the same group as the rest |

| Host-app key | Use here |
|--------------|----------|
| `ai.default` | Read by the SDK when an agent declares no provider — Synapse never writes it |

`synapse.playground.models` and `synapse.storage.attachments_disk` exist but are **not read until Epic 5**.

---

## Technical approach

Every claim below was verified against `references/laravel/ai` at v0.9.1; file/line references are to that copy.

### 1. The decorator is riskier than the PRD sketch suggests

The PRD's `SynapseConversationalAgent` forwards `instructions()`, `messages()`, `tools()`. That is not enough: **the SDK reflects on the agent instance in six separate places**, and each one sees the decorator's class, not the wrapped agent's.

| SDK call site | Reads | If not forwarded |
|---------------|-------|------------------|
| `Promptable::getProvidersAndModels()` | `provider()` / `model()` methods, else `#[Provider]` / `#[Model]` | Agent silently runs on `config('ai.default')` — the playground would use a different model than the Info panel reports |
| `Promptable::getTimeout()` | `timeout()`, else `#[Timeout]` | Falls back to 60s |
| `Promptable::getDefaultModelFor()` | `#[UseSmartestModel]` / `#[UseCheapestModel]` | Tier attributes lost |
| `TextGenerationOptions::forAgent()` | `maxSteps()` `maxTokens()` `temperature()` `topP()` `toolChoice()`, else the matching attribute | All generation settings lost |
| `Strict::isAppliedTo($options->agent)` | `#[Strict]` — **attribute only, no method fallback** | Strict tool/output schemas silently disabled |
| `Ai::hasFakeGatewayFor($agent::class)` | the class name | `Agent::fake()` does not apply — a test would hit the real provider |

Every row except `#[Strict]` is fixable because the SDK checks a **method before** the attribute, and because `getTimeout()` / `getDefaultModelFor()` / `getProvidersAndModels()` are `protected` on the `Promptable` trait the decorator itself uses.

`#[Strict]` is attribute-only, so it needs a second class:

```php
// src/Chat/SynapseConversationalAgent.php
class SynapseConversationalAgent implements Agent, Conversational, HasTools, HasMiddleware, HasProviderOptions
{
    use Promptable;

    protected TextGenerationOptions $options;

    /** @param  Message[]  $history */
    public function __construct(protected Agent $agent, protected array $history)
    {
        // Re-run the SDK's own resolution against the REAL agent, once.
        $this->options = TextGenerationOptions::forAgent($agent);
    }

    /** Pick the variant that preserves #[Strict]. */
    public static function for(Agent $agent, array $history): self
    {
        return Strict::isAppliedTo($agent)
            ? new StrictSynapseConversationalAgent($agent, $history)
            : new self($agent, $history);
    }

    public function instructions(): string { return $this->agent->instructions(); }

    public function messages(): iterable { return $this->history; }

    public function tools(): iterable
    {
        return $this->agent instanceof HasTools ? $this->agent->tools() : [];
    }

    public function middleware(): array
    {
        return $this->agent instanceof HasMiddleware ? $this->agent->middleware() : [];
    }

    public function providerOptions(Lab|string $provider): ?array
    {
        return $this->agent instanceof HasProviderOptions
            ? $this->agent->providerOptions($provider)
            : null;
    }

    // Generation settings — method wins over attribute in the SDK, so these
    // are enough; the decorator never needs the attributes themselves.
    public function maxSteps(): ?int { return $this->options->maxSteps; }
    public function maxTokens(): ?int { return $this->options->maxTokens; }
    public function temperature(): ?float { return $this->options->temperature; }
    public function topP(): ?float { return $this->options->topP; }
    public function toolChoice(): ?ToolChoice { return $this->options->toolChoice; }   // ToolChoice::from() accepts an instance

    // Provider/model/timeout/tier — delegate to the real agent's own resolution.
    protected function getProvidersAndModels($provider, $model): array { /* bound closure onto $this->agent */ }
    protected function getTimeout(?int $timeout): int { /* ditto */ }
    protected function getDefaultModelFor(TextProvider $provider): string { /* ditto */ }
}

#[Strict]
final class StrictSynapseConversationalAgent extends SynapseConversationalAgent {}
```

The three `protected` overrides delegate by binding a closure to the wrapped agent, so the SDK's own logic runs unchanged rather than being reimplemented:

```php
protected function getTimeout(?int $timeout): int
{
    return Closure::bind(
        fn (): int => $this->getTimeout($timeout),
        $this->agent,
        $this->agent::class,
    )();
}
```

**Interfaces the decorator implements unconditionally, and why that is safe:**
`HasTools` → `resolveTools()` gets `[]` for a non-tool agent, identical to not implementing it. `HasMiddleware` → `[...$middleware, ...[]]`. `HasProviderOptions` → `providerOptions()` returns `null`, the same as the non-implementing branch.

**Interfaces it must NOT implement:**
`HasStructuredOutput` — `StreamsText::stream()` throws for any agent implementing it (§2 below), so a decorator carrying it would break *every* conversational stream. `RemembersConversations` — deliberately absent: `GeneratesText::gatherMiddlewareFor()` checks `class_uses_recursive($agent)` for the trait, so leaving it off is exactly what keeps the SDK's `RememberConversation` middleware and the developer's own `ConversationStore` out of the picture, as the PRD intends.

**Testing trap.** `Ai::hasFakeGatewayFor()` keys on the concrete class, so `SupportAgent::fake()` does **not** cover the decorated call. Tests register the fake against all three:

```php
// tests/Pest.php
function fakeAgent(string $agent, array|Closure $responses = []): void
{
    foreach ([$agent, SynapseConversationalAgent::class, StrictSynapseConversationalAgent::class] as $class) {
        Ai::fakeAgent($class, $responses);
    }
}
```

### 2. Structured-output agents cannot stream

```php
// Providers/Concerns/StreamsText.php
if ($agent instanceof HasStructuredOutput) {
    throw new InvalidArgumentException('Streaming structured output is not currently supported.');
}
```

So the invoker branches before choosing a transport:

```php
$response = $agent instanceof HasStructuredOutput
    ? $this->promptOnce($agent, $message)   // AgentResponse → one text part + finish
    : $target->stream($message);            // StreamableAgentResponse
```

`prompt()` also means no decorator for these agents in Epic 3 — `GeneratesText::prompt()` reads `$agent->messages()` the same way, so a structured **and** conversational agent still needs one; it gets a third variant carrying `HasStructuredOutput`. Deferred: no workbench fixture is both, and Epic 5 owns structured output properly. Epic 3 sends structured agents through undecorated `prompt()` and shows the Stateless notice, which is honest for the fixtures we have.

### 3. The stream, and the two parts the SDK drops

`StreamableAgentResponse` is an `IteratorAggregate`; iterating it resolves the generator, accumulates `$events`, computes `text` + `usage` via `TextDelta::combine()` / `StreamEnd::combineUsage()`, then fires the `then()` callbacks. So:

```php
$response = $target->stream($message);
$invocationId = $response->invocationId;          // available immediately

$response->withinConversation($conversationId)
         ->then(fn (StreamedAgentResponse $r) => $this->persistAssistantTurn($r, ...));

foreach ($response as $event) {
    $emitter->write($event);
}
```

`write()` mirrors `Responses/Concerns/CanStreamUsingVercelProtocol` — **do not** return the SDK's `Responsable`, because we must add parts it cannot emit:

| Event | SDK's `toVercelProtocolArray()` | Synapse |
|-------|--------------------------------|---------|
| `StreamStart` | `{type: start, messageId}` | pass through, **first one only** (fires once per step) |
| `TextStart/Delta/End` | `text-start` / `text-delta` / `text-end` | pass through |
| `ReasoningStart/Delta/End` | `reasoning-*` | pass through (rendered in Epic 5) |
| `ToolCall` | `tool-input-available` | pass through; record the id |
| `ToolResult` | **always** `tool-output-available` | emit `tool-output-error` instead when `$event->successful === false` — the SDK discards `successful`/`error`. Skip results with no matching call id, as the SDK does |
| `Citation` | `source-url` | pass through |
| `Error` | `{type: error, errorText}` | pass through **and** persist a `role = error` row |
| `ProviderToolEvent` | **`null`** — silently skipped | emit `{type: 'data-provider-tool', data: $event->toArray()}` |
| `StreamEnd` | `{type: finish}` | hold until last, as the SDK does; carries the accumulated `Usage` we persist |

Two Synapse-only parts bracket the stream:

- `data-synapse-start` — `{conversationId, userMessageId}`, written **before** the first SDK part so the client can push `?c={id}` immediately.
- `data-synapse-end` — `{assistantMessageId, usage, durationMs}`, written from the `then()` callback so the UI can swap the in-flight turn for its persisted identity.

Headers match the SDK's: `Content-Type: text/event-stream`, `Cache-Control: no-cache, no-transform`, `x-vercel-ai-ui-message-stream: v1`, plus `X-Accel-Buffering: no`. Terminate with `data: [DONE]`.

**Trap:** a `response()->stream()` closure runs *after* the response headers are sent, so nothing thrown inside it can become an HTTP status. That is precisely why the catch-all (§5) has to emit an error *part* — throwing would truncate the stream with no explanation.

### 4. History rebuild mirrors the SDK's own store

`Storage/DatabaseConversationStore::getLatestConversationMessages()` is the canonical round-trip; `src/Chat/MessageHistory.php` reproduces its `flatMap` verbatim against `synapse_messages`:

- `role = user` → `UserMessage` (or bare `Message('user', …)` when there are no attachments, as the SDK does)
- assistant **with** `tool_calls` and `tool_results` → `AssistantMessage('', ToolCall::fromArray…)` → `ToolResultMessage(ToolResult::fromArray…)` → `AssistantMessage($content)` when text is present
- assistant with tool calls but no results → text-only `AssistantMessage`, or nothing
- otherwise → `AssistantMessage($content)`
- `role = error` rows are **skipped** — they are Synapse observations, never model context

Ordering is `orderBy('id')`: uuid7 keys are k-sortable, so id order *is* chronological. Cap at 100 messages, matching `maxConversationMessages()`.

### 5. One catch-all, wrapping everything

```php
try {
    // resolve → build history → decorate → stream/prompt → iterate → emit → persist
} catch (Throwable $e) {
    $errorMessage = $this->writer->storeError($conversationId, $e);

    SynapseToolInvocation::query()
        ->where('invocation_id', $invocationId)
        ->where('status', 'pending')
        ->update(['status' => 'error', 'error' => $e->getMessage(), 'finished_at' => now()]);

    $emitter->error($e, $errorMessage->id);
}
```

Verified: `Gateway\Concerns\InvokesTools::executeTool()` wraps `$tool->handle()` in `try/finally` with **no catch**, and `TextGenerationLoop` hard-codes `successful: true` on every `ToolResult` event. There is no "failed tool result" path in 0.9.1 — a throwing tool exits `stream()` entirely. That is why one catch-all around the whole pipeline is the entire error strategy, and why the dangling-`pending` sweep lives inside it even though the rows themselves are Epic 4's.

Failover is *not* an error: `AgentFailedOver` fires from `Promptable::recordAgentFailover()` and the run continues. Epic 3 listens and emits `data-synapse-notice`; the UI renders it as an informational line, not a red card.

### 6. Persistence

| When | Row |
|------|-----|
| Before the stream opens | `synapse_conversations` (if new; `title` = first user message truncated to 80 chars) + `synapse_messages` `role = user` |
| `then()` at stream close | `role = assistant` with `content`, `tool_calls`/`tool_results` (`toArray()` shapes), `usage` (`Usage::toArray()`, all five fields), promoted `prompt_tokens` / `completion_tokens`, `duration_ms`, `meta` (provider + model actually used) |
| Catch-all | `role = error` with `metadata: {exception_class, stack_trace}` |
| Any write | `conversations.updated_at` touched — retention and the Epic 6 list both order by it |

The user row is written **before** the stream so a mid-flight crash still leaves a readable thread.

### 7. Client

`lib/stream.ts` — `fetch(url, {method:'POST', body, headers})`, then `response.body.getReader()` + `TextDecoderStream`, split on `\n\n`, strip `data: `, `JSON.parse`, stop at `[DONE]`. Each part is dispatched to a handler map; unknown `type`s are ignored (forward compatibility with parts Epics 4–5 add).

`useConversation` folds parts into a `ChatEntry[]`:
`text-delta` appends to the open assistant entry (keyed by part `id`, since a multi-step run emits one text block per step); `error` pushes an error entry; `data-synapse-start` sets the conversation id; `finish`/`data-synapse-end` closes the turn and attaches usage. `AbortController` cancels in flight when the user navigates away or starts a new conversation.

---

## API

### `POST /synapse/api/chat/{agent}/send`

```jsonc
// request (application/json — multipart arrives in Epic 5)
{
  "message": "Why did the last support ticket fail?",
  "conversation_id": "0198f...".  // omit to start a new conversation
}
```

```
// response: text/event-stream
data: {"type":"data-synapse-start","data":{"conversationId":"0198f…","userMessageId":"0198f…"}}

data: {"type":"start","messageId":"01K2…"}

data: {"type":"text-start","id":"01K2…"}

data: {"type":"text-delta","id":"01K2…","delta":"I "}

data: {"type":"text-end","id":"01K2…"}

data: {"type":"finish"}

data: {"type":"data-synapse-end","data":{"assistantMessageId":"0198f…","usage":{"prompt_tokens":142,"completion_tokens":89,"cache_write_input_tokens":0,"cache_read_input_tokens":0,"reasoning_tokens":0},"durationMs":1840}}

data: [DONE]
```

Error part (also persisted as a `role = error` row):

```jsonc
{"type":"error","errorText":"Rate limit exceeded for anthropic.",
 "data":{"messageId":"0198f…","exceptionClass":"Laravel\\Ai\\Exceptions\\RateLimitException",
         "stackTrace":"#0 …","recoverable":false}}
```

### `GET /synapse/api/conversations/{id}`

```jsonc
{
  "id": "0198f…",
  "agent_slug": "app.agents.support-agent",     // resolved back from agent_class
  "agent_class": "App\\Agents\\SupportAgent",
  "title": "Why did the last support ticket fail?",
  "created_at": "2026-07-28T10:14:02+00:00",
  "totals": { "prompt_tokens": 3821, "completion_tokens": 234, "total_tokens": 4055 },
  "messages": [
    { "id": "…", "role": "user", "content": "…", "created_at": "…" },
    { "id": "…", "role": "assistant", "content": "…",
      "usage": { "prompt_tokens": 142, "completion_tokens": 89, "…": 0 },
      "duration_ms": 1840,
      "meta": { "provider": "openai", "model": "gpt-5.6-luna" },
      "created_at": "…" },
    { "id": "…", "role": "error", "content": "Rate limit exceeded…",
      "metadata": { "exception_class": "…", "stack_trace": "…" }, "created_at": "…" }
  ]
}
```

404 when the id is unknown. `messages` is ordered by `id` (uuid7 ⇒ chronological).

### `DELETE /synapse/api/conversations/{id}`

`204`. Goes through `ConversationRepository::deleteConversations()` so messages, tool rows, and (from Epic 5) attachment files all cascade in the repository layer.

---

## Acceptance criteria

1. Opening `/synapse/playground/{slug}` with no `?c` shows the hero empty state and a focused composer; the thread area is empty.
2. Typing a message and pressing **Send** (or `Enter`) appends the user bubble immediately and begins streaming the assistant's answer token by token; `Shift+Enter` inserts a newline instead of sending.
3. While streaming, the Send control is disabled and the in-flight assistant turn shows a caret; when the stream closes the caret disappears and `Prompt: n · Completion: n · Total: n` appears under the turn.
4. The header shows `Total` / `Prompt` / `Completion` for the whole conversation, updating after each turn.
5. After the first send the URL carries `?c={id}`; **refreshing the page restores the full thread** — user turns, assistant turns, and any error cards, in order.
6. A **conversational** agent (`SupportAgent`) answers a second message with the first turn in context — asserted by inspecting the messages the faked gateway received, not by the model's wording.
7. A **stateless** agent (`WeatherAgent`) shows the Stateless notice, and the faked gateway receives **only** the current message on the second turn — no prior turns.
8. An agent whose tool throws renders an error card naming the exception class and message, with the stack trace hidden behind a collapsible control; the thread stays usable and the next message still sends.
9. A provider failure before any output renders the same error card rather than a broken stream or an HTTP error page.
10. `⋮ → New conversation` clears the thread, drops `?c` from the URL, and leaves the previous conversation in the database; `⋮ → Clear conversation` deletes it and its messages, then returns to the empty state.
11. `ExtractorAgent` (structured output) answers without throwing "Streaming structured output is not currently supported".
12. Everything above renders correctly in **both themes**, and the whole surface stays behind the `viewSynapse` gate — an unauthorised request to `POST /api/chat/{agent}/send` is rejected like every other Synapse route.

---

## Code map

| Area | Path |
|------|------|
| Invocation pipeline + catch-all | `src/Chat/AgentInvoker.php` |
| History decorator (+ strict variant) | `src/Chat/SynapseConversationalAgent.php` |
| History rebuild from `synapse_messages` | `src/Chat/MessageHistory.php` |
| Vercel-protocol SSE writer | `src/Chat/StreamEmitter.php` |
| Row writes (conversation / user / assistant / error) | `src/Chat/ConversationWriter.php` |
| Failover notice listener | `src/Chat/Listeners/RecordFailover.php` |
| Chat endpoint | `src/Http/Controllers/ChatController.php` |
| Conversation read + delete | `src/Http/Controllers/ConversationsController.php` |
| Cascade delete (existing) | `src/Repositories/ConversationRepository.php` |
| Routes | `routes/web.php` — replace the three chat/conversation stubs |
| Bindings | `src/SynapseServiceProvider.php` — register the listener |
| Stream reader · thread state | `resources/js/lib/stream.ts` · `resources/js/hooks/useConversation.ts` |
| Chat UI | `resources/js/components/{ChatComposer,UserMessage,AssistantMessage,MessageMeta,ConversationTokens,ErrorCard,ChatEmptyState,StatelessNotice,ConversationMenu}.tsx` · `resources/js/composed/ChatThread.tsx` |

---

## Tests

### Feature (`tests/Feature/Chat/`)

- **`SendTest`** — a faked agent streams; response is `text/event-stream`; parts arrive in order; `data-synapse-start` carries a conversation id; `[DONE]` terminates. (AC 2, 3)
- **`PersistenceTest`** — one send writes a conversation + user row + assistant row; `prompt_tokens` / `completion_tokens` / `duration_ms` / `meta` are populated from `Usage` and `Meta`; `updated_at` is touched. (AC 5)
- **`ConversationalTest`** — second turn on `SupportAgent`: assert via `Agent::assertPrompted` / the fake gateway that the message list contains the first turn. (AC 6)
- **`StatelessTest`** — second turn on `WeatherAgent`: assert the gateway received exactly one `UserMessage` and no prior turns. **This is the epic's most important test** — it is the whole conversational/stateless promise. (AC 7)
- **`DecoratorTest`** — wrap `ConfiguredAgent` (every generation option + `#[Timeout]` + provider options + middleware) and assert `TextGenerationOptions::forAgent($decorator)` and the resolved provider/model/timeout are **identical** to the undecorated agent. Plus a `#[Strict]` fixture asserting `Strict::isAppliedTo()` survives wrapping. This is the regression net for §1. (AC 6)
- **`ErrorTest`** — a tool that throws produces a `role = error` row with `exception_class` + `stack_trace`, an `error` SSE part, and flips dangling `pending` tool rows; a provider failure before any output does the same. (AC 8, 9)
- **`StructuredOutputTest`** — `ExtractorAgent` returns a response instead of throwing the streaming exception. (AC 11)
- **`ConversationsEndpointTest`** — `GET` returns the ordered thread with totals; 404 on unknown id; `DELETE` cascades messages and tool rows. (AC 5, 10)
- **`AuthorizationTest`** — the chat route is behind `viewSynapse`. (AC 12)

### Browser (`tests/Browser/ChatTest.php`)

Driven by the shared `fakeAgent()` helper so nothing touches a provider. Targeting via `data-testid`, content asserted as scoped text (AGENTS.md → Writing browser tests).

- Empty playground → hero state present (`@chat-empty`)
- Type + send → user bubble appears, assistant text lands, `@message-meta` shows the counts
- Header `@conversation-tokens` updates after the turn
- Refresh with `?c={id}` → thread intact
- Stateless agent → `@stateless-notice` present; conversational agent → absent
- Throwing tool → `@error-card` with the exception class; expanding reveals the trace
- `⋮ → New conversation` → back to `@chat-empty`, no `?c` in the URL
- Both themes on the conversation view

New testids: `chat-empty`, `chat-composer`, `chat-thread`, `message-user`, `message-assistant`, `message-meta`, `conversation-tokens`, `error-card`, `stateless-notice`, `conversation-menu`.

### Workbench fixtures

- **`FlakyToolAgent`** — conversational, one tool whose `handle()` throws `RuntimeException('Ledger service unavailable')`. Drives AC 8 and the dangling-`pending` sweep.
- **`StrictAgent`** — `#[Strict]` + one tool, for the decorator regression test.
- Existing `SupportAgent` (conversational + tool), `WeatherAgent` (stateless), `ExtractorAgent` (structured), `ConfiguredAgent` (every option) cover the rest.

---

## Risks

| Risk | Mitigation |
|------|------------|
| The decorator silently changes how an agent runs (§1) | `DecoratorTest` asserts option-by-option parity against the undecorated agent, so a future SDK reflection point that we miss fails loudly rather than quietly using the wrong model |
| SSE doesn't flush under Pest 4's in-process browser harness | The reader renders the same final state whether parts arrive incrementally or in one chunk, so browser assertions target the settled thread. If incremental flush proves untestable, streaming granularity is covered by the feature test reading the raw response body instead |
| `Ai::fakeAgent` keying breaks a test into hitting a real provider | The shared `fakeAgent()` helper registers all decorator classes; `preventStrayPrompts()` in `tests/Pest.php` turns any miss into an immediate exception rather than a network call |
| PHP output buffering / a proxy holds the stream | `X-Accel-Buffering: no` + `no-transform`, and an explicit `ob_flush()`/`flush()` per part in the emitter. Documented in GOAL as a note for developers behind nginx |
| A developer's `Conversational` agent implements `messages()` itself and we override it | Intended per PRD (Synapse supplies its own thread), but surprising. The Info panel already reports `conversational`; add a line to GOAL explaining that Synapse feeds its own history to conversational agents |
| Long threads grow the prompt without bound | Cap history at 100 messages, matching the SDK's `maxConversationMessages()` |
| The browser disconnects mid-stream and the turn vanishes | PHP's `ignore_user_abort` defaults to `0`, so a closed tab (or the UI's own `AbortController` on New conversation / navigation) kills the script at the next write: no assistant row, no error row, and from Epic 4 a permanently `pending` tool row. `ChatController` sets `ignore_user_abort(true)` so the run finishes and records itself. The provider call is already in flight, so completing it is strictly cheaper than discarding it |

---

## Delivered

Shipped as planned. Five things the build turned up that the plan did not:

1. **Flushing had to become conditional.** The Pest browser harness captures a streamed body with its own output buffer (`ob_start()` → `sendContent()` → `ob_get_clean()`), so `ob_flush()` — which is exactly what Laravel's own `eventStream()` does — pushed every part to stdout and handed the browser an empty response. `StreamEmitter` now flushes only when `headers_sent()`, i.e. when the response is genuinely on the wire. Nothing changes in production; browser tests can see the stream.
2. **The catch-all proved itself during development.** A mistake in a test listener (`$event->agent`, which `StreamingAgent` doesn't have — it's `$event->prompt->agent`) surfaced as a rendered error card with the exception class and message, exactly as Feature 6 promises, instead of a blank page or a 500.
3. **`Agent::fake()` needed the wrapper classes, as predicted** — `tests/Pest.php` gained a `fakeAgent()` helper registering the agent plus both decorator classes. Also: a faked response *list* never advances past an entry that throws (the fake increments its cursor with `tap()` after marshalling), so failure-then-success tests key on the prompt with a closure.
4. **`AiServiceProvider` had to join `getPackageProviders()`** — Testbench doesn't auto-discover it, and `Ai::fakeAgent()` needs the manager bound.
5. **A streamed response does nothing until its body is consumed.** Feature tests call `streamedContent()` inside the `sendMessage()` helper; without it the agent is never invoked and nothing persists. Test-only — in production the framework always sends the response — but chasing it surfaced a real one: because the callback runs with the connection open for the whole generation, a disconnect would have killed the turn silently. Hence `ignore_user_abort(true)`, above.

The `type()`/testid, output-buffer, and fake-cursor findings are recorded in [AGENTS.md → Writing browser tests](../../AGENTS.md#writing-browser-tests) so the next epic inherits them.

## Definition of done

- All 12 acceptance criteria verified
- `composer check` green (Pint · PHPStan level 5 · Pest) and `composer test:e2e` green
- Feature tests for every backend path; a browser test for the user-visible flow
- Both themes verified on empty, streaming, settled, and error states
- `dist/` rebuilt and committed
- **PRD updated:** Feature 2's `useChat()` sentence reworded (Decision 1) and the structured-output streaming limitation recorded (Decision 2)
- **GOAL updated:** conversation lifecycle (`?c={id}`, New/Clear), the stateless explanation, and the nginx buffering note
- **FRONTEND.md updated:** `Collapsible` moves from epic 4 → 3
- `plans/PLAN.md` Epic 3 row marked ✅ done
