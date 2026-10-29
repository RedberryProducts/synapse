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

> **Migration qualification:** steps 1–8 of the [Laravel AI 1.3 plan](plans/laravel-ai-1.3-migration/PLAN.md) are implemented and deterministic checks pass. Live OpenAI flows have also been exercised; this branch is not tagged or released. See the [qualification report](plans/laravel-ai-1.3-migration/QUALIFICATION.md) and follow [UPGRADE.md](UPGRADE.md) for the required history reset before upgrading.

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 8.3+ |
| Laravel | 12.62+ or 13.15+ |
| `laravel/ai` | ^1.3 |
| `laravel/mcp` (optional) | 1.1.0+ when installed |

Synapse installs alongside your existing `laravel/ai` setup. It reads the agents and providers you already have — you don't configure providers or API keys in Synapse; it uses your app's `config/ai.php`.

---

## Installation

```bash
composer require redberry/synapse --dev
php artisan synapse:install
```

`synapse:install` does everything needed:

- Publishes `config/synapse.php`
- Runs Synapse's migrations
- Publishes a `SynapseServiceProvider` into your app (where the access gate lives — see [Access control](#access-control--environments))
- Adds an idempotent registration to `AppServiceProvider` that loads the published provider only in `local` and only while the Synapse package exists

The published migrations and provider do not require Synapse classes to load.
Production can therefore remove the development dependency and still run all
application migrations:

```bash
composer install --no-dev
php artisan migrate --force
```

You never run `npm` — Synapse ships pre-built assets.

**There is no asset publishing step, by design.** The compiled dashboard lives
inside the package and is inlined into the page at request time. Nothing is
copied into your `public/` directory, so a `composer update` can never leave you
running last month's JavaScript against this month's API — the usual way a
dashboard package breaks after an upgrade. There is nothing to re-publish and no
cache to bust.

**For production deployments:**

```bash
composer install --no-dev
```

No manual provider removal is needed. The local registration checks that the
Synapse package class exists, so Laravel still boots after Composer removes the
development dependency. Existing installations that contain
`App\Providers\SynapseServiceProvider` in `bootstrap/providers.php` should run
`php artisan synapse:install` once locally before deployment; the command moves
registration to `AppServiceProvider` without overwriting the customized gate. If no legacy entry exists, the bootstrap file is left unchanged, preserving provider order and comments. If the installer finds an existing registration it cannot verify as guarded, it asks you to remove that registration and any provider references outside the generated block (including alias imports), then rerun the command; it leaves your application provider and bootstrap entry unchanged.

**After a `composer update`:**

```bash
php artisan migrate
```

That's it — new releases may add tables, and nothing else needs doing. Re-running
`php artisan synapse:install` is also safe: it never overwrites a
`config/synapse.php` you have edited or a `SynapseServiceProvider` you have
customised, so your access gate survives. Application bindings added before or after the generated registration block are preserved on repeat installs.

**Upgrading an installation created by an older Synapse release:** refresh the
old package-dependent migrations before removing development dependencies:

```bash
php artisan vendor:publish --tag=synapse-migrations --force
```

Update `app/Providers/SynapseServiceProvider.php` to match the current
self-contained stub while preserving your gate, or remove its entry from
`bootstrap/providers.php` before `composer install --no-dev`. Re-running
`synapse:install` does not overwrite that customized provider automatically.

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

4. **Send a message.** The response streams back in real time. Synapse immediately shows a pending assistant `Loading...` indicator so the thread never looks stalled before the first stream event arrives. If the agent calls tools, you'll see each call appear inline as a card with its arguments and results. Token counts and timing show on every response.

5. **Iterate.** Change your agent's prompt, tools, or config in code, refresh, and test again. Synapse always reflects your current code — there's no cache to bust.

That's the whole loop Synapse is built for: **discover → chat → inspect → repeat.**

---

## The interface

Every page sits inside a persistent, collapsible sidebar:

- **Recent Conversations** — your latest chats across all agents, each with its agent name, a short title, a call count, and an error indicator if something failed. Each has a menu to open, rename, or delete it.
- **Agents** — a quick list of discovered agents for jumping straight into a playground. Long names stay on one line, end with an ellipsis when space runs out, and expose the full name on hover.
- **Workspace** — navigation between **Discovery** (the agents dashboard) and **History** (all past conversations).
- **Footer** — the Synapse version and how many agents were discovered (e.g. `v1.0.0 · 8 agents`).

Collapse the sidebar to the logo when you want more room for a conversation.

---

## Features

### Agent discovery

The Discovery page is your landing page. It scans your project on every request and lists every agent it finds — so a newly created agent appears the moment you refresh.

Each **agent card** shows:

- **Name** — the class short name (e.g. `SupportAgent`)
- Long names stay within the card on one line, show an ellipsis when truncated, and expose the full class name on hover.
- **Provider / model** — e.g. `anthropic / claude-sonnet-5`
- **Tools** — a chip per tool; if there are many, they collapse into a `+N` chip you can hover to see the full list

Click a card to open its playground, or click **Info** to open the [agent info panel](#agent-info-panel).

Agents that cannot be constructed show an actionable diagnostic in Discovery and in their playground, including when opened from the sidebar. Their composer is hidden until the error is fixed; saved conversations remain readable.

**How discovery works:** Synapse looks in the directories you configure — by default `app/Ai/Agents/` (where `make:agent` generates agents) and `app/Agents/` — for classes implementing `Laravel\Ai\Contracts\Agent`. There's no manual registration — creating the class is enough. Because discovery runs fresh each request, it always matches your current code (ideal during active development).

### Chat playground

The playground is a real conversation with a real agent. Messages stream token-by-token as the model generates them.

If an answer fails mid-stream, its visible partial text stays in the conversation context for the next message, including when a tool already completed earlier in that turn.

When the SDK retries with another provider, successful tools from the earlier attempt keep their results and timing. The retry appears as a notice, without falsely marking those tools as failed.

**Composing messages:**

- **Text** — type and send.
- **Attachments** — attach images, documents, or audio with the file picker or by dragging files onto the composer. Attachments show as chips before you send, and as thumbnails (images) or chips on your message afterward. They stay attached for the rest of the conversation, so a follow-up question about the same file still works. There is no file-type allowlist: anything your provider rejects comes back as an error card explaining why, which is exactly what production would do. (Files go to your app's storage — see [Where your data lives](#where-your-data-lives).)
- **Model selector** — a dropdown in the composer lets you run the next message on a different model without touching your agent's code. It always offers the agent's own configured model (the default) plus its provider's **cheapest** and **smartest** tiers, and any extra models you list in config (see [Configuration](#configuration)). Whatever model actually ran is recorded per message, so replaying a conversation always shows the truth. The choice lasts while you're on the page and resets to the agent's own model when you come back — an override is an experiment, and you should never return later and mistake one for how the agent is actually configured.

**In the response, you'll see:**

- **Streaming text** — the answer as it arrives.
- **Pending response state** — right after you send, a `Loading...` row appears where the reply will land and stays there until the first reasoning step, tool event, notice, answer text, or error arrives. Reasoning already received remains visible when later tools, notices, or errors arrive.
- **Reasoning** — for models with extended thinking (Anthropic, OpenAI o-series, DeepSeek), `✦ Thinking…` appears while the model works, then collapses into a "Thinking" pane above the answer, with its own reasoning-token count in the per-message counts. Collapsed by default so it never gets in the way — and kept, so reopening the conversation later shows the same thinking you watched.
- **Sources** — when the SDK reports URL citations, a Sources list appears below the assistant's answer, with clickable titles and hostname fallbacks. It updates as citations arrive, combines duplicate URLs once per turn, and survives refreshes, approval continuations, and partial failures. Sources reflect provider-reported citations, not proof that an answer is correct; ordinary answer links and tool results do not become citations. Empty lists stay hidden. SDK 1.3 reports citations for supported responses from OpenAI, Anthropic, Gemini, OpenRouter, and xAI, sometimes only near the end of generation. Inline citation highlighting is not included. Existing history shows sources only where citation data was actually retained.
- **Completion explanations** — a response stopped by an output token limit or content filter gets a concise notice; an explicitly reported cancellation is identified too. These outcomes retain partial output and do not themselves count as agent failures. Normal completions add no notice. Approval pauses and error cards remain authoritative. The same explanation appears after reload where finish data was retained; missing historical data is not guessed.
- **Tool calls** — inline cards, in the order they happened (see [Tool inspection](#tool-inspection)). Reloading preserves the same text, reasoning, and tool sequence.
- **Approval requests** — when the SDK asks for permission, Synapse pauses and shows the tool arguments, approval reason, and Approve/Deny controls. A single decision continues immediately; multiple requests require a decision for each and a Continue action. Denied tools never run. Refreshing keeps pending decisions available, and continuation updates the same answer and tool cards. Resolve the requests or start a new conversation; sending and model selection stay disabled during the pause. Repeated clicks cannot start the same continuation again.
- **Approval scope** — custom and SDK tools using `Approvable`, SDK MCP wrappers, and approval before sub-agent delegation are supported. Provider-hosted tools and approvals originating inside a sub-agent are excluded. Stateless and structured agents only receive their current paused turn when resuming. Old paused turns saved before approval support remain readable but cannot be resumed.
- **Interrupted approvals** — if the server stops while an approved tool is running, Synapse does not automatically repeat a potentially completed action. Reload to check the saved outcome; a still-running claim requires checking the host process and tool outcome before manual recovery.
- **Structured output** — if your agent returns structured data, the response renders as a formatted, collapsible JSON card instead of plain text. Synapse keeps structured-output agents on its single-shot path and shows the finished answer — the only visible difference is that the text arrives all at once.
- **Metadata** — inclusive input/output token counts and response time on each answer, plus the model that ran it whenever you overrode the agent's own.

**Conversation controls:**

The **⋮ menu** in the playground header holds:

- **New conversation** — start a fresh thread with the same agent.
- **Clear conversation** — delete the current thread and return to an empty playground.

**Switch agent** — pick another agent (from the sidebar or Discovery); a new conversation starts automatically.

**Opening an agent returns you to where you left off** — the conversation you were reading, or a blank page if that's where you deliberately were after **New conversation**. The conversation's id lives in the address bar (`?c=…`), so refreshing keeps you in the same thread and the link is shareable. This is remembered per browser, not stored with your data, so nothing reappears on a machine you've never used. To jump to any other conversation, pick it from [History](#history) or the sidebar.

**Conversation memory mirrors your agent — Synapse never fakes it.** The playground always shows your messages as a thread, but whether the agent actually *remembers* earlier messages depends entirely on your agent:

- **If your agent supports conversation memory** (it implements the SDK's `Conversational` contract — most commonly via the `RemembersConversations` trait), the playground is a true multi-turn conversation. Each message is sent with the full thread, so the agent has the earlier context. Synapse supplies that history from **its own** stored messages rather than your app's conversation tables, so you don't have to call `forUser()` or `continue()` to try an agent out, and nothing you do in the playground touches your production conversation data.
- **If your agent is stateless** (it doesn't implement `Conversational`), each message is sent to the agent on its own, with **no earlier messages attached** — exactly how it behaves in production. Synapse still keeps the messages together in one session so you can read them as a thread, and marks the agent **Stateless** so you know why it won't recall previous turns.

- **One exception: structured-output agents.** An agent implementing `HasStructuredOutput` runs single-shot with no earlier messages attached, even if it is also `Conversational`. Synapse retains this existing behavior during the SDK migration; the installed SDK 1.3.0 still rejects structured streaming. Extending Synapse's structured conversation path is separate work.

The point: what you see in Synapse is what you'd get in production. Synapse won't give your agent memory it doesn't actually have — if you need multi-turn behavior, that's a signal to make your agent conversational in code.

### Tool inspection

Whenever an agent calls a tool, a card appears inline in the conversation. This is the heart of debugging agent behavior.

Stringable tool results use the same text the SDK sends to the model. Each execution keeps its output and timing on one card, both live and after reload.

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
- **Partial failures** — useful text, reasoning, and completed tool work are retained before the error. A failed call without a result is explicitly marked interrupted in subsequent model history; it is never presented to the model as a successful execution.
- **Mid-stream errors** — if a provider reports a problem partway through generating, Synapse shows it in place; recoverable hiccups are styled more softly than fatal errors.

### Token counting

Every assistant response carries its token cost, so you can spot bloated prompts and optimize as you go:

- **Per response:** `↑ 340 · ↓ 128` (input / output)
- **Per conversation:** a running total at the top of the thread

Cache-read and cache-write counts appear on hover; reported reasoning counts appear beside output. Cache counts are already included in input, and reasoning is included in output, so totals never add them twice. Unknown breakdowns stay hidden; a reported zero is shown. A multi-step answer is counted once, both live and after reload. Tool-only turns keep their reported usage visible immediately, including when a tool fails or pauses for approval; later sends retain those counts.

---

## Configuration

Everything is configured in `config/synapse.php`. Synapse has **no in-app settings screen** — configuration is code, versioned with your project.

```php
return [

    // Master switch. In production, Synapse only registers if this is
    // explicitly true (see "Access control & environments").
    'enabled' => (bool) env('SYNAPSE_ENABLED', env('APP_ENV') !== 'production'),

    'ui' => [
        // The dashboard is served from this path: /synapse
        'path' => env('SYNAPSE_PATH', 'synapse'),

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
| `SYNAPSE_ENABLED` | `true`, except `false` in production | Master switch; must be set explicitly to run in production |
| `SYNAPSE_PATH` | `synapse` | Path the dashboard is mounted at |
| `SYNAPSE_DB_CONNECTION` | *(app default)* | Database connection for Synapse's tables |
| `SYNAPSE_ATTACHMENTS_DISK` | `local` | Filesystem disk for uploaded attachments |
| `SYNAPSE_AUTO_PRUNE` | `false` | Enable automatic daily pruning |
| `SYNAPSE_PRUNE_DAYS` | `7` | Age threshold for pruning |

---

## Access control & environments

Synapse invokes your **real agents** — spending API credits and running tools that may write to your database, call external services, or trigger any side effect your tools implement. So access is guarded accordingly.

- **Local:** open, no authentication. Zero-config dev experience.
- **Any other environment:** the standard `--dev` installation does not register the published provider, so access is denied by default even when routes are enabled. To allow access, explicitly register the published provider while Synapse is installed and configure its `viewSynapse` authorization gate, which then protects every dashboard and API route:

  ```php
  // app/Providers/SynapseServiceProvider.php
  Gate::define('viewSynapse', function ($user) {
      return in_array($user->email, [
          'you@example.com',
      ]);
  });
  ```

- **Production:** the supported flow removes Synapse with `composer install --no-dev`; the guarded application registration remains safe when its package classes are absent. If Synapse is installed deliberately, it still does **not** register routes unless `SYNAPSE_ENABLED=true` is explicitly set, and access requires a configured `viewSynapse` gate.

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

### Runtimes

The playground streams a reply as the model produces it, which only works when
PHP is serving HTTP directly. Measured per runtime:

| Runtime | Streaming | Notes |
|---------|-----------|-------|
| `php artisan serve` | ✅ Verified | First byte in ~7ms of a 7s run |
| nginx + PHP-FPM (Herd, Valet, Forge) | ✅ Verified | First byte in ~2ms of a 4s run, with `output_buffering=4096` |
| Laravel Sail | ✅ Expected | nginx + PHP-FPM in Docker — the same stack as the row above |
| FrankenPHP | ✅ Verified | First byte in ~2ms of a 4s run |
| **Laravel Octane** | ❌ **Not supported** | See below |

**Octane.** Swoole and RoadRunner run their workers under PHP's CLI interface,
where `flush()` cannot push bytes to a client — so Synapse assembles the whole
reply and sends it at once. Nothing is lost and nothing breaks; you simply wait
for the full answer instead of watching it arrive. Synapse detects this and says
so in the playground rather than leaving you looking at a blank thread. Octane
isn't tested against each release yet, so it isn't claimed as supported.

If you're on a runtime not listed here, `bin/check-streaming.sh` in the package
repository measures it in one command.

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
