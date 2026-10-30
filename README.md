# Synapse

*See every connection your AI agents make.*

Synapse is a development dashboard for AI agents built with the [Laravel AI SDK](https://github.com/laravel/ai) (`laravel/ai`) — Telescope and Horizon, but for agents. It finds the agents in your app, lets you chat with them in the browser, and shows exactly what happened underneath: every tool call with its arguments and results, every token, every reasoning step, every error with its stack trace.

Building an agent is a tight loop — change the prompt, run it, check the tool calls, repeat. Synapse is that loop in a browser instead of `tinker` and `dd()`.

![The Synapse playground: a conversation with a tool call expanded to show its arguments and result](docs/screenshots/playground.png)

> **Upgrading:** v0.3.1 fixes Octane recording and needs no new migrations when upgrading from 0.3.0. Upgrades from 0.2.0 preserve history and require two additive migrations. The older 0.1.x → 0.2.0 storage migration requires a reset. Follow the version-specific [upgrade guide](UPGRADE.md).

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
- **Completion explanations** — see when output was limited, content-filtered, or explicitly cancelled, with the same explanation preserved in history.
- **Step inspector** — expand Steps beneath a response to inspect each model round, its output, usage, finish reason, tool results, and available model-call timing.
- **Tool approvals** — approve or deny SDK approval requests inline, including after a refresh. Supports custom/SDK tools, SDK MCP wrappers, and approval before sub-agent delegation.
- **Tool inspection** — each call inline as a card: arguments, results, duration, and failures marked as failures. Provider-native tools are distinguished from your own.
- **Info panel** — the agent's resolved config, system prompt, tool schemas and middleware, exactly as the SDK would use them.
- **History** — search, filter, replay, rename and delete past conversations.
- **Errors** — anything that throws becomes an inline card with the exception class, message and stack trace. Never a dead stream or a raw 500.

## Tool approvals

Use the Laravel AI SDK's `Approvable` contract and `InteractsWithApprovals` trait on custom tools, or call `requireApproval('Reason shown to the user')` on an SDK tool that supports it. Synapse follows the SDK's decision for each actual call, including argument-dependent approval rules.

The playground displays the arguments and reason before execution. Approve or deny each pending call; batches continue once every call has a decision. Denial lets the model continue with an explanation that the action was declined. Reloading preserves pending requests. Native provider-hosted tools and approvals inside child agents are not supported by this integration.

## Upgrading to 0.3.1

From 0.3.0, run `composer require --dev 'redberry/synapse:^0.3.1'` and reload any Octane workers as described under [Runtimes](#runtimes). No new migrations, configuration changes, or data reset are needed. See the [0.3.1 release notes](docs/releases/v0.3.1.md) for the fixes.

From 0.2.0, update the Composer constraint and publish/run the two additive migrations for approvals and step observations before opening the dashboard:

```bash
composer require --dev 'redberry/synapse:^0.3.1' --with-all-dependencies
php artisan vendor:publish --tag=synapse-migrations
php artisan migrate
```

Existing history and attachments are retained; no reset is needed. Older paused turns cannot be resumed, and historical steps have no model-call timing where it was never recorded. See [UPGRADE.md](UPGRADE.md) for verification and the separate legacy 0.1.x migration, and [release notes](docs/releases/v0.3.0.md) for all new features.

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

The following configurations were tested over real HTTP on 2026-10-30, including tool execution, saved history, partial failures, and approval/denial after reload.

| Runtime | Streaming | Validated Synapse version |
|---|---|---|
| `php artisan serve` | Verified incremental delivery | 0.3.0, 0.3.1 |
| nginx + PHP-FPM | Verified incremental delivery with FastCGI buffering enabled | 0.3.0, 0.3.1 |
| Laravel Sail, default `artisan serve` server | Verified incremental delivery | 0.3.0, 0.3.1 |
| FrankenPHP, classic mode | Verified incremental delivery | 0.3.0, 0.3.1 |
| Octane + FrankenPHP | Verified incremental delivery | 0.3.1 |
| Octane + Swoole | Verified incremental delivery | 0.3.1 |
| Octane + RoadRunner | Buffered delivery only; live streaming is unsupported | 0.3.1 |

**Use Synapse 0.3.1 or later with Octane.** Version 0.3.0 can fail to save tool executions in Octane workers. RoadRunner still delivers the entire turn at completion; the playground shows a buffering notice.

**No Synapse-specific Octane configuration is needed.** Once Octane and its chosen server are installed following [Laravel's Octane setup](https://laravel.com/docs/octane), install Synapse normally with `php artisan synapse:install`. There are no extra listeners, service-provider bindings, or `octane.warm` / `octane.flush` entries to add. Start the installed engine normally, for example:

```bash
php artisan octane:start --server=frankenphp
# Or: php artisan octane:start --server=swoole
```

Reload workers after upgrading Synapse or changing agent code: `php artisan octane:reload --server=frankenphp` (substitute `swoole` or `roadrunner` for your running engine). Octane's normal worker reload/watch behavior applies. Existing Synapse environment and access controls remain in effect; Octane does not enable production access automatically.

Qualification applies to the configurations and versions in the [runtime test guide](tests/Runtime/README.md). Herd, Valet, and Forge are not separate server engines; their individual proxy/compression configurations were not tested. Synapse sends `X-Accel-Buffering: no`; preserve this header and avoid additional response buffering when configuring a proxy.

## Conversation behavior and data

Conversational agents receive the previous Synapse turns. Stateless agents receive only the current message, while the dashboard still displays their turns together. Structured-output agents use a single-shot request and render the result as JSON; they do not receive previous turns. Approval continuation resumes the paused turn.

Synapse records only playground invocations in `synapse_conversations`, `synapse_messages`, and `synapse_tool_invocations`. It never reads or writes the SDK's conversation tables. Everyone authorized to use a dashboard shares its history. To isolate storage, define a Laravel database connection and select it with `SYNAPSE_DB_CONNECTION` before installation.

Uploaded images, audio, and documents are stored on `storage.attachments_disk` and served through authorized routes. Provider/model support determines which inputs work. Video, remote attachment URLs, and provider-hosted file references are not supported by the playground.

History deletion, `synapse:prune`, and `synapse:clear` remove the associated attachments too. Automatic pruning is off by default; enabling `SYNAPSE_AUTO_PRUNE` schedules a daily prune and requires Laravel's scheduler to run. Deleting history is blocked while a tool approval is running.

## Troubleshooting

- **Agent missing:** check that its class implements the SDK's `Agent` contract, lives in a configured `discovery.paths` directory, and is not in `discovery.ignore`. The default paths are `app/Ai/Agents` and `app/Agents`. Constructor failures appear as unavailable-agent diagnostics.
- **Earlier messages ignored:** check whether the agent implements `Conversational`. Stateless and structured-output agents intentionally start each new prompt without earlier turns.
- **Dashboard denied outside local:** register the published application provider and configure its `viewSynapse` gate. Production also requires explicitly enabling Synapse while the package is installed.
- **New columns missing after an update:** publish migrations with `php artisan vendor:publish --tag=synapse-migrations`, then run `php artisan migrate`. Follow [UPGRADE.md](UPGRADE.md) for your version; frontend assets never need publishing.
- **Approval stays unresolved after interruption:** reload to inspect its stored state. Synapse does not automatically retry a claimed tool execution, because it may already have produced side effects.

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
