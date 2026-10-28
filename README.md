# Synapse

*See every connection your AI agents make.*

Synapse is a development dashboard for AI agents built with the [Laravel AI SDK](https://github.com/laravel/ai) (`laravel/ai`) — Telescope and Horizon, but for agents. It finds the agents in your app, lets you chat with them in the browser, and shows exactly what happened underneath: every tool call with its arguments and results, every token, every reasoning step, every error with its stack trace.

Building an agent is a tight loop — change the prompt, run it, check the tool calls, repeat. Synapse is that loop in a browser instead of `tinker` and `dd()`.

![The Synapse playground: a conversation with a tool call expanded to show its arguments and result](docs/screenshots/playground.png)

> **Migration qualification:** steps 1–8 of the [Laravel AI 1.3 plan](plans/laravel-ai-1.3-migration/PLAN.md) are implemented and deterministic checks pass. Live OpenAI flows have also been exercised; this branch is not tagged or released. See the [qualification report](plans/laravel-ai-1.3-migration/QUALIFICATION.md) and follow [UPGRADE.md](UPGRADE.md) for the required history reset before upgrading.

## Requirements

| | |
|---|---|
| PHP | 8.3+ |
| Laravel | 12.62+ or 13.15+ |
| `laravel/ai` | ^1.3 |
| `laravel/mcp` (optional) | 1.1.0+ when installed |

---

This package is built and maintained by [Redberry](https://redberry.international/), one of the few Official Premier Laravel Partner agencies worldwide. With 250+ Laravel projects shipped across 20+ countries, a 200-person team, and over a decade in the Laravel ecosystem, Redberry has helped startups, SMEs, and publicly traded enterprises in regulated industries build SaaS platforms, custom web applications, APIs, and more. [Learn about our AI development services](https://redberry.international/ai-agent-development/).

## Installation

```bash
composer require redberry/synapse --dev
```

```bash
php artisan synapse:install
```

That publishes `config/synapse.php`, runs the migrations, and publishes a `SynapseServiceProvider` into your app — which is where the access gate lives. The installer conditionally registers that provider from `AppServiceProvider` only in `local` and while the Synapse package is installed. Then open `/synapse`.

For production, install dependencies without development packages as usual:

```bash
composer install --no-dev
```

No provider cleanup is required. Synapse is omitted, and the generated `class_exists` guard lets the application boot without it. If an older Synapse install added `App\Providers\SynapseServiceProvider` to `bootstrap/providers.php`, re-run `php artisan synapse:install` locally once before deploying; the installer removes that stale entry without overwriting your gate.

The published migrations and provider are self-contained, so production deployments may safely run `composer install --no-dev` followed by `php artisan migrate --force` after Composer removes Synapse.

Upgrading from a release that published migrations extending `SynapseMigration` requires refreshing those files while Synapse is still installed:

```bash
php artisan vendor:publish --tag=synapse-migrations --force
```

Also update `app/Providers/SynapseServiceProvider.php` to extend `Illuminate\Support\ServiceProvider` and guard its `Redberry\Synapse\Synapse::auth(...)` call with `class_exists`, following the current published stub. Preserve your existing `viewSynapse` gate while doing so. Alternatively, remove that provider from `bootstrap/providers.php` before deploying without development dependencies.

You never run `npm`. Compiled assets ship inside the package and are inlined into the dashboard, so there is no publish step and a `composer update` can never leave you on stale assets.

Synapse reads your existing `config/ai.php`. You don't configure providers or API keys in Synapse; if your agents run in your app, they run here and vice versa.

## ⚠️ Read this before deploying anything

**Synapse exposes an endpoint that invokes your real agents.** It spends real API credits and executes your real tools, which may write to your database, call webhooks, or do anything else your tool code does.

Two independent protections, both on by default:

1. **It does not register at all in production** unless you explicitly set `SYNAPSE_ENABLED=true`.
2. **Dashboard access outside `local` requires authorization.** Routes are registered whenever `synapse.enabled` is true. The installer registers the published application provider, which defines the `viewSynapse` gate, only in `local`. To use Synapse in another environment, explicitly register that provider while the package is installed and configure its gate:

   ```php
   // app/Providers/SynapseServiceProvider.php
   Gate::define('viewSynapse', function ($user) {
       return in_array($user->email, [
           'you@example.com',
       ]);
   });
   ```

In `local`, the dashboard is open and needs no authentication — that's the point of a dev tool.

## What you get

- **Discovery** — every agent class in your app, with its provider, model and tools. Write a class, refresh, it's there.
- **Chat playground** — streaming replies, attachments, a model selector for trying another model without touching your code, and full conversation persistence.
- **Cited sources** — inspect SDK-reported source links as answers stream, with the same sources preserved in history, approval continuations, and partial failures.
- **Tool approvals** — approve or deny SDK approval requests inline, including after a refresh. Supports custom/SDK tools, SDK MCP wrappers, and approval before sub-agent delegation.
- **Tool inspection** — each call inline as a card: arguments, results, duration, and failures marked as failures. Provider-native tools are distinguished from your own.
- **Info panel** — the agent's resolved config, system prompt, tool schemas and middleware, exactly as the SDK would use them.
- **History** — search, filter, replay, rename and delete past conversations.
- **Errors** — anything that throws becomes an inline card with the exception class, message and stack trace. Never a dead stream or a raw 500.

## Tool approvals

Use the Laravel AI SDK's `Approvable` contract and `InteractsWithApprovals` trait on custom tools, or call `requireApproval('Reason shown to the user')` on an SDK tool that supports it. Synapse follows the SDK's decision for each actual call, including argument-dependent approval rules.

The playground displays the arguments and reason before execution. Approve or deny each pending call; batches continue once every call has a decision. Denial lets the model continue with an explanation that the action was declined. Reloading preserves pending requests. Native provider-hosted tools and approvals inside child agents are not supported by this integration.

When upgrading from Synapse 0.2, publish and run the additive migration before opening the dashboard:

```bash
php artisan vendor:publish --tag=synapse-migrations
php artisan migrate
```

Existing history is retained. Older paused turns lack the resume checkpoint; start a new turn for those. No new approval request runs automatically, and no SDK conversation tables are used.

## Configuration

Everything is optional; the defaults work.

| Key | Env | Default | What it does |
|---|---|---|---|
| `enabled` | `SYNAPSE_ENABLED` | `true` except in production | Master switch. Routes don't register when false. |
| `ui.path` | `SYNAPSE_PATH` | `synapse` | Where the dashboard is mounted. |
| `ui.middleware` | — | `['web']` | Middleware on every Synapse route. The gate is added on top. |
| `discovery.paths` | — | `app/Ai/Agents`, `app/Agents` | Where to look for agent classes. **An agent outside these is invisible — the usual reason a Discovery page is empty.** |
| `discovery.ignore` | — | `[]` | Agent classes to hide. |
| `playground.models` | — | `[]` | Extra models in the composer's selector, on top of the agent's own and its provider's cheapest/smartest. |
| `storage.connection` | `SYNAPSE_DB_CONNECTION` | app default | Database connection for Synapse's tables. |
| `storage.attachments_disk` | `SYNAPSE_ATTACHMENTS_DISK` | `local` | Disk for files uploaded in the playground. |
| `retention.auto_prune` | `SYNAPSE_AUTO_PRUNE` | `false` | Schedule a daily prune. |
| `retention.days` | `SYNAPSE_PRUNE_DAYS` | `7` | How long conversations live when auto-pruning. |

## Commands

| Command | What it does |
|---|---|
| `synapse:install` | Publishes self-contained config, migrations and the gate provider, registers the provider locally from `AppServiceProvider`, then migrates. Safe to re-run — it never overwrites your edits. |
| `synapse:prune` | Deletes conversations older than `--days`, with their attachments. |
| `synapse:clear` | Deletes **all** Synapse conversations and attachments. |

`php artisan about` shows Synapse's version, whether it's enabled, its path, how many agents it found, and its retention setting.

## Runtimes

The playground streams replies as the model produces them, which needs PHP to be serving HTTP directly.

| Runtime | Streaming |
|---|---|
| `php artisan serve` | ✅ Verified |
| nginx + PHP-FPM (Herd, Valet, Forge) | ✅ Verified |
| Laravel Sail | ✅ Expected — same stack |
| FrankenPHP | ✅ Verified |
| **Laravel Octane** | ❌ Not supported |

Octane's workers run under PHP's CLI interface, where output cannot be pushed to a client mid-request, so replies arrive complete instead of streaming. Nothing breaks — Synapse detects it and says so in the playground rather than leaving you looking at a blank thread. It isn't tested per release yet, so it isn't claimed as supported.


> _**[GOAL.md](GOAL.md)** is the full manual — every feature, every config key, access control, where your data lives, and a troubleshooting section._

## Development

```bash
composer install && npm install
```

```bash
npm run build      # compile assets into dist/ (committed; inlined at runtime)
```

```bash
composer check     # lint + static analysis + tests
```

```bash
composer test:e2e  # browser end-to-end tests (needs Playwright; not run in CI)
```

Run `composer hooks` once to enable the pre-commit hook. See **[DEV.md](DEV.md)** for the development cycle and **[AGENTS.md](AGENTS.md)** for architecture and coding standards.

## License

MIT — see [LICENSE.md](LICENSE.md).
