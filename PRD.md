# Synapse — MVP PRD

*See every connection your AI agents make.*

## Overview

**Synapse** is a development dashboard for AI agents in Laravel — a hands-on workbench for building, testing, and debugging agents during development. Think of it as the missing UI for `laravel/ai`: discover your agents, chat with them, inspect tool calls, and iterate on prompts — all from the browser.

The MVP targets **Laravel AI SDK (`laravel/ai`)** as its sole data source. By design, Synapse is compatible with **any agent framework that implements the Laravel AI SDK contracts** — if a framework dispatches SDK events, Synapse records it automatically, no adapter needed.

**Package:** `redberry/synapse`

**Target SDK version:** `laravel/ai` 0.9.x or 0.10.x (PHP ^8.3, Laravel 12/13). APIs below were verified against v0.9.1; the full suite also passes against v0.10.2, which is additive for everything Synapse touches.

### Why?

- Building AI agents is a tight loop: change prompt → test → check tool calls → repeat. Doing this via `tinker` or custom routes is painful
- Laravel developers expect first-party devtools (Telescope, Horizon, Pulse) — AI agents deserve the same
- The SDK provides no UI to interact with agents — Synapse fills that gap
- No existing package fills this role in the Laravel ecosystem

### Positioning

| Tool | Purpose |
|------|---------|
| Telescope | Inspect HTTP requests, queries, jobs, mail |
| Horizon | Monitor Redis queues & workers |
| Pulse | Track application performance metrics |
| SDK's built-in `agent:chat` command | Terminal chat with a hard-coded anonymous "helpful assistant" — doesn't invoke your agent classes at all; ephemeral, no tool inspection |
| **Synapse** | **Build, test & debug AI agents — browser-based, persistent across refreshes, multi-agent discovery, with inline tool/reasoning/citation inspection** |

### Compatibility

| AI Package | Support Level | How |
|------------|--------------|-----|
| **Laravel AI SDK** (`laravel/ai`) | **First-class** | Discovers agents, invokes via SDK contracts, subscribes to SDK events |
| **Any SDK-compatible framework** | **Automatic** | Any framework implementing SDK's `Agent` contract works out of the box |

---

## Target Users

- **Laravel developers** building AI agents with `laravel/ai` SDK
- Developers iterating on prompts, tool definitions, and agent behavior
- QA engineers validating agent responses and tool call correctness

---

## MVP Scope

### Core Principle

**Discover → Chat → Inspect.** Find your agents, talk to them, see exactly what's happening under the hood.

---

### Feature 1: Agent Discovery

The landing page — auto-scans the project and lists all registered agent classes.

**Each agent card shows** (per Figma design — cards stay lean):
| Field | Source |
|-------|--------|
| Agent name | Class short name (e.g. `SupportAgent`) |
| Provider / Model | e.g. `anthropic / claude-3-5-sonnet` |
| Tools | Chips with tool names; overflow collapses to a `+N` chip with a hover popover listing all tools |

The FQCN and full configuration live in the Info panel (Feature 4), not on the card. Interface-derived capability data (`Conversational`, `HasStructuredOutput`, …) is **not rendered as card badges** — it remains in the discovery API payload for internal use (see Feature 4).

**Actions:** Click a card → opens the Chat Playground; the card's `Info` link opens the Info panel

**How discovery works:**
- Scans for classes implementing the SDK's `Agent` contract
- Reads agent metadata: provider, model, tools, instructions
- No manual registration required — just create an agent class and it appears

#### Technical Implementation

