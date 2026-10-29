# Synapse — MVP PRD

*See every connection your AI agents make.*

## Overview

**Synapse** is a development dashboard for AI agents in Laravel — a hands-on workbench for building, testing, and debugging agents during development. Think of it as the missing UI for `laravel/ai`: discover your agents, chat with them, inspect tool calls, and iterate on prompts — all from the browser.

The MVP targets **Laravel AI SDK (`laravel/ai`)** as its sole data source. By design, Synapse is compatible with **any agent framework that implements the Laravel AI SDK contracts** — if a framework dispatches SDK events, Synapse records it automatically, no adapter needed.

**Package:** `redberry/synapse`

**Target SDK version:** `laravel/ai ^1.3` (PHP ^8.3, Laravel ^12.62 or ^13.15). The integration boundary is verified against SDK v1.3.0. MCP remains optional; Composer conflicts with `laravel/mcp <1.1.0` to enforce the patched 1.x floor for [GHSA-m25c-38v7-94p8](https://github.com/laravel/mcp/security/advisories/GHSA-m25c-38v7-94p8). SDK 1.3 already excludes MCP 0.x, including its patched 0.9.7 release.

**Migration status:** steps 1–8 implement the SDK boundary, scoped recording, ordered history, SSE mapping, API/UI replay, and discovery. Deterministic qualification passes on PHP 8.3/8.4 with Laravel 12/13. Live OpenAI browser flows have also been exercised; no release is tagged or published. See [qualification](plans/laravel-ai-1.3-migration/QUALIFICATION.md) and [upgrade instructions](UPGRADE.md).

The conversational wrapper forwards skills and runtime tool replacements. Cache attributes are forwarded before application step middleware using public SDK value objects, preserving each step's current options and released tool choice. Application middleware receives `PendingStep` once per generation step.

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
| Provider / Model | Read `provider()` / `model()` methods before `#[Provider]` / `#[Model]` attributes. Normalize providers with `Provider::formatProviderAndModelList()`, showing the first provider/model pair. Provider arrays use their mapped models or provider defaults and ignore the separate model declaration, matching the SDK. When no model is specified, retain the cheapest/smartest/default tier. Discovery does not construct optional provider gateways. |
| Tools | `AgentTools` reads `HasTools::tools()` and applies `LoadSkill::mergeInto()` for `HasSkills`, including skills-only agents and deduplicating an explicit loader. Classify provider tools, raw or SDK-wrapped sub-agents, and raw or SDK-wrapped MCP tools before generic tools; preserve names, schemas, descriptions, and sub-agent links. |

Long agent names in constrained discovery/sidebar labels stay on one line, truncate with an ellipsis, and expose the full name through the label's native tooltip (`title`) on hover.

The FQCN and full configuration live in the Info panel (Feature 4), not on the card. Interface-derived capability data (`Conversational`, `HasStructuredOutput`, …) is **not rendered as card badges** — it remains in the discovery API payload for internal use (see Feature 4).

**Actions:** Click a card → opens the Chat Playground; the card's `Info` link opens the Info panel

Unavailable agents reuse `UnresolvableHint` in the playground, including sidebar/direct navigation. Hide the composer, empty-chat invitation, and stateless notice while unavailable; preserve the saved thread for inspection.

**How discovery works:**
- Scans for classes implementing the SDK's `Agent` contract
- Reads agent metadata: provider, model, tools, instructions
- No manual registration required — just create an agent class and it appears

#### Technical Implementation

**Discovery mechanism** — `AgentDiscovery` service scans configured directories (default: `app/Ai/Agents/` — where the SDK's `make:agent` generates agents — plus `app/Agents/`) using Symfony Finder, filtering classes that implement `Laravel\Ai\Contracts\Agent`:


**Metadata extraction** — For each discovered agent class, instantiate via the Laravel container (`app($class)`) and read. Note: `make()` lives on the `Promptable` trait, not the `Agent` contract — a framework-authored agent implementing only the contract won't have it, so Synapse must not rely on it:
| Data | How |
|------|-----|
| Provider / Model | Read `provider()` / `model()` methods before `#[Provider]` / `#[Model]` attributes. Normalize providers with `Provider::formatProviderAndModelList()`, showing the first provider/model pair. Provider arrays use their mapped models or provider defaults and ignore the separate model declaration, matching the SDK. When no model is specified, retain the cheapest/smartest/default tier. Discovery does not construct optional provider gateways. |
| Tools | `AgentTools` reads `HasTools::tools()` and applies `LoadSkill::mergeInto()` for `HasSkills`, including skills-only agents and deduplicating an explicit loader. Classify provider tools, raw or SDK-wrapped sub-agents, and raw or SDK-wrapped MCP tools before generic tools; preserve names, schemas, descriptions, and sub-agent links. |
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
- The playground shows an assistant-side pending response indicator immediately after send, and clears or replaces it on the first meaningful stream event so slow provider startup never looks stalled
- Synapse owns its SSE wire format and React reader. `StreamEmitter` explicitly maps SDK 1.3 event classes to the established Vercel-style text/reasoning/tool parts and Synapse-specific provider, notice, error, and completion parts. It does not call removed event serialization methods or return the SDK response directly.
- Reasoning blocks (Anthropic extended thinking, OpenAI o-series, DeepSeek) rendered inline as a collapsible "Thinking…" pane separate from the final answer; reasoning token count shown alongside prompt/completion tokens
- Structured-output agents (`HasStructuredOutput`) use a schema-forwarding `SynapseStructuredAgent` and `prompt()` with no prior user turns. Approval continuations receive the current paused turn only. Their completed `StructuredAgentResponse::structured` renders as a JSON card. The installed SDK 1.3.0 still rejects `HasStructuredOutput` in `StreamsText::stream()`; extending this Synapse path is outside this migration.
- URL citations render as one Sources list per assistant turn, updated during streaming and preserved on reload, approval continuation, and partial failure. Titles fall back to the hostname or URL. Only SDK-reported citations are included; no source-page fetching or inline character-range highlighting occurs. SDK 1.3 supports citation events across OpenAI, Anthropic, Gemini, OpenRouter, and xAI where their responses supply annotations.
- Conversation persists across page refreshes (stored in Synapse DB)
- Conversation memory mirrors the agent: multi-turn for `Conversational` agents, independent request/response per message for stateless agents (with a "Stateless" badge) — see Technical Implementation

**Inline tool call cards** (see Feature 3 for detail):
- When the agent calls a tool, a card appears inline in the chat flow
- Collapsed state: tool name + status badge (`pending` / `paused` / `success` / `denied` / `error`)
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

Synapse wraps every playground agent so SDK conversation storage stays isolated and approvals can resume. Conversational agents receive thread history; stateless and structured agents receive an empty history for each new user turn:

```php
// Synapse supplies history according to the original agent capability.
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
$history = $agent instanceof Conversational && ! $agent instanceof HasStructuredOutput ? $history : [];
$target = SynapseConversationalAgent::for($agent, $history);
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
3. Synapse wraps the agent with full history for conversational agents, or empty history for stateless/structured agents. Structured agents use a schema-forwarding subclass and `prompt()` instead of streaming. See "Synapse's approach" above
4. Calls `$target->stream($currentMessage, $attachments, …)` → returns `StreamableAgentResponse`
5. The frontend immediately inserts a transient assistant placeholder so the thread shows `Loading...` before the first SSE part arrives; later cleanup removes only entries still marked pending and preserves any reasoning already received
6. `AgentInvoker` iterates events through the scoped recorder and explicit `StreamEmitter` mapper. Preliminary tool results never settle cards; failed results emit `tool-output-error`, denied results emit `tool-output-denied`, and pending requests emit `tool-approval-request`. Invalid UTF-8 is substituted. SDK step endings are held until persistence finishes. The stream has one start, one `data-synapse-end`, and one `[DONE]`; successful/paused turns also emit one `finish`. Completion carries the saved public turn projection, so live completion and reload use identical parts/tool observations. A premature browser transport EOF becomes an inline error. An SDK response with no completion reason is retained as failed partial output.

7. `AgentInvoker` owns completion and failure. Its `then()` callback only captures the successful SDK response; persistence happens after stream consumption. `SynapseRecorder` accumulates ordered text/reasoning/tool/notice parts and canonical SDK steps. A completed or paused response is written once through `ConversationWriter`; a failure retains useful partial output and completed steps before one rich error row. A failure before any output creates no assistant row. Unknown usage on an incomplete first step stays null, not zero.
8. `MessageHistory` reads the prior thread **before** storing the new user message, preventing duplication of the current prompt. It expands each stored SDK step into assistant text/calls followed by matching tool results, then the next step. Visible text from an unfinished streamed step is saved as an additional SDK-shaped step with an unknown finish reason, so subsequent prompts retain it even after completed tool steps; it contributes no invented usage. Failed unanswered calls receive explicit interrupted-result placeholders; error cards and notices never become model context. Tool-call deserialization preserves provider fields such as Gemini thought signatures. Paused unanswered calls and raw replay blocks are excluded from new prompts. Closed turns discard raw blocks; a new prompt expires blocks retained on an earlier paused turn.
9. `RecordingScope` is container-scoped and active only inside `during()`, with `finally` restoration. Class listeners are registered once; they do not accumulate closures or remove application listeners. Prompt identity and parent/invocation IDs isolate the root run. No global SDK store binding, conversation participant, or SDK conversation ID is used. Structured agents use the schema-forwarding decorator and remain single-shot.


**Flushing — guard on `PHP_SAPI`, never on `headers_sent()`.** Each SSE part is pushed with `ob_flush(); flush();` as it is written, which is what makes the dashboard live. The guard on that flush must be the SAPI, exactly as Symfony's `Response::send()` and Laravel's own `eventStream()` do it. `headers_sent()` looks like the right question and is not: the stock `php.ini` sets `output_buffering = 4096`, so the first `echo` lands in PHP's own buffer and the headers are therefore *never* sent — the guard answers "no" for every part, of every run, and the whole conversation is assembled and painted at once. Measured on nginx + PHP-FPM with the two guards and nothing else different: **2ms to first byte versus 4019ms of a 4020ms run.** No test tier can catch this, because the feature suite and the browser driver both run Laravel in-process on the CLI SAPI, where flushing is deliberately off — `bin/check-streaming.sh` is the gate instead. `Synapse::streams()` exposes the same rule to the UI so a runtime that cannot stream says so rather than looking hung.

**Attachments** — the SDK does nearly all the work; Synapse's job is upload plumbing:

1. The send request is `multipart/form-data`: `message` + optional `attachments[]`
2. Uploads are saved to a configurable disk (`synapse.storage.attachments_disk`, default `local`, under a `synapse/` prefix) — **never base64 into the DB** (a 5MB PDF is ~6.7MB of text; MySQL `TEXT` caps at 64KB)
3. Each upload becomes a `StoredImage` / `StoredDocument` / `StoredAudio` (disk + path — built for exactly this) passed to `$wrapper->stream($prompt, $attachments)`
4. The user-message row persists the SDK's own serialization (`{type: "stored-image", path, disk, name}`) in its `attachments` JSON column — tiny rows, and history rebuild rehydrates via `File::fromArray()`, mirroring the SDK store's `rehydrateAttachments()`
5. No MIME allowlist — pass anything the developer uploads and let the provider reject what it can't handle; the error card is the point of the tool. Unsupported-type errors surface exactly like production would
6. `synapse:clear` deletes the stored files along with the rows

**Event capture** — SDK events are observed only inside the active recording scope:

| Event | Data captured |
|-------|---------------|
| `PromptingAgent` / `StreamingAgent` | Root invocation and resolved provider/model; start a provider attempt |
| `StartingStep` / `StepCompleted` | Step boundaries and completed model output, usage, reasoning, calls, and metadata |
| `InvokingTool` | Exact execution ID, name, arguments, provider, start time |
| `ToolInvoked` / `ToolFailed` | Exact execution outcome and SDK duration |
| `AgentFailed` | Completed steps and results before failure |
| `AgentFailedOver` | Informational notice; successful fallback does not create an error row |

Failure snapshots use the SDK's `AgentPrompt::runContext()->recordedResponse()` to retain successful results when a later tool in the same step throws before the stream yields them. This is a narrow dependency on an SDK-internal accessor, covered by same-step failure tests; recheck it on SDK upgrades. Steps are cloned before annotating failures so Synapse does not mutate SDK response objects.

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

**Data source** — Tool execution data comes from SDK lifecycle events:

```php
// SDK dispatches these automatically during prompt() / stream()
InvokingTool($invocationId, $toolInvocationId, $agent, $tool, $arguments)
ToolInvoked($invocationId, $toolInvocationId, $agent, $tool, $arguments, $result, $time)
ToolFailed($invocationId, $toolInvocationId, $agent, $tool, $arguments, $exception, $time)
```

**`SynapseRecorder`** inserts an observation on `InvokingTool` and updates it by the exact execution ID on `ToolInvoked` or `ToolFailed`, using SDK duration in milliseconds. Model `tool_call_id` and execution `tool_invocation_id` are separate identities. Association is restricted to one invocation attempt/step and matches name, arguments, and settled result. `ToolInvoked` results are cast to strings before persistence, matching the SDK conversion that occurs after event dispatch; custom `Stringable` results therefore retain one observation with its execution timing. Repeated identical successful calls are paired in SDK execution order. An ambiguous failed execution stays unpaired, with separate model-call observations; no result is guessed. Rejected, skipped, and approval calls may have no execution ID. Preliminary sub-agent results never complete a row or contribute extra usage.

**For streaming** — `StreamableAgentResponse` yields `ToolCall` and `ToolResult` stream events. Synapse pushes these to the browser as they happen (before the final text response). The payload is wrapped in nested data objects rather than flat fields:

| Stream Event | Payload |
|---|---|
| `Streaming\Events\ToolCall` | `$event->toolCall->id`, `$event->toolCall->name`, `$event->toolCall->arguments`, `$event->toolCall->reasoningId` |
| `Streaming\Events\ToolResult` | `$event->toolResult->id`, `$event->toolResult->name`, `$event->toolResult->result`, `$event->successful`, `$event->error` |

**Tool result mapping** — Synapse explicitly maps SDK `ToolResult` events. `preliminary` child output is ignored until the parent settles; `denied` results become `tool-output-denied`, unsuccessful results become `tool-output-error`, while successful final results become `tool-output-available`. Handler exceptions use the invocation catch-all. Approval pauses persist `paused` observations plus a durable checkpoint. `POST /api/chat/{agent}/messages/{message}/approvals` accepts a revision and explicit call-ID-keyed approve/deny decisions for the complete batch. The dashboard gate and web/CSRF middleware apply. A transaction locks the conversation and claims the pending checkpoint once. Stale, concurrent, cross-agent, and incomplete decisions cannot execute a tool.

**Approval continuation** — `MessageHistory::forApproval()` includes the paused SDK steps and provider replay blocks; normal history still excludes unanswered calls. `Decisions` resumes the saved provider/model without failover or a new user message. The original assistant row and tool observation identities are retained; new steps, display parts, duration, and SDK usage are merged once. Approved pending executions are correlated in SDK request order, with name/argument checks, so identical calls retain their own output and timing. `RecordApprovalResults` saves resolved results before application step middleware or the next provider request; streaming result events also checkpoint them. A subsequent model failure retains completed tool results. Denial supplies a result explaining the decision, allowing the model to continue without executing the rejected tool.

**Approval UI** — pending cards show arguments, the SDK reason, and Approve/Deny. One request submits immediately; a batch requires a choice for every request followed by Continue with decisions. Pending/running approvals disable sends and model selection; a new conversation remains available. Refresh restores the checkpoint. Duplicate submissions return 409 and the client reloads the saved state rather than retrying execution. An interrupted process can leave a running claim: the UI reports that outcome as unresolved, with no automatic retry. Deletion/pruning/clearing refuses conversations with running approval claims so an active execution cannot write into deleted history.

**Supported scope** — SDK `Tool` instances implementing `Approvable`, including custom/SDK tools, `McpTool`, `McpServerTool`, and approval before an `AgentTool` delegation. Provider-hosted tools and approvals raised inside a child agent are excluded. No SDK fork, wildcard approval, argument editing, or persistent always-approve setting is added.

**Provider-native tool events** — the Anthropic, OpenAI and xAI gateways yield `Streaming\Events\ProviderToolEvent` for built-in tools like web search, web fetch, file search, and code interpreter (no `InvokingTool` / `ToolInvoked` Laravel events fire for these — they only appear in the stream). Synapse renders them as tool cards visually distinguished from user-defined tools (⚡ `provider / tool_name`) with `$event->type`, `$event->data`, and `$event->status`.

**Neither `type` nor `status` is normalized by the SDK**, so Synapse normalizes both:

| Gateway | `$event->type` | `$event->status` |
|---------|----------------|------------------|
| Anthropic | the raw content-block type: `server_tool_use`, or `*_tool_result` (e.g. `web_search_tool_result`) | `started` · `result_received` · `completed` |
| OpenAI / xAI | the item type ending in `_call`: `web_search_call`, `file_search_call`, `code_interpreter_call` | `completed`, plus the third segment of `response.<x>_call.<status>` — an **open** set (`in_progress`, `searching`, …) |

Two consequences: the tool's real name is **not** in `type` for Anthropic (`server_tool_use` is generic; the name is at `$data['name']`), so the card resolves `$data['name'] ?? $type` and takes provider identity from each observation's `provider`. And the status is mapped into Synapse's own `pending` / `success` / `error`, defaulting **unknown values to `pending`** so a new provider status shows an in-flight card rather than a wrong terminal one — with the raw string retained alongside. See [plans/epic-04-tool-inspection](plans/epic-04-tool-inspection/PLAN.md#3-provider-tool-events-are-messier-than-the-prd-says). The recorder persists them as `synapse_tool_invocations` rows with `type = provider_tool`, keyed by conversation, root invocation, provider, and `$event->itemId` (upserted as status transitions arrive in the stream). Correlation is best-effort: Anthropic keys the start block on `content_block.id` and the result block on `tool_use_id ?? id`, which match only when the provider sends `tool_use_id` — a miss produces a second card rather than welding the wrong result onto the first. Synapse forwards provider identity and the event payload as a custom `data-provider-tool` part. These tools are also *declarable* — `tools()` can return `ProviderTool` instances (`WebSearch`, `WebFetch`, `FileSearch`), so they appear in the Agent Info Panel too, not just as stream artifacts.

**Mid-stream errors** — recoverable events become notice parts. A terminal event is consumed through the SDK's exception path, then the invocation catch-all writes one error row; the event and its `StreamErrorException` must never create duplicate errors. Provider type/metadata remain in error metadata. Unfinished observations are closed as errors when the run terminates, except genuinely paused calls.

**Error capture** — throwing handlers dispatch `ToolFailed` and propagate to the invocation catch-all. Successful tool work before a failure remains available in the stored steps. A sub-agent's handled failure remains the parent's ordinary result, matching SDK behavior.

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
- **Sub-agents** (raw `Agent` instances or SDK `AgentTool` wrappers) — "Agent tool" badge, linking to that agent's own Synapse page
- **MCP tools** — wrap in `McpTool` / `McpServerTool` (both implement `Tool`, so name/description/schema come for free), badged "MCP"

#### 4d. Middleware
- List of middleware classes applied to this agent

**Where the panel lives** — a right-hand panel inside the Chat Playground (`/playground/{agent}`), opened from an agent card's `Info` link or the playground header. Its state is a query parameter (`?info=config|prompt|tools`) so it survives a reload and can be shared; `?info=1` opens the default tab. Sub-agent tools link to that sub-agent's own Info panel.

#### Technical Implementation

**All data comes from the agent instance** — resolved via the Laravel container (`app($class)`; `make()` is on the `Promptable` trait, not the `Agent` contract, so it can't be assumed):

| Section | SDK Source |
|---------|-----------|
| Provider / Model | Read `provider()` / `model()` methods before `#[Provider]` / `#[Model]` attributes. Normalize providers with `Provider::formatProviderAndModelList()`, showing the first provider/model pair. Provider arrays use their mapped models or provider defaults and ignore the separate model declaration, matching the SDK. When no model is specified, retain the cheapest/smartest/default tier. Discovery does not construct optional provider gateways. |
| Temperature / Max Tokens / Max Steps / Top P / Tool Choice | `TextGenerationOptions::forAgent($agent)` — public static, resolves all five with the SDK's own attribute/method precedence. Tool choice shows mode (`auto` / `none` / `required` / forced tool name) — invaluable when debugging "why won't my agent call the tool" |
| Strict mode | `#[Strict]` attribute (OpenAI strict structured output) — shown as a small badge |
| Timeout | `timeout()` method, or `#[Timeout]` attribute, or default `60` (method takes precedence) |
| System Prompt | `$agent->instructions()` → `string` |
| Tools | `AgentTools` reads `HasTools::tools()` and applies `LoadSkill::mergeInto()` for `HasSkills`, including skills-only agents and deduplicating an explicit loader. Classify provider tools, raw or SDK-wrapped sub-agents, and raw or SDK-wrapped MCP tools before generic tools; preserve names, schemas, descriptions, and sub-agent links. |
| Middleware | `$agent instanceof HasMiddleware ? $agent->middleware() : []` |
| Provider Options | `HasProviderOptions::providerOptions(Lab::tryFrom($provider) ?? $provider)` — standard providers receive the SDK enum; custom names remain strings. |
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
    ->withSum('messages as total_input_tokens', 'input_tokens')
    ->withSum('messages as total_output_tokens', 'output_tokens')
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

**One catch-all is the backbone.** `AgentInvoker` catches failures from agent resolution, decoration, SDK invocation, stream iteration, and persistence. `ConversationWriter` retains partial assistant output when there is any, then writes one error row with exception class, message, stack trace, and available provider response details. `ToolFailed` records the exact failed execution; final cleanup closes unfinished root observations without touching nested or unrelated runs.

Provider failover and recoverable stream events become informational notices. Before a failover changes the active attempt, recording captures the outgoing prompt's run context, including completed tool results: retryable attempts do not emit `AgentFailed`. Successful executions retain their result, timing, call ID, and original provider without a duplicate unanswered-call card. Terminal stream errors and their wrapping exceptions share the same error path. SDK-reported failed tool results remain failed observations without inventing handler executions. A sub-agent that catches its own failure can still return an ordinary parent tool result. New prompts replay only message context, never these diagnostic notices or error cards.

**Error display** — every caught error becomes a `synapse_messages` row with `role = error` (exception class + message + collapsible stack trace in `metadata`), rendered as an error card at the position in the thread where it occurred. Because the same catch-all runs for every invocation, there is no code path where an agent failure escapes to a generic Laravel error page.

---

### Feature 7: Token Counter

Subtle but essential for prompt engineering.

- Each assistant response shows: `↑ 340 tokens · ↓ 128 tokens` (inclusive input and output)
- Running total at top of conversation: `Total: ↑ 1,240 · ↓ 512 `
- Helps developers spot bloated prompts and optimize token usage

#### Technical Implementation

**Data source** — The SDK's `TextUsage` class (available on every response):

```php
// From AgentResponse / StreamedAgentResponse
$response->usage->inputTokens
$response->usage->outputTokens
$response->usage->cacheWriteInputTokens
$response->usage->cacheReadInputTokens
$response->usage->reasoningTokens
```

Usage is aggregated from recorded SDK steps once per assistant turn, including completed work before a failure. The client assigns aggregate usage to one assistant display entry only; multiple blocks do not multiply counts.

**Storage** — Each `synapse_messages` row with `role = assistant` stores `input_tokens` and `output_tokens` as promoted integer columns (for cheap SQL aggregates), with the full `TextUsage::toArray()` breakdown — including cache and reasoning tokens — in the `usage` JSON column (see Database Schema).

**Conversation totals** — Summed on the fly:
```php
$conversation->messages()
    ->whereNotNull('input_tokens')
    ->selectRaw('SUM(input_tokens) as total_in, SUM(output_tokens) as total_out')
    ->first();
```

**Extended token breakdown** (optional detail on hover/expand):
- Cache write/read tokens are subsets of input; reasoning is a subset of output. Never add them again. Null means unknown and is hidden; reported zero stays visible. Input plus output is the total.
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
- **Agents** — quick list of discovered agents for fast switching into a playground; constrained labels truncate with an ellipsis and keep the full name in a native tooltip
- **Workspace nav** — `Discovery` (the agents dashboard, Feature 1) and `History` (Feature 5). *No Settings entry* — Synapse has no runtime settings UI; configuration is file-based (see Design Sync)
- **Footer** — package version + discovered agent count (e.g. `v1.0.0 · 8 agents`)
- Collapsed state shrinks the sidebar to the logo; the chat playground remains fully usable

**HTTP API surface:**

| Method | Route | Purpose |
|--------|-------|---------|
| `GET` | `/synapse/api/agents` | List discovered agents with card metadata (Feature 1) |
| `GET` | `/synapse/api/agents/{agent}` | Full agent detail: config, system prompt, tools, middleware (Feature 4) |
| `POST` | `/synapse/api/chat/{agent}/send` | Send a message (`multipart/form-data`: `message` + optional `attachments[]`, `provider`, `model` overrides); responds with the Vercel-protocol SSE stream (Feature 2) |
| `POST` | `/synapse/api/chat/{agent}/messages/{message}/approvals` | Submit the current revision and explicit decisions for all pending calls; resumes the original turn over SSE |
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

- **Open in `local`, denied by default everywhere else.** In the `local` environment, the dashboard requires no authentication. Elsewhere, `Synapse::check()` returns false unless an authorization callback has been registered; the standard installer does not load the published application provider outside `local`.
- **`viewSynapse` gate after explicit provider registration** — to use Synapse outside `local`, register the published application provider while the package is installed and configure its gate. That provider installs the authorization callback used by every Synapse route (dashboard *and* API, including chat):

```php
// Published into the app by synapse:install (SynapseServiceProvider stub,
// same pattern as Telescope's TelescopeApplicationServiceProvider)
Gate::define('viewSynapse', function ($user) {
    return in_array($user->email, [
        //
    ]);
});
```

- **Production requires explicit opt-in.** In `production`, Synapse's routes do not register at all unless `SYNAPSE_ENABLED=true` is explicitly set — the existing `'enabled' => env('SYNAPSE_ENABLED', true)` config default applies to non-production environments only. Enabling routes alone does not grant access: requests are denied until authorization is configured. With the published application provider explicitly registered, requests must pass its `viewSynapse` gate. Defense in depth: a forgotten `composer require` on a production box must not become an unauthenticated agent-invocation endpoint.
- **`synapse:install` publishes the gate stub** so the definition lives in the app where developers can customize it. The stub extends Laravel's `ServiceProvider` directly and only calls Synapse behind a `class_exists` guard, so it remains safe to bootstrap after `composer install --no-dev` removes the package. The installer registers that provider from `AppServiceProvider` only when the application is `local` and `SynapseApplicationServiceProvider` exists. This condition controls the published gate provider; route registration is controlled separately by `synapse.enabled`.
- **Installer registration is idempotent and migrates old installs.** Re-running the command preserves host application edits, never duplicates the guarded block, and removes the old unconditional `bootstrap/providers.php` entry only when that exact provider is present; otherwise the bootstrap file is left byte-for-byte unchanged. The installer compares PHP tokens throughout the top-level body of `register()`, preserving statements added before or after the generated block. It ignores whitespace and comments without treating strings, nowdocs, nested closures, or other methods as registration statements. Only the complete generated block in that method scope counts as an existing installation; any remaining `SynapseServiceProvider` reference (including qualified names, strings, and alias imports) causes an actionable error before the application provider or bootstrap registration is changed. This conservative check rejects ambiguous references rather than attempting to rewrite host code or resolve aliases.
- **Package removal unregisters the published provider when Composer dispatches its dev-mode pre-uninstall event.** The self-contained stub remains the fallback for `--no-dev`, where Laravel intentionally does not dispatch package-uninstall events.

---

## Database Schema

### Storage strategy (Telescope model)

Synapse follows the exact pattern proven by Telescope and used by `laravel/ai` itself: **migrations run against the user's database by default, with a configurable connection override.**

- **Default:** tables are created on the app's default connection — the same approach as the SDK's own `AiMigration` (`config('ai.conversations.connection', config('database.default'))`).
- **Override:** `SYNAPSE_DB_CONNECTION` points Synapse at any connection defined in `config/database.php`. Each self-contained published migration and all models resolve their connection from this config, mirroring `AiMigration::getConnection()` without depending on package classes after publishing.
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

The `steps` column stores ordered `Responses\Data\Step::toArray()` serializations, including calls/results, finish reason, usage, provider/model, reasoning, replay blocks, and provider tool calls. This is the public response shape, not the SDK database store's compact format. SDK replay data stays private; never return `steps` wholesale in an API response. Provider history is rebuilt in step order with public SDK message constructors and call/result deserializers.

The `parts` column holds the display sequence in array order. Each block has a stable per-turn `id` and `type`: `text`/`reasoning` blocks carry `text`; `tool` blocks carry the Synapse observation row's `tool_invocation_id` (the row primary key, not the SDK execution ID); notice/error blocks carry the existing safe display payload. Tool status, duration, and result remain owned by `synapse_tool_invocations`. The sequence is a compact transcript, not a raw stream-event log. The recorder writes this sequence. `ConversationPresenter` supplies the same explicit public projection to conversation reload and stream completion; private steps, replay blocks, and provider call fields are never part of that projection. Client stream cleanup preserves assistant entries with recorded usage even without text, reasoning, or structured output, so tool-only paused/failed turns retain immediate metadata and cumulative totals.

**Cited sources** — `CitationSources` validates and merges URL/title pairs into the existing `meta.citations` field. Exact URLs deduplicate in first-seen order; later nonempty titles enrich missing titles. Only absolute HTTP/HTTPS URLs with a host and no credentials, control characters, spaces, or backslashes are accepted. Unicode hostnames, paths, queries, and fragments are validated using an ASCII URI representation while the original URL is retained for display and exact-URL deduplication. The recorder captures root citation events immediately and merges completed step/response metadata without clearing prior attempts. Its snapshot uses a fresh Meta so step metadata is not mutated. Approval continuation merges saved and new sources on the original assistant row; citation-only failures count as useful partial output even without text or steps. No new table/column is required.

`ConversationPresenter` exposes a normalized `sources` array (`url`, nullable `title`) identically for completion and reload and normalizes citations in public metadata. Historical citation metadata in saved steps is a read-only fallback; missing historical citations cannot be reconstructed. Private replay blocks and raw response objects remain excluded. `source-url` parts carry the same safe URL/title fields. The client merges sources on the terminal assistant entry without moving text/tool parts or multiplying usage, retains source-only entries, and renders titles as text with safe new-tab links. Source-only footers have a separate identity from reusable loading placeholders, so later text or structured answers stay after intervening tools/notices even when a stream is interrupted. Empty source footers are removed after their sources transfer to an answer; reasoning already displayed is preserved. Range data already in private SDK steps is unchanged; no turn-wide offset mapping is inferred.

**Completion reasons** — `CompletionReason` resolves the final root `StreamEnd` from a completed `StreamedAgentResponse`, including cancellation without a model step. For non-streaming responses it reads the final current-response step. This uses the SDK's existing event collection rather than a second recorder state machine. Completed response reasons are allow-listed SDK `FinishReason` values, separate from Synapse lifecycle status. A failed invocation without a completed response does not inherit the reason of an earlier attempt.

`ConversationWriter` replaces `meta.finish_reason` on every turn write, including approval continuation, and preserves explicit null. `ConversationPresenter` exposes nullable `finish_reason` alongside `status`. Only historical rows lacking the metadata key fall back to the last stored step; missing or unrecognized values produce no explanation. No rows are rewritten on read and no migration is needed. SSE completion uses this same persisted projection rather than defaulting missing or exceptional reasons to stop.

Completed turns show “Output token limit reached.” for length, “Stopped by content filtering.” for content_filter, or “Generation was cancelled.” for explicitly reported cancellation. Normal stop, tool_calls, and continue add no notice. Failed and paused turns suppress completion notices in favor of their existing error/approval UI. A new explicit error or unknown terminal reason enters the existing single error handler, preserving partial content and detailed provider errors. Historical completed error/unknown records get read-only explanations without changing status. Non-streaming middleware stops that report no reason are not labeled cancelled. There is no new cancellation control or automatic retry.

The notice lives on the terminal assistant display entry beside sources and usage, including explanation-only answers. Completion and history use the same projection, preventing duplicate notices or changes to reasoning/tool order and token totals. The step inspector can reuse the typed finish-reason field without exposing raw replay data.

**Step inspector** — saved assistant turns expose an allow-listed `step_details` projection from `StepPresenter`, shared by completion and history. `Steps (N)` is a collapsed, read-only turn footer after the existing display parts, including tool-only and failed turns. Expanding it exposes ordered generation steps with provider/model, text, available reasoning, normalized finish reason, nullable token counts, associated tool calls/results, and model-call duration. Its own `steps` chat entry never contributes to aggregate usage or changes text/tool chronology. Details mount only when expanded; keyboard buttons, theme tokens, and wrapping support narrow layouts. Existing approval controls remain the only place to submit decisions. Both the inspector and individual steps start collapsed. Each step uses a compact clickable header with its number, truncated provider/model, short status, finish reason, and duration; it wraps to two lines when space is limited. Full model names and completion details remain available when expanded.

A seventh additive migration introduces nullable private `step_observations` JSON-in-text on `synapse_messages`, leaving SDK-shaped `steps` unchanged. The recorder aligns these observations with snapshot order across attempts, retaining root `StepCompleted` timing, original model finish reason, response/requested provider/model fallback, completion evidence, and finalized tool observation IDs. Completed model calls remain completed even if later tool execution fails. Approval continuation preserves prior observations and their tool IDs while appending new steps; resumed results update the existing step. Child events and agent traffic outside the recording scope are excluded.

Completion evidence distinguishes `completed` (SDK model-call event), `response` (complete SDK response without an observed model event, such as middleware), `incomplete` (synthetic partial replay step), and `unverified` (historical provenance unavailable). Only completed model events supply model-call milliseconds; zero is valid. Partial replay placeholder usage is projected as null, and available partial reasoning is retained separately. Historical counts are displayed as stored, missing counts remain null, and cache/reasoning subsets are never added again to input/output totals. Model-call time excludes local tool execution and approval waiting but can include provider-hosted work; durations are not summed.

The projection selects fields explicitly and never sends private serialized steps or observation bookkeeping. New tool association reuses the recorder's finalized mapping. Historical calls/results remain inspectable from their saved step, without guessing which same-name observation supplies timing. Unlinked provider tool entries expose their ID/type with unavailable payload/timing, not arbitrary SDK provider data. Linked observations reuse the tool display fields already available to the client. Missing or malformed optional historical fields degrade to unavailable; reads never rewrite history. No new request viewer, pricing, live inspector stream, editable replay, nested trace, or production recording is introduced.

`status` is `completed`, `paused`, or `failed`; the database defaults to `completed`. `input_tokens` and `output_tokens` promote the inclusive counts in `TextUsage::toArray()`. Cache counts and reasoning counts are optional subsets; totals must not add them again. Existing content, attachments, usage, meta, metadata, duration, timestamps, and indexes remain.

A fourth, self-contained migration transforms empty legacy storage. It refuses any rows in any Synapse table before altering schema, on both upgrade and rollback. It never clears data or accesses SDK tables. Existing installations must stop Synapse traffic and explicitly clear their history/attachments first; see [the reset procedure](DEV.md#laravel-ai-13-storage-reset). A fifth self-contained migration adds nullable model-call and provider fields to observations and makes execution IDs nullable. It refuses rollback while observations exist. The first three published migrations remain unchanged. A sixth additive migration adds nullable `approval_state` and `approval` text columns to messages without resetting existing history. `approval_state` is pending/running or null; private `approval` stores the SDK pending requests, revision, provider/model, and submitted decisions. Only state, revision, and pending requests are projected to the browser. Pre-upgrade paused rows have no checkpoint and are not resumable.

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
    $table->text('steps')->nullable();            // private SDK Step::toArray() sequence
    $table->text('step_observations')->nullable(); // private timing, provenance, and tool links
    $table->text('parts')->nullable();            // ordered display blocks with stable per-turn IDs
    $table->string('status', 25)->default('completed');
    $table->string('approval_state')->nullable();
    $table->text('approval')->nullable();
    $table->text('usage');                        // TextUsage::toArray(), including nullable subsets
    $table->unsignedInteger('input_tokens')->nullable();      // inclusive input count
    $table->unsignedInteger('output_tokens')->nullable();     // inclusive output count
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
    $table->string('tool_invocation_id')->nullable()->index(); // SDK execution event ID
    $table->string('tool_call_id')->nullable();   // model/provider call ID, separate from execution
    $table->string('provider')->nullable();       // provider that performed this call
    $table->string('type', 25);                   // tool | provider_tool
    $table->string('name');                       // tool name, or provider tool type (anthropic.web_search)
    $table->text('arguments');                    // JSON
    $table->text('result')->nullable();           // JSON
    $table->string('status', 25);                 // pending | paused | success | denied | error (normalized)
    $table->string('provider_status')->nullable(); // the provider's own word for it, unnormalized
    $table->text('error')->nullable();
    $table->unsignedInteger('duration_ms')->nullable();
    $table->timestamp('started_at')->nullable();  // chronological card placement in the thread
    $table->timestamp('finished_at')->nullable();
    $table->timestamp('created_at');
});
```

**Why `synapse_tool_invocations` is its own table:**

1. **The decorator reads only `synapse_messages`** and rebuilds each SDK step in order — no bespoke re-aggregation of tool-call rows back into the SDK's message sequence (fiddly with multi-step tool loops).
2. **Provider tool events fit naturally** — they never fire Laravel events and never become messages, but they're first-class rows here via the `type` discriminator.
3. **SDK churn is absorbed where it belongs** — messages JSON follows SDK shapes automatically; invocation columns are Synapse's own observations.
4. Feature 5's tool-call count becomes a trivial `withCount('toolInvocations')`.

**Row lifecycle & management** — the recorder (`SynapseRecorder`) owns these rows end to end:

1. **Insert** — `InvokingTool` creates a `status = pending` row (`type = tool`) tagged with both `invocation_id` (the agent run) and `tool_invocation_id` (the specific call). Provider-native tools have no Laravel events, so they're upserted from the stream keyed by conversation, root invocation, provider, and `ProviderToolEvent->itemId` (`type = provider_tool`) as their `in_progress → completed / failed` transitions arrive.
2. **Complete** — `ToolInvoked` updates the row to `success` with `result`, `duration_ms`, `finished_at` (fires on success only — see Feature 3).
3. **Fail** — `ToolFailed` settles the exact execution as `error`; final cleanup closes unfinished observations for the root run (Feature 6).
4. **Link** — when the assistant turn persists, the recorder stamps its `message_id` onto the root observations. Ordered `parts` reference these row IDs. Useful partial turns are persisted on failure before the separate error message.
5. **Delete** — rows are removed with their conversation. Cascade is handled in the repository layer (a `SynapseConversation` deletes its `messages` and `toolInvocations`), **not** via DB foreign keys — sqlite's FK pragma is off by default, so Synapse never relies on it (Telescope prunes the same way). `synapse:clear` / `synapse:prune` go through the same repository path.

Live cards follow stream order. Completion replaces the live turn with its persisted display sequence. Reload uses the same `parts` ordering and observation IDs; it never merges messages/tools by UUID or timestamp. Tool provider labels come from each observation.

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
