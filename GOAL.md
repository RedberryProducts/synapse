# Synapse

*See every connection your AI agents make.*

Synapse is a development dashboard for AI agents built with the [Laravel AI SDK](https://github.com/laravel/ai) (`laravel/ai`). It discovers the agents in your app, lets you chat with them in the browser, and shows you exactly what happens under the hood — every tool call, every token, every reasoning step, every error.

Think of it as the missing UI for `laravel/ai`, in the same spirit as Telescope, Horizon, and Pulse: install it, open a URL, and start working.

> **This document is the product specification written from the user's point of view.** It describes how Synapse behaves once installed. Everything here is what the package delivers.

---

## Table of contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Your first session](#your-first-session)
- [The interface](#the-interface)
- [Features](#features)
  - [Agent discovery](#agent-discovery)
  - [Chat playground](#chat-playground)
  - [Tool inspection](#tool-inspection)
  - [Agent info panel](#agent-info-panel)
  - [History](#history)
  - [Errors](#errors)
  - [Token counting](#token-counting)
- [Configuration](#configuration)
- [Access control & environments](#access-control--environments)
- [Where your data lives](#where-your-data-lives)
- [Data retention](#data-retention)
- [Artisan commands](#artisan-commands)
- [Theming](#theming)
- [Compatibility](#compatibility)
- [What Synapse does not do](#what-synapse-does-not-do)
- [Planned](#planned)
- [Troubleshooting & FAQ](#troubleshooting--faq)

---

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 8.3+ |
| Laravel | 12 or 13 |
| `laravel/ai` | 0.9.x |

Synapse installs alongside your existing `laravel/ai` setup. It reads the agents and providers you already have — you don't configure providers or API keys in Synapse; it uses your app's `config/ai.php`.

---

## Installation

```bash
composer require synapse-ai/synapse --dev
php artisan synapse:install
```

`synapse:install` does everything needed:

- Publishes `config/synapse.php`
- Runs Synapse's migrations
- Publishes a `SynapseServiceProvider` into your app (where the access gate lives — see [Access control](#access-control--environments))

You never run `npm` — Synapse ships pre-built assets.

Then open:

```
https://your-app.test/synapse
```

In your local environment, that's all there is to it. The dashboard is open, your agents are already listed, and you can start chatting.

---

## Your first session

1. **Create an agent** the usual way (or use one you already have):

   ```bash
   php artisan make:agent SupportAgent
   ```

   `make:agent` creates it in `app/Ai/Agents/`. Any class there (or in `app/Agents/`) implementing the SDK's `Agent` contract is picked up automatically — no registration, no annotations.

2. **Open `/synapse`.** Your agent appears as a card on the Discovery page, showing its provider, model, and tools.

3. **Click the card.** You're in the chat playground.

4. **Send a message.** The response streams back in real time. If the agent calls tools, you'll see each call appear inline as a card with its arguments and results. Token counts and timing show on every response.

5. **Iterate.** Change your agent's prompt, tools, or config in code, refresh, and test again. Synapse always reflects your current code — there's no cache to bust.

That's the whole loop Synapse is built for: **discover → chat → inspect → repeat.**

---

## The interface

Every page sits inside a persistent, collapsible sidebar:

- **Recent Conversations** — your latest chats across all agents, each with its agent name, a short title, a call count, and an error indicator if something failed. Each has a menu to open, rename, or delete it.
- **Agents** — a quick list of discovered agents for jumping straight into a playground.
- **Workspace** — navigation between **Discovery** (the agents dashboard) and **History** (all past conversations).
- **Footer** — the Synapse version and how many agents were discovered (e.g. `v1.0.0 · 8 agents`).

Collapse the sidebar to the logo when you want more room for a conversation.

---

## Features

### Agent discovery

The Discovery page is your landing page. It scans your project on every request and lists every agent it finds — so a newly created agent appears the moment you refresh.

Each **agent card** shows:

- **Name** — the class short name (e.g. `SupportAgent`)
- **Provider / model** — e.g. `anthropic / claude-sonnet-5`
- **Tools** — a chip per tool; if there are many, they collapse into a `+N` chip you can hover to see the full list

Click a card to open its playground, or click **Info** to open the [agent info panel](#agent-info-panel).

**How discovery works:** Synapse looks in the directories you configure — by default `app/Ai/Agents/` (where `make:agent` generates agents) and `app/Agents/` — for classes implementing `Laravel\Ai\Contracts\Agent`. There's no manual registration — creating the class is enough. Because discovery runs fresh each request, it always matches your current code (ideal during active development).

### Chat playground

The playground is a real conversation with a real agent. Messages stream token-by-token as the model generates them.

**Composing messages:**

- **Text** — type and send.
- **Attachments** — attach images, documents, or audio with the file picker or by dragging files onto the composer. Attachments show as chips before you send, and as thumbnails (images) or chips on your message afterward. They stay attached for the rest of the conversation, so a follow-up question about the same file still works. There is no file-type allowlist: anything your provider rejects comes back as an error card explaining why, which is exactly what production would do. (Files go to your app's storage — see [Where your data lives](#where-your-data-lives).)
- **Model selector** — a dropdown in the composer lets you run the next message on a different model without touching your agent's code. It always offers the agent's own configured model (the default) plus its provider's **cheapest** and **smartest** tiers, and any extra models you list in config (see [Configuration](#configuration)). Whatever model actually ran is recorded per message, so replaying a conversation always shows the truth. The choice lasts while you're on the page and resets to the agent's own model when you come back — an override is an experiment, and you should never return later and mistake one for how the agent is actually configured.

**In the response, you'll see:**

- **Streaming text** — the answer as it arrives.
- **Reasoning** — for models with extended thinking (Anthropic, OpenAI o-series, DeepSeek), `✦ Thinking…` appears while the model works, then collapses into a "Thinking" pane above the answer, with its own reasoning-token count in the per-message counts. Collapsed by default so it never gets in the way — and kept, so reopening the conversation later shows the same thinking you watched.
- **Tool calls** — inline cards, in the order they happened (see [Tool inspection](#tool-inspection)).
- **Structured output** — if your agent returns structured data, the response renders as a formatted, collapsible JSON card instead of plain text. Structured-output agents can't stream (the SDK doesn't support it), so Synapse runs them in one shot and shows the finished answer — the only visible difference is that the text arrives all at once.
- **Metadata** — prompt/completion token counts and response time on each answer, plus the model that ran it whenever you overrode the agent's own.

**Conversation controls:**

The **⋮ menu** in the playground header holds:

- **New conversation** — start a fresh thread with the same agent.
- **Clear conversation** — delete the current thread and return to an empty playground.

**Switch agent** — pick another agent (from the sidebar or Discovery); a new conversation starts automatically.

**Opening an agent returns you to where you left off** — the conversation you were reading, or a blank page if that's where you deliberately were after **New conversation**. The conversation's id lives in the address bar (`?c=…`), so refreshing keeps you in the same thread and the link is shareable. This is remembered per browser, not stored with your data, so nothing reappears on a machine you've never used. To jump to any other conversation, pick it from [History](#history) or the sidebar.

**Conversation memory mirrors your agent — Synapse never fakes it.** The playground always shows your messages as a thread, but whether the agent actually *remembers* earlier messages depends entirely on your agent:

- **If your agent supports conversation memory** (it implements the SDK's `Conversational` contract — most commonly via the `RemembersConversations` trait), the playground is a true multi-turn conversation. Each message is sent with the full thread, so the agent has the earlier context. Synapse supplies that history from **its own** stored messages rather than your app's conversation tables, so you don't have to call `forUser()` or `continue()` to try an agent out, and nothing you do in the playground touches your production conversation data.
- **If your agent is stateless** (it doesn't implement `Conversational`), each message is sent to the agent on its own, with **no earlier messages attached** — exactly how it behaves in production. Synapse still keeps the messages together in one session so you can read them as a thread, and marks the agent **Stateless** so you know why it won't recall previous turns.

- **One exception: structured-output agents.** An agent implementing `HasStructuredOutput` runs single-shot with no earlier messages attached, even if it is also `Conversational`. This comes from the SDK, not from a choice Synapse made: `StreamsText::stream()` rejects any agent with structured output, so these never take the streaming path, and the wrapper Synapse uses to supply conversation history can't carry the structured-output contract without breaking every other conversational agent. In practice an agent that extracts a fixed shape from one input rarely wants memory — but if yours does, it will not have it here.

The point: what you see in Synapse is what you'd get in production. Synapse won't give your agent memory it doesn't actually have — if you need multi-turn behavior, that's a signal to make your agent conversational in code.

### Tool inspection

Whenever an agent calls a tool, a card appears inline in the conversation. This is the heart of debugging agent behavior.

A tool card is **collapsed** by default, showing the tool name, a status badge, and how long it took:

```
🔧 searchProducts                    ✅ 45ms
```

**Expand it** to see the full picture:

- **Arguments** — the exact input the model sent, as formatted JSON
- **Result** — what the tool returned, as formatted JSON
- **Errors** — if the tool failed, the card shows the error in place of the result

Tool cards have three states you'll see live as a message streams:

- **Pending** — the tool is still running (shown with a progress indicator)
- **Success** — completed normally
- **Error** — the tool threw

A failed tool gives you **both** a failed card and an error card: the card tells you *which* tool, the error card tells you *what went wrong*, with the stack trace a click away.

**Provider tools** (built-in provider capabilities like web search, web fetch, file search, and code interpreter) get their own distinct card style — marked with a ⚡ and labelled `provider / tool` — so you can tell them apart from your own tools at a glance. These run inside the provider, and every provider describes them differently: Anthropic says `started` and `result_received`, OpenAI says `in_progress` and `searching`. Synapse normalizes those into the same three states so the cards stay readable, and **keeps the provider's own word for it on hover**, because when you're debugging you want both. A status Synapse doesn't recognise stays *pending* rather than being guessed at.

Cards appear **where the call happened**. When an agent narrates, calls a tool, then keeps talking, you see that sequence — text, card, text — rather than the answer with its tool calls swept to one side. Multiple calls in one step stack as separate cards, each independently expandable.

Two things that look like bugs but aren't:

- **A sub-agent that fails shows as a *success*.** When one agent calls another as a tool, the SDK catches whatever the sub-agent throws and hands the model the string `Agent failed: …` as an ordinary result. That string is what your agent actually received and reasoned from, so that's what the card shows. Expand it and you'll see the failure.
- **Occasionally a provider tool produces two cards.** Some providers key the "started" and "finished" halves of a call differently, and when Synapse can't be certain the two belong together it shows both rather than attaching a result to the wrong call.

### Agent info panel

Open the **Info** panel from any agent card or from inside the playground to see the agent's full configuration — everything the SDK will use when it runs, read straight from your agent class. It's organized into tabs:

- **Config**
  - Provider and model (and the model tier — cheapest/smartest — if your agent uses one)
  - Generation settings: temperature, max tokens, max steps, top-p, tool choice, timeout, and strict mode
  - Any custom provider options
  - The agent's tools, as chips
- **Prompt** — the agent's full system instructions, rendered as markdown so it's easy to read and verify.
- **Tools** — every registered tool with its details:
  - **Your tools** — name, description, and each parameter with its type, whether it's required, and its description
  - **Output schema** — for agents that return structured data, the shape they produce
  - **Provider tools** — labelled `⚡ Provider tool` with their options
  - **Sub-agents** (agents used as tools) — labelled and linked to that agent's own page
  - **MCP tools** — labelled `MCP`

The info panel is a read-only reflection of your code. Change the agent, refresh, and it updates. Its state lives in the URL, so you can link a colleague straight to an agent's Prompt or Tools tab.

### History

The History page is the searchable record of every conversation you've had in the playground.

Each row shows the **agent**, the conversation **title** (the first message, truncated), a **status** icon (success or error), the total **tokens**, and the **date & time**.

**Find conversations with:**

- **Search** — across conversation titles and message content
- **Filters** — by agent, by status (success/error), by tools used, and by date range
- **Sort** — newest or oldest first
- **Pagination** — 25 per page

**On each row you can:**

- **Open** — reopen the conversation with the full thread restored: every message, every tool card, exactly as it happened
- **Rename** — give the conversation a memorable title (titles are never auto-generated by an LLM — Synapse never spends API credits on your behalf)
- **Delete** — remove it (with a confirmation prompt)

A conversation is marked **error** if the agent itself failed at some point. A tool that failed but the agent recovered from does **not** mark the whole conversation as failed — that's normal agent behavior, not a conversation error. (If a failed tool turned the row red, you'd learn to ignore the column within a day, and then it would stop working for the failures that matter.)

**Filters live in the URL**, so reloading restores the same view and you can send someone a link to a filtered list.

**A conversation outlives the agent that made it.** Delete or rename an agent class and its conversations stay in History — they're Synapse's own records, not a mirror of your code. Opening one replays the whole thread; the composer is disabled, with a note explaining that the agent no longer exists.

### Errors

**Anything** that goes wrong while running your agent — a provider error, a timeout, a bug in one of your own tools, a misconfigured agent — is caught and shown inline as an error card. Synapse never drops you on a broken page, a dead stream, or a raw 500. Seeing failures clearly is the whole point of a debugging tool.

- **Agent/LLM errors** (rate limits, auth failures, timeouts, invalid requests) appear as an error card showing the exception class and message, with a collapsible stack trace. Fix your code and try again — no reload needed.
- **Errors in your own tools** — if a tool throws while running, its card is marked failed and an error card explains what happened (class, message, stack trace), so a bug in tool code is easy to spot.
- **Failover** — if your agent is configured to fail over between providers and does, Synapse shows an informational notice (not an error), so you know a fallback kicked in.
- **Mid-stream errors** — if a provider reports a problem partway through generating, Synapse shows it in place; recoverable hiccups are styled more softly than fatal errors.

### Token counting

Every assistant response carries its token cost, so you can spot bloated prompts and optimize as you go:

- **Per response:** `↑ 340 · ↓ 128` (prompt in / completion out)
- **Per conversation:** a running total at the top of the thread

Expand the token detail to see the full breakdown, including **cache-read**, **cache-write**, and **reasoning** tokens — useful when tuning prompt caching (Anthropic) or reasoning usage (OpenAI o-series).

---

## Configuration

Everything is configured in `config/synapse.php`. Synapse has **no in-app settings screen** — configuration is code, versioned with your project.

```php
return [

    // Master switch. In production, Synapse only registers if this is
    // explicitly true (see "Access control & environments").
    'enabled' => env('SYNAPSE_ENABLED', true),

    'ui' => [
        // The dashboard is served from this path: /synapse
        'path' => 'synapse',

        // Middleware applied to every Synapse route.
        'middleware' => ['web'],
    ],

    'discovery' => [
        // Directories scanned for agent classes. The first is where
        // `php artisan make:agent` puts them; the second is a common alternative.
        'paths' => [
            app_path('Ai/Agents'),
            app_path('Agents'),
        ],

        // Agent classes to hide from the dashboard.
        'ignore' => [],
    ],

    'playground' => [
        // Extra models to offer in the composer's model selector, on top of
        // each agent's own model and its provider's cheapest/smartest tiers.
        'models' => [
            // 'anthropic/claude-sonnet-5',
            // 'openai/gpt-5',
        ],
    ],

    'storage' => [
        // Database connection for Synapse's tables. null = your app default.
        // Point this at a dedicated connection to isolate Synapse's data.
        'connection' => env('SYNAPSE_DB_CONNECTION', null),

        // Filesystem disk for chat attachment uploads.
        'attachments_disk' => env('SYNAPSE_ATTACHMENTS_DISK', 'local'),
    ],

    'retention' => [
        // When true, Synapse registers a daily scheduled prune automatically.
        'auto_prune' => env('SYNAPSE_AUTO_PRUNE', false),

        // Conversations older than this many days are pruned.
        'days' => env('SYNAPSE_PRUNE_DAYS', 7),
    ],

];
```

**Environment variables:**

| Variable | Default | Purpose |
|----------|---------|---------|
| `SYNAPSE_ENABLED` | `true` | Master switch; required to be `true` in production |
| `SYNAPSE_DB_CONNECTION` | *(app default)* | Database connection for Synapse's tables |
| `SYNAPSE_ATTACHMENTS_DISK` | `local` | Filesystem disk for uploaded attachments |
| `SYNAPSE_AUTO_PRUNE` | `false` | Enable automatic daily pruning |
| `SYNAPSE_PRUNE_DAYS` | `7` | Age threshold for pruning |

---

## Access control & environments

Synapse invokes your **real agents** — spending API credits and running tools that may write to your database, call external services, or trigger any side effect your tools implement. So access is guarded accordingly.

- **Local:** open, no authentication. Zero-config dev experience.
- **Any other environment:** every route (dashboard and API) is protected by a `viewSynapse` authorization gate. `synapse:install` publishes this gate into your app so you own it:

  ```php
  // app/Providers/SynapseServiceProvider.php
  Gate::define('viewSynapse', function ($user) {
      return in_array($user->email, [
          'you@example.com',
      ]);
  });
  ```

- **Production:** Synapse does **not** register at all unless `SYNAPSE_ENABLED=true` is explicitly set — and even then, access still requires passing the `viewSynapse` gate. A forgotten `composer require` on a production box can never become an open agent-invocation endpoint.

Synapse is a development tool. Running it in production is possible but deliberately locked down.

---

## Where your data lives

Synapse stores its conversations in **your application's database**, in three tables (`synapse_conversations`, `synapse_messages`, `synapse_tool_invocations`). It never touches the SDK's own `agent_conversations` tables — Synapse's records are your playground history, not a mirror of production traffic.

**Supported databases:** SQLite, MySQL, MariaDB, PostgreSQL — anything Laravel supports.

**Isolating Synapse's data:** if you'd rather keep Synapse's tables out of your main database, point it at a dedicated connection. For example, a self-contained SQLite file:

```php
// config/database.php
'connections' => [
    'synapse' => [
        'driver' => 'sqlite',
        'database' => storage_path('synapse.sqlite'),
        'foreign_key_constraints' => false,
    ],
],
```

```env
SYNAPSE_DB_CONNECTION=synapse
```

Synapse creates its tables on whichever connection you choose.

**Attachments** you upload in the playground are stored on the configured filesystem disk (`SYNAPSE_ATTACHMENTS_DISK`, default `local`) under a `synapse/` prefix — not inline in the database.

**Shared history:** Synapse's history is shared per application database — it is not scoped per user. In a shared or staging environment, everyone who can access the dashboard sees the same conversations. This keeps Synapse simple and matches its role as a development tool.

---

## Data retention

Playground history grows as you use it. Synapse gives you two commands and an optional automatic mode.

- **`synapse:prune`** — removes conversations older than a threshold:

  ```bash
  php artisan synapse:prune --days=7
  ```

  Run it manually, or add it to your own schedule if you want full control:

  ```php
  // routes/console.php or your scheduler
  Schedule::command('synapse:prune --days=7')->daily();
  ```

- **`synapse:clear`** — wipes **all** Synapse history and its stored attachments in one go.

- **Automatic pruning** — if you'd rather not manage a schedule, enable it in config:

  ```env
  SYNAPSE_AUTO_PRUNE=true
  SYNAPSE_PRUNE_DAYS=7
  ```

  When enabled, Synapse registers a daily prune for you — no scheduler wiring required. When disabled (the default), nothing is pruned automatically and your history is kept until you prune or clear it yourself.

Pruning removes the conversations, their messages, their tool records, and their uploaded attachment files together.

---

## Artisan commands

| Command | What it does |
|---------|--------------|
| `synapse:install` | Publishes config, runs migrations, and publishes the `SynapseServiceProvider` (with the `viewSynapse` gate) |
| `synapse:prune` | Deletes conversations older than `--days` (defaults to the configured retention window), plus their attachments |
| `synapse:clear` | Deletes **all** Synapse conversations and attachments |

---

## Theming

Synapse ships light and dark themes. A **theme switcher** sits in the sidebar's Workspace menu, beside Discovery and History — choose **Light**, **Dark**, or **System**. Your choice is remembered across visits; **System** follows your operating system's appearance setting live.

---

## Compatibility

| Package | Support | How |
|---------|---------|-----|
| **Laravel AI SDK** (`laravel/ai`) | First-class | Discovers your agents, chats with them, and inspects tool calls, tokens, and reasoning |
| **Any SDK-compatible framework** | Automatic | Any framework whose agents implement the SDK's `Agent` contract works with no adapter — if it dispatches the SDK's events, Synapse records them |

Synapse doesn't require you to change a single line of your agent code. It reads what's already there.

---

## What Synapse does not do

Synapse is focused on the **build/test/debug loop for text agents**. The following are intentionally out of scope for now:

- **Not a production logger.** Synapse only records the conversations *you* have in its playground. It does not capture or display your app's real production agent traffic.
- **Synchronous only.** The playground always invokes agents directly and streams to your browser. Queued and broadcast invocation aren't used.
- **Class-based agents only.** Anonymous/ad-hoc agents created inline in code aren't discovered — Synapse lists the agent classes in your project.
- **Text agents.** The SDK's non-conversational capabilities — embeddings, reranking, image generation, text-to-speech, transcription, and vector stores — aren't part of the dashboard.
- **Local attachments only.** You can upload images, documents, and audio from your machine. Referencing provider-hosted files or remote URLs as attachments isn't supported in the playground.
- **No auto-generated titles.** Conversation titles come from your first message (or a name you set). Synapse never makes an extra model call just to title a chat.

These may be revisited once the core loop is solid.

---

## Planned

Not in this release, and named here so you know they're missing rather than
hidden.

**Citations.** When an agent uses provider-native web search, the SDK reports
which sources backed which parts of the answer — `laravel/ai` models this as a
`Citation` event carrying a URL, a title, and the character range of the answer
that source supports. That range is the valuable part: it separates an agent that
searched and *used* what it found from one that searched, ignored the results,
and wrote something plausible. Both read identically in a transcript.

Synapse doesn't surface them yet. Two things make it a real piece of work rather
than a patch:

- Of the SDK's gateways, only **Anthropic** and **OpenRouter** emit citations while streaming. OpenAI, Gemini and xAI parse them only on the non-streaming path, so a citations panel would sit empty for a lot of setups today.
- The SDK's Vercel serialization keeps only the bare URL and drops the title and the character range, so getting the useful part means carrying the raw event — the same kind of workaround Synapse already applies to two other events the serializer flattens.

---

## Troubleshooting & FAQ

**My agent doesn't appear on the dashboard.**
Check that it (1) implements `Laravel\Ai\Contracts\Agent`, (2) lives in one of the `discovery.paths` directories (default `app/Ai/Agents/` and `app/Agents/`), (3) isn't listed in `discovery.ignore`, and (4) can be constructed by Laravel's container (an agent with unresolvable constructor dependencies can't be instantiated for discovery). Refresh — discovery runs on every request.

**Do I need to configure providers or API keys in Synapse?**
No. Synapse uses your existing `config/ai.php`. If your agents run from the terminal or in your app, they'll run in Synapse.

**Why doesn't my agent remember previous messages in the playground?**
Because your agent is stateless — it doesn't implement the SDK's `Conversational` contract, so it has no conversation memory. Synapse deliberately mirrors that: each message is sent independently, exactly as in production, and the agent is marked **Stateless**. To get multi-turn behavior, make your agent conversational (e.g. use the `RemembersConversations` trait). Synapse won't fake memory your agent doesn't have. See [Chat playground](#chat-playground).

**The answer appears all at once instead of streaming.**
Something between PHP and your browser is buffering the response. Synapse sends the correct headers (`Cache-Control: no-transform`, `X-Accel-Buffering: no`), but a proxy in front of your app may need `proxy_buffering off;` for the Synapse path, and PHP's `output_buffering` should be off or small. Everything still works — the answer, tokens, and tool cards are all correct — you just lose the token-by-token effect. Structured-output agents never stream by design (see [Chat playground](#chat-playground)).

**What happens if I close the tab while an agent is still answering?**
The run finishes on the server and the answer is saved. Reopen the conversation from History and the full turn is there, with its token counts and tool cards. Synapse deliberately doesn't abandon a request that's already been sent to your provider — you've paid for those tokens either way, and a half-recorded turn is worse than a complete one.

**Can I use a different model than my agent is configured with?**
Yes — use the model selector in the composer. It's per-send and never changes your code. Add extra models to the dropdown via `playground.models` in config.

**Can I run Synapse in staging or production?**
Yes, but it's locked down: define the `viewSynapse` gate, and in production also set `SYNAPSE_ENABLED=true`. See [Access control](#access-control--environments). Remember that everyone with access shares the same history.

**Will Synapse's data grow forever?**
Only until you prune or clear it. Use `synapse:prune`, `synapse:clear`, or enable automatic pruning — see [Data retention](#data-retention).

**Does Synapse touch my app's real conversation data?**
No. It uses its own tables and never reads or writes the SDK's `agent_conversations` tables.

**How do I keep Synapse's data separate from my app database?**
Point `SYNAPSE_DB_CONNECTION` at a dedicated connection — see [Where your data lives](#where-your-data-lives).

**Do I need to build or publish frontend assets?**
No, and there is nothing to re-publish after an upgrade. Synapse ships compiled assets inside the package and serves them directly, so `composer update` is all you ever need — the dashboard can never be left running stale assets.