**Discovery mechanism** — `AgentDiscovery` service scans configured directories (default: `app/Ai/Agents/` — where the SDK's `make:agent` generates agents — plus `app/Agents/`) using Symfony Finder, filtering classes that implement `Laravel\Ai\Contracts\Agent`:


**Metadata extraction** — For each discovered agent class, instantiate via the Laravel container (`app($class)`) and read. Note: `make()` lives on the `Promptable` trait, not the `Agent` contract — a framework-authored agent implementing only the contract won't have it, so Synapse must not rely on it:
| Data | How |
|------|-----|
| Provider / Model | Call `provider()` / `model()` methods if they exist, **else** read `#[Provider]` / `#[Model]` attributes via `ReflectionClass::getAttributes()` (methods take precedence — same resolution order as `Promptable::getProvidersAndModels()`). When neither is set, fall back to `#[UseCheapestModel]` / `#[UseSmartestModel]` and surface the chosen tier in the UI badge (e.g. "smartest" / "cheapest" pill) |
| Tools | Check if agent `instanceof HasTools`, call `$agent->tools()` → returns `array<Tool\|ProviderTool>`, and entries may also be `Agent` instances (sub-agents as tools) or raw MCP tool references. Mirror the gateway's `resolveTool()` match logic to classify each entry (see Feature 4) — calling `description()` / `schema()` blindly fatals on `ProviderTool` |
| Capabilities | Check `instanceof Conversational`, `instanceof RemembersConversations`, `instanceof HasTools`, `instanceof HasStructuredOutput`, `instanceof HasMiddleware`, `instanceof CanActAsTool` — used internally and exposed in the API payload; not rendered as UI badges (see Feature 4) |
| Generation options | Call `TextGenerationOptions::forAgent($agent)` (public static) → resolves `maxSteps`, `maxTokens`, `temperature`, `topP`, `toolChoice` with the SDK's own attribute/method precedence — never hand-roll this reflection |
| Timeout | `timeout()` method if it exists, else `#[Timeout]` attribute, else default `60` (methods take precedence over attributes) |

**Caching** — Discovery results cached per-request (singleton). No persistent cache — in development, classes change constantly.

---

### Feature 2: Chat Playground

The core experience — select an agent and have a real conversation.

**Chat interface:**
- Clean message thread (user messages on right, agent on left)
- Text input with send button
- Attachment support — file picker + drag-and-drop for images, documents, and audio; attached files render as thumbnails/chips on the user message bubble (and in replay)
- Model selector in the composer (per Figma design) — a dropdown to override the agent's provider/model for subsequent sends, defaulting to the agent's configured model. Passed through as `stream($prompt, $attachments, provider: $override, model: $override)` (native SDK parameters — no agent modification). The actually-used provider/model is recorded per message in `meta`, so replays always show what really ran. **Dropdown contents:** the agent's own configured model (default selection) + its provider's cheapest and smartest tiers (resolved via the SDK's `cheapestTextModel()` / `smartestTextModel()`) + any additional models listed in `config('synapse.playground.models')`
- Messages stream in real-time (if agent supports streaming)
- Streams speak the Vercel AI SDK UI message protocol, but Synapse owns **both ends**: it emits the SSE itself rather than returning the SDK's built-in `usingVercelDataProtocol()` response (the built-in serializer silently drops `ProviderToolEvent`s and tool error state — see Feature 3), and the React app consumes it with its own ~120-line reader rather than `useChat()` from the `ai` package. Keeping the SDK's own part names (`toVercelProtocolArray()`) means the wire format stays the SDK's; skipping the dependency keeps it out of the bundle that is inlined into every dashboard page load
- Reasoning blocks (Anthropic extended thinking, OpenAI o-series, DeepSeek) rendered inline as a collapsible "Thinking…" pane separate from the final answer; reasoning token count shown alongside prompt/completion tokens
- Structured-output agents (`HasStructuredOutput`) return a `StructuredAgentResponse` — the playground renders `$response->structured` as a syntax-highlighted JSON card instead of assuming plain text. **These agents cannot stream:** `StreamsText::stream()` throws `InvalidArgumentException('Streaming structured output is not currently supported.')` for any agent implementing the contract, so Synapse detects it and invokes `prompt()` instead, emitting the completed response as a single part. The same limitation is why the history decorator below must **not** implement `HasStructuredOutput`
- Conversation persists across page refreshes (stored in Synapse DB)
- Conversation memory mirrors the agent: multi-turn for `Conversational` agents, independent request/response per message for stateless agents (with a "Stateless" badge) — see Technical Implementation

**Inline tool call cards** (see Feature 3 for detail):
- When the agent calls a tool, a card appears inline in the chat flow
- Collapsed state: tool name + status badge (`success` / `error`)
- No disruption to the chat reading flow

**Per-message metadata:**
- Token count (prompt + completion) shown as subtle label on each assistant response
- Duration (ms) for each response

**Error handling:**
- If the agent throws an exception, display it inline as an error card
- Show the exception class, message, and stack trace (collapsible)
- Developer can fix and try again without reloading

**Conversation controls:**
- "New conversation" button — starts fresh
- "Clear conversation" — deletes the current thread (messages + tool rows) and returns to an empty playground; equivalent to `DELETE /synapse/api/conversations/{id}` followed by a new conversation
- Ability to switch agent mid-session (starts new conversation)

#### Technical Implementation

**The conversation problem** — The SDK treats conversation history as an agent responsibility. In `GeneratesText::prompt()`:

```php
// vendor/laravel/ai/src/Providers/Concerns/GeneratesText.php
$messages = $agent instanceof Conversational ? $agent->messages() : [];
$messages[] = new UserMessage($prompt->prompt, $prompt->attachments->all());
```

An agent that doesn't implement `Conversational` gets `$messages = []` — every call is stateless. An agent that does implement it (via `RemembersConversations` trait) only loads history when a conversation participant is set via `forUser()` / `continue()`.

**Synapse's approach — mirror the agent's real behavior** — Synapse does **not** force every agent to be conversational. It reflects how the agent actually behaves so the playground is an honest test surface, and there are exactly two modes, chosen automatically per agent:

- **Conversational agents** (`instanceof Conversational` — typically via the `RemembersConversations` trait): Synapse feeds the **full thread history** back on each turn. Real multi-turn conversation. Synapse supplies the history from its own `synapse_messages` (via the decorator below) rather than relying on the agent's own `ConversationStore` / participant, so it works without the developer calling `forUser()` / `continue()` and never touches the SDK's conversation tables.
- **Stateless agents** (do **not** implement `Conversational`): Synapse sends **only the current message** — no prior turns. Each send is an independent request/response, exactly as the agent behaves in production. Synapse still stores every turn and displays them as one thread in the UI (grouped in a Synapse conversation/session), but **the agent itself receives no history**. The playground *looks* like a chat; for a stateless agent it is really a sequence of isolated prompts.

This falls out of the SDK's own injection point in `GeneratesText::prompt()`:

```php
$messages = $agent instanceof Conversational ? $agent->messages() : [];
$messages[] = new UserMessage($prompt->prompt, $prompt->attachments->all());
```

So Synapse only wraps `Conversational` agents with the history decorator; stateless agents are invoked as-is, and the SDK naturally sends just the current `UserMessage`:

```php
// SynapseConversationalAgent — applied ONLY to agents that already implement
// Conversational, to feed Synapse's own thread history instead of the agent's store
class SynapseConversationalAgent implements Agent, Conversational, HasTools /*, ... */
{
    public function __construct(
        private Agent $agent,
        private array $messages,  // loaded from synapse_messages
    ) {}

    public function instructions(): string { return $this->agent->instructions(); }
    public function messages(): iterable { return $this->messages; }
    public function tools(): iterable {
        return $this->agent instanceof HasTools ? $this->agent->tools() : [];
    }
}

// Invocation:
$target = $agent instanceof Conversational
    ? new SynapseConversationalAgent($agent, $history)  // multi-turn: inject history
    : $agent;                                           // stateless: current message only
```

**The decorator must forward more than it looks.** The sketch above shows the *intent*; a faithful implementation also has to forward everything the SDK resolves by reflecting on the agent **instance**, because each of those call sites sees the decorator's class instead of the wrapped agent's: `provider()` / `model()` (`Promptable::getProvidersAndModels()`), `timeout()`, the `#[UseSmartestModel]` / `#[UseCheapestModel]` tier (`getDefaultModelFor()`), and `maxSteps` / `maxTokens` / `temperature` / `topP` / `toolChoice` (`TextGenerationOptions::forAgent()`). All of those check a **method before** the attribute, so forwarding methods is enough. `#[Strict]` is the exception — attribute-only, with no method fallback — so it needs a `#[Strict]`-annotated subclass of the decorator, selected by `Strict::isAppliedTo($agent)`. Without this the playground silently runs a different model and different generation settings than the Info panel reports. See [plans/epic-03-chat-mvp](plans/epic-03-chat-mvp/PLAN.md#1-the-decorator-is-riskier-than-the-prd-sketch-suggests).

**Statelessness is across user turns, not within one.** Within a single send, the SDK's multi-step tool loop still maintains its internal `assistant → tool → assistant` messages — that's one message being answered, and tool inspection (Feature 3) works identically for both agent types. The "no history" rule only means prior user/assistant turns are not fed back.

**UI indicator** — the playground shows a subtle badge on stateless agents (e.g. "Stateless — each message is sent independently") so developers understand why the agent doesn't recall earlier turns. Conversational agents show no badge (memory is expected). The `conversational` flag is part of the agent-detail API payload (from the discovery `instanceof Conversational` check).

This approach:
- Mirrors each agent's real conversational capability — Synapse never adds memory the agent doesn't actually have
- For conversational agents, never interferes with the agent's own `ConversationStore` or `RememberConversation` middleware (the decorator supplies history directly)
- Uses only the `$messages` injection point the SDK already supports

**Streaming flow:**
1. User sends message → `POST /synapse/api/chat/{agent}/send` (see HTTP API surface in Tech Stack & Architecture)
2. Synapse stores user message in `synapse_messages`
3. **If the target agent is `Conversational`**, Synapse wraps it in `SynapseConversationalAgent` with the full thread history; **otherwise it uses the agent as-is** (no history injection — stateless, current message only). See "Synapse's approach" above
4. Calls `$target->stream($currentMessage, $attachments, …)` → returns `StreamableAgentResponse`
5. Synapse's controller iterates the stream and emits Vercel-protocol SSE parts itself: for each event, use `$event->toVercelProtocolArray()` when it returns non-null (text-delta / reasoning / tool-input / finish parts — `useChat()` parses these for free), and fill the SDK's two serialization gaps with additional parts: a custom `data-provider-tool` part for `ProviderToolEvent` (which has **no** Vercel serialization and is silently skipped by the SDK's serializer), and the standard `tool-output-error` part when `ToolResult->successful === false` (the SDK only ever emits `tool-output-available`, discarding `$successful` / `$error`). ~40 lines of glue; do **not** return the SDK's `Responsable` directly
**Flushing — guard on `PHP_SAPI`, never on `headers_sent()`.** Each SSE part is pushed with `ob_flush(); flush();` as it is written, which is what makes the dashboard live. The guard on that flush must be the SAPI, exactly as Symfony's `Response::send()` and Laravel's own `eventStream()` do it. `headers_sent()` looks like the right question and is not: the stock `php.ini` sets `output_buffering = 4096`, so the first `echo` lands in PHP's own buffer and the headers are therefore *never* sent — the guard answers "no" for every part, of every run, and the whole conversation is assembled and painted at once. Measured on nginx + PHP-FPM with the two guards and nothing else different: **2ms to first byte versus 4019ms of a 4020ms run.** No test tier can catch this, because the feature suite and the browser driver both run Laravel in-process on the CLI SAPI, where flushing is deliberately off — `bin/check-streaming.sh` is the gate instead. `Synapse::streams()` exposes the same rule to the UI so a runtime that cannot stream says so rather than looking hung.

6. Persistence is registered with `->then(fn (StreamedAgentResponse $r) => ...)` — the SDK invokes the callback once the stream closes, providing `$text`, `$usage`, `$toolCalls`, `$toolResults`, `$events` (including `ReasoningEnd`, `Citation`, `ProviderToolEvent`, stream `Error`) → stored in `synapse_messages`. Since Synapse iterates the stream itself (step 5), events can also be persisted inline as they pass, with `then()` as the completion hook. `->withinConversation($synapseConversationId)` tags the response so the recorder can correlate stream events to the right Synapse conversation.

**Attachments** — the SDK does nearly all the work; Synapse's job is upload plumbing:

1. The send request is `multipart/form-data`: `message` + optional `attachments[]`
2. Uploads are saved to a configurable disk (`synapse.storage.attachments_disk`, default `local`, under a `synapse/` prefix) — **never base64 into the DB** (a 5MB PDF is ~6.7MB of text; MySQL `TEXT` caps at 64KB)
3. Each upload becomes a `StoredImage` / `StoredDocument` / `StoredAudio` (disk + path — built for exactly this) passed to `$wrapper->stream($prompt, $attachments)`
4. The user-message row persists the SDK's own serialization (`{type: "stored-image", path, disk, name}`) in its `attachments` JSON column — tiny rows, and history rebuild rehydrates via `File::fromArray()`, mirroring the SDK store's `rehydrateAttachments()`
5. No MIME allowlist — pass anything the developer uploads and let the provider reject what it can't handle; the error card is the point of the tool. Unsupported-type errors surface exactly like production would
6. `synapse:clear` deletes the stored files along with the rows

**Event capture** — Synapse subscribes to SDK events for metadata:
| Event | Data Captured |
|-------|---------------|
| `PromptingAgent` / `StreamingAgent` | `$invocationId`, start timestamp |
| `AgentPrompted` / `AgentStreamed` | `$response->usage` (tokens incl. `reasoningTokens`, `cacheReadInputTokens`, `cacheWriteInputTokens`), `$response->meta` (provider, model) |
| `InvokingTool` | Tool name, arguments, start timestamp |
| `ToolInvoked` | Tool result, compute duration |
| `AgentFailedOver` / `ProviderFailedOver` | Rendered as informational notice, not error |

Events are dispatched by the SDK automatically — Synapse just listens.

---

### Feature 3: Inline Tool Call Inspector

Tool calls are shown as expandable cards within the chat flow.

**Collapsed state** (always visible in chat):
```
┌──────────────────────────────────────────┐
│ 🔧 searchProducts              ✅ 45ms  │
└──────────────────────────────────────────┘
```

**Expanded state** (click to toggle):
```
┌──────────────────────────────────────────┐
│ 🔧 searchProducts              ✅ 45ms  │
├──────────────────────────────────────────┤
│ Arguments:                               │
│ {                                        │
│   "query": "wireless headphones",        │
│   "max_results": 5                       │
│ }                                        │
├──────────────────────────────────────────┤
│ Result:                                  │
│ [                                        │
│   { "id": 42, "name": "Sony WH-1000" }, │
│   { "id": 87, "name": "AirPods Max" }   │
│ ]                                        │
└──────────────────────────────────────────┘
```

**Details:**
- Arguments and results rendered with syntax-highlighted JSON
- Error state shows error message + exception details instead of result
- Multiple tool calls in a single step shown as stacked cards
- Duration shown per tool call

#### Technical Implementation

**Data source** — Tool call data comes from two SDK events:

```php
// SDK dispatches these automatically during prompt() / stream()
InvokingTool($invocationId, $toolInvocationId, $agent, $tool, $arguments)
ToolInvoked($invocationId, $toolInvocationId, $agent, $tool, $arguments, $result)
```

**`SynapseRecorder`** listens to both events:
1. On `InvokingTool` — inserts a `synapse_tool_invocations` row (`status = pending`) with `name`, `arguments`, `tool_invocation_id`, `invocation_id`, `started_at` — the UI gets in-flight tool cards for free
2. On `ToolInvoked` — updates that row by `tool_invocation_id`: `result`, `status = success`, `duration_ms`, `finished_at`. Note `ToolInvoked` fires **only on success** — the SDK's `executeTool()` runs the "invoked" callback after `handle()` returns, so a throwing tool never reaches this event. Its `pending` row is instead flipped to `error` by the invocation-level catch-all (Feature 6, step 2)

**For streaming** — `StreamableAgentResponse` yields `ToolCall` and `ToolResult` stream events. Synapse pushes these to the browser as they happen (before the final text response). The payload is wrapped in nested data objects rather than flat fields:

| Stream Event | Payload |
|---|---|
| `Streaming\Events\ToolCall` | `$event->toolCall->id`, `$event->toolCall->name`, `$event->toolCall->arguments`, `$event->toolCall->reasoningId` |
| `Streaming\Events\ToolResult` | `$event->toolResult->id`, `$event->toolResult->name`, `$event->toolResult->result`, `$event->successful`, `$event->error` |

**Vercel-protocol gap** — the SDK's own `ToolResult::toVercelProtocolArray()` emits only `tool-output-available` with the output, discarding `$successful` and `$error`. In 0.9.1 the stream always sets `successful: true`, so today the live tool-failure path is the invocation-level catch-all (Feature 6), not this event. Synapse's SSE emitter (Feature 2, step 5) still emits the standard `tool-output-error` Vercel part whenever `$successful === false` — a defensive, forward-compatible hook for when/if the SDK adds a failed-tool-result path.

**Provider-native tool events** — the Anthropic, OpenAI and xAI gateways yield `Streaming\Events\ProviderToolEvent` for built-in tools like web search, web fetch, file search, and code interpreter (no `InvokingTool` / `ToolInvoked` Laravel events fire for these — they only appear in the stream). Synapse renders them as tool cards visually distinguished from user-defined tools (⚡ `provider / tool_name`) with `$event->type`, `$event->data`, and `$event->status`.

**Neither `type` nor `status` is normalized by the SDK**, so Synapse normalizes both:

| Gateway | `$event->type` | `$event->status` |
|---------|----------------|------------------|
| Anthropic | the raw content-block type: `server_tool_use`, or `*_tool_result` (e.g. `web_search_tool_result`) | `started` · `result_received` · `completed` |
| OpenAI / xAI | the item type ending in `_call`: `web_search_call`, `file_search_call`, `code_interpreter_call` | `completed`, plus the third segment of `response.<x>_call.<status>` — an **open** set (`in_progress`, `searching`, …) |

Two consequences: the tool's real name is **not** in `type` for Anthropic (`server_tool_use` is generic; the name is at `$data['name']`), so the card resolves `$data['name'] ?? $type` and takes the provider prefix from the turn's `meta.provider`. And the status is mapped into Synapse's own `pending` / `success` / `error`, defaulting **unknown values to `pending`** so a new provider status shows an in-flight card rather than a wrong terminal one — with the raw string retained alongside. See [plans/epic-04-tool-inspection](plans/epic-04-tool-inspection/PLAN.md#3-provider-tool-events-are-messier-than-the-prd-says). The recorder persists them as `synapse_tool_invocations` rows with `type = provider_tool`, keyed by `$event->itemId` (upserted as status transitions arrive in the stream). Correlation is best-effort: Anthropic keys the start block on `content_block.id` and the result block on `tool_use_id ?? id`, which match only when the provider sends `tool_use_id` — a miss produces a second card rather than welding the wrong result onto the first. **Important:** `ProviderToolEvent` has no `toVercelProtocolArray()` override — the SDK's built-in Vercel serializer silently drops it. Synapse's own SSE emitter forwards it as a custom `data-provider-tool` part (the Vercel UI message protocol supports arbitrary `data-*` parts). These tools are also *declarable* — `tools()` can return `ProviderTool` instances (`WebSearch`, `WebFetch`, `FileSearch`), so they appear in the Agent Info Panel too, not just as stream artifacts.

**Mid-stream errors** — `Streaming\Events\Error` events arrive inside the stream when a provider reports an error mid-generation (rate limits hit during streaming, content-filter blocks, etc.). The recorder captures these the same way as thrown exceptions, storing them as a `synapse_messages` row with `role = error`. The event carries a `recoverable` bool — render recoverable errors (e.g. a rate-limit the provider retries through) as a softer informational card, and fatal ones as full error cards.

**Error capture** — A tool that throws does so *out of* the SDK (no `catch` in `executeTool()`), so its failure is recorded by the invocation-level catch-all (Feature 6): the dangling `pending` row is flipped to `status = error` with the exception message in the `error` column, and the card renders that error in place of the result. If a future SDK instead reports `ToolResult->successful === false`, the recorder stores the `$error` the same way — either path lands on the same `synapse_tool_invocations` row.

---

### Feature 4: Agent Info Panel

A side panel (or dedicated tab) showing the selected agent's full configuration.

**Sections:**

#### 4a. Configuration
- Provider + model
- Temperature, max tokens, max steps, timeout
- Any custom provider options

#### 4b. System Prompt
- Full `instructions` text, rendered as markdown
- Easy to read and verify during development

#### 4c. Registered Tools
`tools()` entries come in four kinds, each rendered distinctly:
- **User tools** (`Tool`) — name, description, parameter schema (rendered as a readable table or JSON)
- **Provider tools** (`ProviderTool`: `WebSearch`, `WebFetch`, `FileSearch`) — "⚡ Provider tool" badge + class name + provider options (they have **no** `description()` / `schema()` — calling those fatals)
- **Sub-agents** (`Agent` instances, auto-wrapped in `AgentTool` by the SDK) — "Agent tool" badge, linking to that agent's own Synapse page
- **MCP tools** — wrap in `McpTool` / `McpServerTool` (both implement `Tool`, so name/description/schema come for free), badged "MCP"

#### 4d. Middleware
- List of middleware classes applied to this agent

**Where the panel lives** — a right-hand panel inside the Chat Playground (`/playground/{agent}`), opened from an agent card's `Info` link or the playground header. Its state is a query parameter (`?info=config|prompt|tools`) so it survives a reload and can be shared; `?info=1` opens the default tab. Sub-agent tools link to that sub-agent's own Info panel.

#### Technical Implementation

**All data comes from the agent instance** — resolved via the Laravel container (`app($class)`; `make()` is on the `Promptable` trait, not the `Agent` contract, so it can't be assumed):

| Section | SDK Source |
|---------|-----------|
| Provider / Model | `provider()` / `model()` methods if they exist, **else** `#[Provider]` / `#[Model]` attributes via `ReflectionClass` (methods take precedence). If model is not explicitly provided, also read `#[UseCheapestModel]` / `#[UseSmartestModel]` and display the tier (resolved model is determined at invocation time by the SDK) |
| Temperature / Max Tokens / Max Steps / Top P / Tool Choice | `TextGenerationOptions::forAgent($agent)` — public static, resolves all five with the SDK's own attribute/method precedence. Tool choice shows mode (`auto` / `none` / `required` / forced tool name) — invaluable when debugging "why won't my agent call the tool" |
| Strict mode | `#[Strict]` attribute (OpenAI strict structured output) — shown as a small badge |
| Timeout | `timeout()` method, or `#[Timeout]` attribute, or default `60` (method takes precedence) |
| System Prompt | `$agent->instructions()` → `string` |
| Tools | `$agent instanceof HasTools ? $agent->tools() : []` → classify each entry per the gateway's `resolveTool()` logic: `Tool` as-is, `Agent` → `AgentTool`, MCP references → `McpTool` / `McpServerTool`, `ProviderTool` as-is (see 4c) |
| Middleware | `$agent instanceof HasMiddleware ? $agent->middleware() : []` |
| Provider Options | `$agent instanceof HasProviderOptions ? $agent->providerOptions($provider) : []` |
| Structured Output | `$agent instanceof HasStructuredOutput ? $agent->schema(new JsonSchemaTypeFactory) : null` — rendered as an **Output schema** section inside the Tools tab (no fourth tab), reusing the parameter rows |

**Tool schema rendering** — Each tool's `schema(JsonSchema $schema)` returns `array<string, Type>` (using `illuminate/json-schema`). **Serialize it through the SDK's public `ObjectSchema`**, not per-`Type`:

```php
$json = (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toSchema();
// ['type' => 'object', 'properties' => [...], 'required' => ['query'], ...]
```

`Type::toArray()` deliberately **drops `required`** (`Serializer::$ignore` contains it, because JSON Schema records required-ness on the parent object, and `Serializer::isRequired()` is `protected`). `ObjectSchema` is the same path every provider gateway uses, so the panel shows exactly what the provider receives.

Rendered per the Figma panel as one row per parameter — `name` + a type badge, required marked, with the description on a second line when the tool defines one:

```
query *                    [ String  ]
Search query text
max_results                [ Integer ]
Maximum results to return
```

A tool whose `schema()` throws degrades to a "schema unavailable" note on that tool; the rest of the panel is unaffected.

**"Capabilities" section (per Figma design)** — the Info panel's Config tab section labeled *Capabilities* renders the agent's **tool chips** (matching the design), not interface badges. Interface-derived capability checks (`instanceof Conversational`, `RemembersConversations`, `HasTools`, `HasStructuredOutput`, `HasMiddleware`, `CanActAsTool`) are still performed by discovery — Synapse needs them internally (decorator behavior, structured-output rendering, tools resolution) and they're included in the agent-detail API payload — but they are **not rendered as UI badges in the MVP**.

---

### Feature 5: Invocation History

Simple list of past conversations with this agent (or across all agents).

**Each row shows** (columns per Figma design — message/tool-call counts are visible inside the conversation, not as table columns):
| Field | Source |
|-------|--------|
| Agent | Class short name |
| Message | Conversation title — first user message, truncated to 100 characters (`Str::limit`); set at conversation creation, manually renamable (see Actions), never LLM-generated (unlike the SDK's `generate_title` behavior) |
| Status | `success` · `error` icon |
| Tokens | Total (prompt + completion), abbreviated (e.g. `3.5k`) |
| Date & Time | When conversation started |

**Search, filters & sort** (per Figma design):
- **Search** — matches against conversation titles and message content
- **Filters** — Agent (multi-select), Status (`success` / `error`), Tools used (multi-select, matched via `synapse_tool_invocations.name`), and date range picker; active filters show a count badge
- **Sort** — Newest First / Oldest First (by `updated_at`)
- **Pagination** — numbered pages with prev/next (25 per page)
- All of these are query parameters on `GET /synapse/api/conversations`: `search`, `agents[]`, `status`, `tools[]`, `from`, `to`, `sort`, `page`

**Actions** (row menu, per Figma design):
- Click / Open → reopens the conversation with all messages and tool call cards intact
- Rename — modal to set a custom conversation title (`PATCH /synapse/api/conversations/{id}`); still never LLM-generated
- Delete — with confirmation modal ("This action cannot be undone")

#### Technical Implementation

**Data source** — Synapse's own `synapse_conversations`, `synapse_messages`, and `synapse_tool_invocations` tables (not the SDK's `agent_conversations`). See Database Schema.

**Queries:**
```php
// List all conversations with aggregates
SynapseConversation::query()
    ->withCount(['messages', 'toolInvocations'])
    ->withSum('messages as total_prompt_tokens', 'prompt_tokens')
    ->withSum('messages as total_completion_tokens', 'completion_tokens')
    ->latest('updated_at')
    ->paginate(25);
```

**Conversation replay** — Loading a past conversation renders the full message thread including inline tool call cards, merged from both tables (see Database Schema):
- `synapse_messages` with `role = user` → user message bubble, with attachment thumbnails/chips rendered from the `attachments` JSON
- `synapse_messages` with `role = assistant` → assistant message bubble with token metadata
- `synapse_messages` with `role = error` → inline error card
- `synapse_tool_invocations` rows → inline tool call cards (collapsed), interleaved chronologically by `started_at` against message timestamps

**Status detection** — A conversation's status is `error` if any `synapse_messages` row has `role = error`, otherwise `success`. Tool-level errors (`synapse_tool_invocations.status = error`) don't fail a conversation — agents often recover from a failed tool call and answer anyway.

**Relationship to SDK conversations** — Synapse conversations are fully independent from the SDK's `agent_conversations` + `agent_conversation_messages` tables (the latter holds columns `attachments`, `tool_calls`, `tool_results`, `usage`, `meta` per assistant turn). Synapse never reads from or writes to those tables. For conversational agents, the `SynapseConversationalAgent` decorator supplies history directly and Synapse never sets a conversation participant, so the agent's own `RememberConversation` middleware / `DatabaseConversationStore` is never engaged during a Synapse-initiated invocation; for stateless agents there is no wrapping at all. Either way, Synapse records are created only by the Chat Playground — they are not a mirror of production data. This keeps MVP scope tight — Synapse is a dev tool, not a production logger.

---

### Feature 6: Error Display

**Every** error that occurs while running an agent — from any source — is caught and rendered as a readable inline card. Synapse never surfaces a blank screen, a broken stream, or a raw HTTP 500. This includes provider/LLM errors, timeouts, exceptions thrown inside the developer's own tool `handle()` code, agent resolution/instantiation failures, middleware exceptions, and mid-stream provider errors.

**Error card:**
```
┌──────────────────────────────────────────┐
│ ❌ Error: RateLimitException             │
├──────────────────────────────────────────┤
│ Rate limit exceeded for anthropic.       │
│ Retry after: 30s                         │
│                                          │
│ ▸ Stack trace                            │
└──────────────────────────────────────────┘
```

- Exception class + message always visible
- Stack trace collapsible
- Works for both LLM errors and tool execution errors

#### Technical Implementation

**One catch-all is the backbone.** The SDK lets exceptions propagate — it does **not** swallow errors thrown inside a tool. Verified in `Gateway\Concerns\InvokesTools::executeTool()`: it runs `$tool->handle()` inside a `try/finally` with **no `catch`**, so a throwing tool handler bubbles straight out of `stream()` / `prompt()`. Likewise the streaming `ToolResult` event is currently always constructed `successful: true, error: null` in `TextGenerationLoop` — the SDK has no "failed tool result" path in 0.9.1. Therefore a single `try/catch (\Throwable)` around the **entire invocation pipeline** (agent resolution → decorator build → `stream()` → stream iteration → persistence) is what makes error handling comprehensive:

```php
try {
    $target = $agent instanceof Conversational
        ? new SynapseConversationalAgent($agent, $history)
        : $agent;

    $response = $target->stream($currentMessage, $attachments, $provider, $model);
    // ... iterate + emit SSE + persist
} catch (\Throwable $e) {
    // 1. Store an agent-level error row
    SynapseMessage::create([
        'conversation_id' => $conversationId,
        'role' => 'error',
        'content' => $e->getMessage(),
        'metadata' => [
            'exception_class' => get_class($e),
            'stack_trace' => $e->getTraceAsString(),
        ],
    ]);

    // 2. Resolve any tool cards left hanging: a tool that threw fired
    //    InvokingTool (pending row) but never ToolInvoked. Mark them failed.
    SynapseToolInvocation::where('invocation_id', $invocationId)
        ->where('status', 'pending')
        ->update(['status' => 'error', 'error' => $e->getMessage(), 'finished_at' => now()]);

    // 3. Emit an error part on the SSE stream so the UI renders it inline
}
```

**Error sources, and how each lands:**

1. **Provider / LLM errors** — rate limits, auth failures, timeouts, invalid requests thrown during `stream()`. `Promptable::withModelFailover()` catches only `FailoverableException` (for failover); everything else propagates to the catch-all → agent-level error card.
2. **Tool errors (developer code)** — an exception in a tool's `handle()` propagates out of the SDK (it is *not* turned into a tool result). The catch-all stores the error row **and** flips that tool's dangling `pending` invocation row to `error` (step 2 above), so the tool card shows failed *and* an error card explains why. Synapse still handles a `ToolResult` with `successful === false` defensively (emitting `tool-output-error`, Feature 3) for forward-compatibility if a future SDK adds that path.
3. **Failover events** — `AgentFailedOver` / `ProviderFailedOver` fire when the SDK falls back to the next provider. Rendered as an informational notice (not an error), so the developer sees a fallback occurred.
4. **Mid-stream errors** — `Streaming\Events\Error` inside the stream (Feature 3); stored as a `role = error` row, styled by its `recoverable` flag.

**Error display** — every caught error becomes a `synapse_messages` row with `role = error` (exception class + message + collapsible stack trace in `metadata`), rendered as an error card at the position in the thread where it occurred. Because the same catch-all runs for every invocation, there is no code path where an agent failure escapes to a generic Laravel error page.

---

### Feature 7: Token Counter

Subtle but essential for prompt engineering.

- Each assistant response shows: `↑ 340 tokens · ↓ 128 tokens` (prompt in, completion out)
- Running total at top of conversation: `Total: ↑ 1,240 · ↓ 512 `
- Helps developers spot bloated prompts and optimize token usage

#### Technical Implementation

**Data source** — The SDK's `Usage` class (available on every response):

```php
// From AgentResponse / StreamedAgentResponse
$response->usage->promptTokens
$response->usage->completionTokens
$response->usage->cacheWriteInputTokens
$response->usage->cacheReadInputTokens
$response->usage->reasoningTokens
```

For streaming, `StreamEnd` events carry per-step `Usage` objects. `StreamEnd::combineUsage()` aggregates them.

**Storage** — Each `synapse_messages` row with `role = assistant` stores `prompt_tokens` and `completion_tokens` as promoted integer columns (for cheap SQL aggregates), with the full `Usage::toArray()` breakdown — including cache and reasoning tokens — in the `usage` JSON column (see Database Schema).

**Conversation totals** — Summed on the fly:
```php
$conversation->messages()
    ->whereNotNull('prompt_tokens')
    ->selectRaw('SUM(prompt_tokens) as total_in, SUM(completion_tokens) as total_out')
    ->first();
```

**Extended token breakdown** (optional detail on hover/expand):
- Cache write tokens, cache read tokens, reasoning tokens — all available from `Usage`
- Useful for developers optimizing prompt caching on Anthropic or reasoning usage on OpenAI o-series

---

## Configuration

```php
// config/synapse.php
return [
    // Default applies to non-production only; in production, routes register
    // solely when SYNAPSE_ENABLED is explicitly true (see Authorization & Safety)
    'enabled' => env('SYNAPSE_ENABLED', true),

    'storage' => [
        // Any connection from config/database.php; null = app default.
        // Point at a dedicated sqlite connection to keep Synapse data fully
        // isolated from the app DB (see Database Schema → Storage strategy)
        'connection' => env('SYNAPSE_DB_CONNECTION', null),

        // Filesystem disk for chat attachment uploads (stored under a synapse/ prefix)
        'attachments_disk' => env('SYNAPSE_ATTACHMENTS_DISK', 'local'),
    ],

    'discovery' => [
        // Directories to scan for agent classes. The first is where the SDK's
        // `make:agent` generates them; the second is a common alternative.
        'paths' => [app_path('Ai/Agents'), app_path('Agents')],

        // Ignore these agent classes
        'ignore' => [],
    ],

    'playground' => [
        // Extra models offered in the composer's model selector, on top of each
        // agent's own model and its provider's cheapest/smartest tiers (Feature 2)
        'models' => [
            // 'anthropic/claude-sonnet-5',
            // 'openai/gpt-5',
        ],
    ],

    'retention' => [
        // When true, Synapse registers a daily scheduled `synapse:prune` (see Artisan Commands)
        'auto_prune' => env('SYNAPSE_AUTO_PRUNE', false),

        // Conversations older than this many days are pruned
        'days' => env('SYNAPSE_PRUNE_DAYS', 7),
    ],

    'ui' => [
        'path' => 'synapse',
        'middleware' => ['web'],
    ],
];
```

---

## Tech Stack & Architecture

### Stack

| Layer | Choice | Rationale |
|-------|--------|-----------|
| Backend | PHP ^8.3, Laravel 12/13 | Must match `laravel/ai`'s own constraints — Synapse can never be installable where the SDK isn't |
| Frontend | React + TypeScript | Richer chat interactivity; first-class `useChat()` support |
| Build | Vite | Horizon, Telescope, and `laravel/ai` all build with Vite — proven package setup |
| Styling | Tailwind CSS | Utility-first, no runtime dependency, easy dark mode |
| UI components | shadcn/ui (vendored into `resources/js`, built on Radix primitives) | The [Figma components sheet](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=187-2364&m=dev) maps ~1:1 to shadcn components (Tabs, Dialog, DropdownMenu, Select, Command, Calendar range picker, Table, Pagination, Badge, Collapsible, Sidebar). Vendored code = no version drift in the shipped `dist/`, full restyling control to match Figma tokens; CSS-variable theming makes the pending light theme a token swap; Radix gives a11y for free. Custom-built on top: message bubbles, tool-card composition, JSON viewer |
| Streaming client | Vercel `ai` package (`useChat()`) | Parses the Vercel UI message protocol emitted by Synapse's SSE controller (Feature 2, step 5) |
| SPA routing | React Router | Client-side pages: Agents, Chat, History |
| JSON rendering | One collapsible JSON tree component (`@uiw/react-json-view` or equivalent), used everywhere JSON appears — tool arguments/results, schemas, usage breakdowns | Horizon/Telescope standardize on `vue-json-pretty`; one component, not per-feature choices. No heavyweight highlighter (shiki) — keep the bundle small |
| Markdown rendering | `react-markdown` | System prompts (Feature 4b) and assistant responses render as markdown |
| Theming | Light/dark via Tailwind's `dark:` variant + a **theme switcher in the sidebar Workspace menu** (light / dark / system, persisted in `localStorage`; the stored-or-OS choice is applied inline before first paint to avoid a flash) | Both theme token sets exist in Figma, so components are theme-aware from the start. The switcher has no dedicated Figma component yet — it reuses the `Navigation` row and `Dropdown item` styles, and is swapped when the designer publishes one |
| Testing | Orchestra Testbench + Pest | The standard for Laravel packages — used by all three reference packages |
| Dev environment | `workbench/` app | Synapse development requires a host Laravel app with real agent classes to discover — Horizon, Telescope, and `laravel/ai` all use the Testbench workbench pattern for exactly this |

### Package Layout

All three reference packages (Horizon, Telescope, `laravel/ai`) converge on the same shape — Synapse copies it:

```
synapse/
├── config/synapse.php          # publishable config
├── database/migrations/        # the three synapse_* tables (see Database Schema)
├── dist/                       # compiled JS/CSS, committed, published on install
├── resources/
│   ├── js/                     # React + TypeScript source (built by Vite → dist/)
│   └── views/layout.blade.php  # the single SPA shell
├── routes/web.php              # catch-all + api group
├── src/                        # PHP: ServiceProvider, discovery, recorder, controllers, models
├── stubs/                      # SynapseServiceProvider stub (viewSynapse gate)
├── tests/                      # Pest + Testbench
├── workbench/                  # host Laravel app with sample agents for development
├── package.json                # frontend deps (dev only — users never run npm)
└── vite.config.js
```

### SPA Architecture (Horizon model)

Synapse mirrors Horizon's proven single-page-app structure:

- **One blade layout + catch-all route** — `GET /synapse/{view?}` (where `{view}` matches `.*`) returns `synapse::layout`, a single blade view that boots the React app. React Router owns everything client-side; refreshing any page works because the catch-all always serves the layout.
- **JSON API under a prefix** — all data endpoints live under `Route::prefix('api')` inside the `/synapse` group, exactly like Horizon's `routes/web.php`. The React app talks only to these endpoints.

**App shell (per Figma design)** — a persistent, collapsible left sidebar frames every page:

- **Recent Conversations** — latest conversations across agents, each showing agent name, truncated title, call count, and an error indicator when the conversation contains an error; per-item context menu (Open Playground / Rename / Delete)
- **Agents** — quick list of discovered agents for fast switching into a playground
- **Workspace nav** — `Discovery` (the agents dashboard, Feature 1) and `History` (Feature 5). *No Settings entry* — Synapse has no runtime settings UI; configuration is file-based (see Design Sync)
- **Footer** — package version + discovered agent count (e.g. `v1.0.0 · 8 agents`)
- Collapsed state shrinks the sidebar to the logo; the chat playground remains fully usable

**HTTP API surface:**

| Method | Route | Purpose |
|--------|-------|---------|
| `GET` | `/synapse/api/agents` | List discovered agents with card metadata (Feature 1) |
| `GET` | `/synapse/api/agents/{agent}` | Full agent detail: config, system prompt, tools, middleware (Feature 4) |
| `POST` | `/synapse/api/chat/{agent}/send` | Send a message (`multipart/form-data`: `message` + optional `attachments[]`, `provider`, `model` overrides); responds with the Vercel-protocol SSE stream (Feature 2) |
| `GET` | `/synapse/api/attachments/{message}/{index}` | Stream a stored attachment (thumbnails in chat/replay); gated like every other route |
| `GET` | `/synapse/api/conversations` | Paginated conversation history with aggregates; supports `search`, `agents[]`, `status`, `tools[]`, `from`, `to`, `sort`, `page` query params (Feature 5) |
| `GET` | `/synapse/api/conversations/{id}` | Full message thread for conversation replay (Feature 5) |
| `PATCH` | `/synapse/api/conversations/{id}` | Rename a conversation (Feature 5) |
| `DELETE` | `/synapse/api/conversations/{id}` | Delete a single conversation (Feature 5) |
| `POST` | `/synapse/api/conversations/clear` | Wipe all conversation history (`synapse:clear` equivalent) |

The `{agent}` route parameter is a URL-safe slug derived from the FQCN (e.g. `app.agents.support-agent`), resolved back to the class through the discovery service — never a raw class name in the URL.

### Asset Delivery

Users never run npm, **and never publish assets**. Following current Horizon/Telescope (both stopped publishing assets — `horizon:publish` now only warns, `telescope:publish` publishes config only):

- Compiled JS/CSS is **committed to `dist/`** in the package repo (built via `vite build` before each release) with stable filenames (`app.js`, `app.css`)
- `Synapse::css()` / `Synapse::js()` read those files with `file_get_contents` and **inline them** into the dashboard layout as `<style>` / `<script type="module">`
- Nothing is copied into the host application's `public/` directory, so a `composer update` can never leave stale assets behind and there is no re-publish step, no cache-busting query string, and no manifest to keep in sync
- If `dist/` is missing (a source checkout without a build), `js()` emits an HTML comment telling the developer to run `npm run build` rather than failing

---

## Authorization & Safety

Synapse's exposure is **worse than Telescope's**, not equivalent: Telescope leaking read-only debug data is bad, but Synapse exposes an endpoint that **invokes real agents** — spending API credits and executing tools, which may write to the database, call webhooks, or trigger any other side effect the developer's tools implement. The auth model treats the chat endpoint like a loaded weapon, not a dashboard.

Synapse adopts the proven Telescope/Horizon pattern:

- **Open in `local`, gated everywhere else.** In the `local` environment, the dashboard requires no authentication — zero-config dev experience, as intended.
- **`viewSynapse` gate** — in every other environment, all Synapse routes (dashboard *and* API, including chat) pass through an authorization gate:

```php
// Published into the app by synapse:install (SynapseServiceProvider stub,
// same pattern as Telescope's TelescopeApplicationServiceProvider)
Gate::define('viewSynapse', function ($user) {
    return in_array($user->email, [
        //
    ]);
});
```

- **Production requires explicit opt-in.** In `production`, Synapse's routes do not register at all unless `SYNAPSE_ENABLED=true` is explicitly set — the existing `'enabled' => env('SYNAPSE_ENABLED', true)` config default applies to non-production environments only. Enabling it in production still requires passing the `viewSynapse` gate. Defense in depth: a forgotten `composer require` on a production box must not become an unauthenticated agent-invocation endpoint.
- **`synapse:install` publishes the gate stub** so the definition lives in the app where developers can customize it. It registers that provider from `AppServiceProvider` only when the application is `local` and `SynapseApplicationServiceProvider` exists. This keeps the `--dev` installation local while allowing production to boot after `composer install --no-dev` removes the package.
- **Installer registration is idempotent and migrates old installs.** Re-running the command preserves host application edits, never duplicates the guarded block, and removes the old unconditional `bootstrap/providers.php` entry.

---

## Database Schema

### Storage strategy (Telescope model)

Synapse follows the exact pattern proven by Telescope and used by `laravel/ai` itself: **migrations run against the user's database by default, with a configurable connection override.**

- **Default:** tables are created on the app's default connection — the same approach as the SDK's own `AiMigration` (`config('ai.conversations.connection', config('database.default'))`).
- **Override:** `SYNAPSE_DB_CONNECTION` points Synapse at any connection defined in `config/database.php`. Synapse's migration base class and all models resolve their connection from this config, mirroring `AiMigration::getConnection()`.
- **Isolation recipe (documented in README):** users who want Synapse data fully out of their app database define a dedicated connection (e.g. a sqlite file) and set one env var:

```php
// config/database.php
'synapse' => [
    'driver' => 'sqlite',
    'database' => storage_path('synapse.sqlite'),
    'foreign_key_constraints' => false,
],
```
```env
SYNAPSE_DB_CONNECTION=synapse
```

No bundled/hidden database: a package-registered secret connection would be invisible to `php artisan db` and every DB tool developers use — the opposite of what a debugging tool wants. The configurable connection provides the isolation option without owning its complexity.

**Supported databases:** anything Laravel's schema builder supports — sqlite, MySQL, MariaDB, PostgreSQL. Two portability rules copied from the SDK's own migration: JSON payloads use **`text` columns** (not `->json()` — sqlite has no native JSON type) with Eloquent `array` casts, and **deletes cascade in the repository layer, not via DB foreign keys** (sqlite's FK pragma is off by default; Telescope also prunes manually).

### Design principle: store replay data in the SDK's own serialization shapes

Before every prompt, the `SynapseConversationalAgent` decorator must rebuild `Message[]` history. The SDK's `DatabaseConversationStore` defines the canonical round-trip: assistant rows carry `tool_calls` / `tool_results` JSON, rehydrated via `ToolCall::fromArray()` / `ToolResult::fromArray()` into the `AssistantMessage(toolCalls)` → `ToolResultMessage` → `AssistantMessage(text)` sequence.

Synapse stores replay data in **exactly those shapes** (`ToolCall::toArray()`, `ToolResult::toArray()`, `Usage::toArray()`). When the SDK evolves its payloads, Synapse's stored JSON evolves with it — rehydration mirrors the SDK's own store code instead of maintaining a parallel bespoke format. This splits the schema into two concerns:

- **`synapse_messages`** — SDK-shaped replay data (what the decorator feeds back to the agent)
- **`synapse_tool_invocations`** — Synapse-shaped observation data (timing, status — things the SDK doesn't store and no SDK change can break)

### Tables

```php
Schema::create('synapse_conversations', function (Blueprint $table) {
    $table->string('id', 36)->primary();          // uuid7 — k-sortable, ORDER BY id = chronological
    $table->string('agent_class')->index();       // FQCN
    $table->string('title');                      // derived: truncated first user message
    $table->timestamps();

    $table->index('updated_at');                  // history list ordering
});

Schema::create('synapse_messages', function (Blueprint $table) {
    $table->string('id', 36)->primary();          // uuid7
    $table->string('conversation_id', 36)->index();
    $table->string('role', 25);                   // user | assistant | error
    $table->text('content')->nullable();          // message text, or error message
    $table->text('attachments');                  // JSON — SDK File serialization ({type, path, disk, name}); rehydrated via File::fromArray()
    $table->text('tool_calls');                   // JSON — ToolCall::toArray() shapes (SDK-compatible)
    $table->text('tool_results');                 // JSON — ToolResult::toArray() shapes (SDK-compatible)
    $table->text('usage');                        // JSON — full Usage::toArray(), all 5 token fields
    $table->unsignedInteger('prompt_tokens')->nullable();     // promoted for SQL aggregates
    $table->unsignedInteger('completion_tokens')->nullable(); // promoted for SQL aggregates
    $table->unsignedInteger('duration_ms')->nullable();       // response wall time
    $table->text('meta');                         // JSON — provider, model, citations
    $table->text('metadata');                     // JSON — Synapse-specific (exception_class, stack_trace)
    $table->timestamp('created_at');

    $table->index(['conversation_id', 'id']);     // uuid7 ⇒ id-sorted = chronological
});

Schema::create('synapse_tool_invocations', function (Blueprint $table) {
    $table->string('id', 36)->primary();
    $table->string('conversation_id', 36)->index();
    $table->string('message_id', 36)->nullable(); // linked to assistant row once the turn completes
    $table->string('invocation_id');              // agent invocation uuid from SDK events
    $table->string('tool_invocation_id')->index(); // from InvokingTool/ToolInvoked; matches stream event ids
    $table->string('type', 25);                   // tool | provider_tool
    $table->string('name');                       // tool name, or provider tool type (anthropic.web_search)
    $table->text('arguments');                    // JSON
    $table->text('result')->nullable();           // JSON
    $table->string('status', 25);                 // pending | success | error (normalized)
    $table->string('provider_status')->nullable(); // the provider's own word for it, unnormalized
    $table->text('error')->nullable();
    $table->unsignedInteger('duration_ms')->nullable();
    $table->timestamp('started_at')->nullable();  // chronological card placement in the thread
    $table->timestamp('finished_at')->nullable();
    $table->timestamp('created_at');
});
```

**Why `synapse_tool_invocations` is its own table:**

1. **The decorator reads only `synapse_messages`** and mirrors SDK rehydration verbatim — no bespoke re-aggregation of tool-call rows back into the SDK's message sequence (fiddly with multi-step tool loops).
2. **Provider tool events fit naturally** — they never fire Laravel events and never become messages, but they're first-class rows here via the `type` discriminator.
3. **SDK churn is absorbed where it belongs** — messages JSON follows SDK shapes automatically; invocation columns are Synapse's own observations.
4. Feature 5's tool-call count becomes a trivial `withCount('toolInvocations')`.

**Row lifecycle & management** — the recorder (`SynapseRecorder`) owns these rows end to end:

1. **Insert** — `InvokingTool` creates a `status = pending` row (`type = tool`) tagged with both `invocation_id` (the agent run) and `tool_invocation_id` (the specific call). Provider-native tools have no Laravel events, so they're upserted from the stream keyed by `ProviderToolEvent->itemId` (`type = provider_tool`) as their `in_progress → completed / failed` transitions arrive.
2. **Complete** — `ToolInvoked` updates the row to `success` with `result`, `duration_ms`, `finished_at` (fires on success only — see Feature 3).
3. **Fail** — a thrown tool leaves its row `pending`; the invocation-level catch-all flips every still-`pending` row for that `invocation_id` to `error` (Feature 6).
4. **Link** — when the assistant turn persists at stream close, the recorder stamps `message_id` onto that invocation's rows, associating each tool card with its assistant message; unlinked rows (e.g. a run that errored before any assistant message) still render, ordered by `started_at`.
5. **Delete** — rows are removed with their conversation. Cascade is handled in the repository layer (a `SynapseConversation` deletes its `messages` and `toolInvocations`), **not** via DB foreign keys — sqlite's FK pragma is off by default, so Synapse never relies on it (Telescope prunes the same way). `synapse:clear` / `synapse:prune` go through the same repository path.

Inline tool cards are interleaved into the chat thread by `started_at` against message timestamps, giving the UI in-flight (`pending`) cards for free.

---

## Artisan Commands

| Command | Purpose |
|---------|---------|
| `synapse:install` | Publish config, run migrations, publish the `SynapseServiceProvider` stub with the `viewSynapse` gate (assets need no publishing — see Asset Delivery) |
| `synapse:prune` | Delete conversations older than `--days` (defaults to `synapse.retention.days`), including their messages, tool rows, and stored attachment files |
| `synapse:clear` | Clear **all** conversation history, including stored attachment files |

**Automatic pruning** — when `synapse.retention.auto_prune` is `true`, the `SynapseServiceProvider` registers a daily scheduled `synapse:prune --days={retention.days}` (via `Schedule::command()` in `booted()`) — no user scheduler wiring required. When `false` (default), nothing is pruned automatically; developers can still schedule `synapse:prune` themselves for custom cadence. Pruning is age-based on `synapse_conversations.updated_at`.

---

## Integration Points

### Zero-Config Setup (via ServiceProvider)

```php
// SynapseServiceProvider auto-discovered via package:discover
//
// 1. Discovers agent classes implementing SDK's Agent contract
// 2. Provides chat routes that invoke agents via SDK
// 3. Subscribes to SDK events for tool call & token capture
// 4. Registers a daily synapse:prune schedule when retention.auto_prune is on
//
// Any agent framework implementing SDK contracts works automatically.
// No changes to agent code required.
```

---

## Design Sync

The Figma designs (dark theme) are the visual source of truth, with the reconciliations below.

**Screens** — [Synapse on Figma](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse):

| Screen | PRD features | Link |
|--------|-------------|------|
| Discovery (agents dashboard) | Feature 1, app shell/sidebar | [324-2362](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=324-2362&m=dev) |
| Chat Playground — empty state, Info panel (Config tab) | Features 2, 4 | [307-2834](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=307-2834&m=dev) |
| Chat Playground — conversation, expanded tool card, Info panel | Features 2, 3, 4, 7 | [324-11632](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=324-11632&m=dev) |
| Chat Playground — full width, collapsed tool card (success) | Features 2, 3, 7 | [324-12263](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=324-12263&m=dev) |
| Chat Playground — collapsed sidebar, tool card (error) | Features 2, 3, 6 | [324-13008](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=324-13008&m=dev) |
| History | Feature 5 | [355-8263](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=355-8263&m=dev) |
| Components sheet (cards, tabs, modals, filters, pickers) | All — implemented via shadcn/ui (see Tech Stack → UI components) | [187-2364](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=187-2364&m=dev) |

### Ignore in design (do not implement)

- **Settings nav item** — the sidebar shows a `Settings` entry; Synapse has no runtime settings UI (configuration is `config/synapse.php`). Do not build it; designers asked to remove it.

### Implement without dedicated designs (use existing design language)

These PRD features have no Figma screens; build them from the established card/panel patterns — no designer involvement required:

- **Agent-level error card** — exception class + message with collapsible stack trace (reuse tool-card expand pattern); failover informational notice; recoverable vs fatal mid-stream error styling (Feature 6)
- **Structured-output JSON response card** — for `HasStructuredOutput` agents, reuse the JSON viewer styling from tool cards (Feature 2)
- **Info panel Config additions** — `Top_P`, `Tool Choice`, `Strict`, provider options, and the model-tier badge (`smartest` / `cheapest`) extend the existing Generation section rows (Feature 4)

### Pending designer feedback (missing from designs)

Tracked in **[DESIGN_FEEDBACK.md](DESIGN_FEEDBACK.md)** (with per-item Figma links). Summary: attachments UI (Feature 2), reasoning pane (Feature 2), provider-tool card variant (Feature 3), pending/in-flight tool state (Feature 3), light theme, History subtitle copy fix, and removal of the Settings nav item. Until the missing states land, implementation follows the PRD spec using the existing design language.

---

## Success Criteria (MVP)

1. **Zero-config discovery** — install package, run migrations, agents appear on dashboard
2. **Chat works** — send a message, get a response, tool calls visible inline
3. **Tool inspection** — expandable cards show arguments + results with syntax highlighting
4. **Error visibility** — exceptions displayed inline with stack traces
5. **Token awareness** — per-response and conversation-total token counts visible
6. **History persists** — past conversations loadable with full message + tool call state
7. **Fast setup** — `composer require` + `artisan synapse:install` → working dashboard

---

## Out of Scope (MVP)

The following `laravel/ai` capabilities are intentionally **not** part of the Synapse MVP. They may be revisited in v2 once the core chat/inspection loop is solid:

- **Vector `Stores`** (`Laravel\Ai\Stores`) — file ingestion, similarity search
- **`Files`** API — file upload / management against provider file stores
- **`Embeddings`** — embedding generation
- **`Reranking`** — result reranking
- **`Image`** — image generation
- **`Audio`** — text-to-speech
- **`Transcription`** — speech-to-text
- **Queued invocation** (`Agent::queue()`, `QueuedAgentResponse`) — the dashboard always invokes synchronously
- **Broadcast invocation** (`Agent::broadcast()`, `broadcastNow()`, `broadcastOnQueue()`) — streaming is rendered directly in the browser, not over channels
- **Anonymous agents** (`agent()` helper, `AnonymousAgent`, `StructuredAnonymousAgent`) — only class-based agents are discovered
- **Production traffic logging** — Synapse only records its own playground invocations; the SDK's `agent_conversations` table is left untouched
- **Provider file stores for attachments** — playground attachments are local uploads only (`Stored*` classes); no `ProviderImage` / `ProviderDocument` (provider file-store IDs) and no `Remote*` URL attachments in the upload UI
- **LLM-generated conversation titles** — the SDK's `RememberConversation` middleware generates 3–5 word titles via an extra agent call (`ai.conversations.generate_title`); Synapse never spends API credits on titles — see Feature 5's derivation rule
