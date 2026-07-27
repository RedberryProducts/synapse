# Synapse — Scaffolding Plan

The starting-point scaffold for `redberry/synapse`, grounded in the Horizon / Telescope / `laravel/ai` package patterns and the [PRD](PRD.md). **This pass produces an installable package that migrates and serves a real (empty) React dashboard shell at `/synapse` — no feature logic yet.** Feature implementation (discovery, SSE chat, recorder, real screens) follows against this skeleton.

- **Package:** `redberry/synapse`
- **Root namespace:** `Redberry\Synapse\` (PSR-4 → `src/`) — matches the company convention (`Redberry\Evals`)
- **Scope of this pass:** Phases 0–3 + frontend toolchain + empty SPA + two testing environments

---

## Repository layout

```
synapse/
├── composer.json
├── package.json  vite.config.ts  tsconfig.json  components.json      # frontend toolchain
├── pint.json  phpstan.neon.dist  phpunit.xml.dist  testbench.yaml    # PHP tooling
├── .gitignore  README.md  LICENSE.md
├── config/
│   └── synapse.php                        # the full config block from the PRD
├── database/
│   └── migrations/                        # 3 synapse_* tables
├── dist/                                  # compiled assets (committed; built by Vite)
├── resources/
│   ├── views/
│   │   └── layout.blade.php               # SPA shell + window.Synapse bootstrap
│   └── js/                                # React + TS SPA (see Frontend)
├── routes/
│   └── web.php                            # api group + SPA catch-all
├── src/
│   ├── SynapseServiceProvider.php         # register/boot: routes, views, publish, commands, schedule
│   ├── SynapseApplicationServiceProvider.php  # base for the published app provider (auth + gate)
│   ├── Synapse.php                        # asset/script helpers + auth callback
│   ├── Http/
│   │   ├── Controllers/HomeController.php  # serves the SPA shell
│   │   └── Middleware/Authorize.php        # viewSynapse gate check
│   ├── Migrations/SynapseMigration.php     # connection resolver (mirrors AiMigration)
│   ├── Models/
│   │   ├── SynapseConversation.php
│   │   ├── SynapseMessage.php
│   │   └── SynapseToolInvocation.php
│   └── Console/
│       ├── InstallCommand.php
│       ├── PruneCommand.php
│       └── ClearCommand.php
├── stubs/
│   └── SynapseServiceProvider.stub        # published to app/Providers (viewSynapse gate)
├── workbench/                             # Testbench dev app for automated tests
│   ├── app/Agents/                        # sample agents (conversational, stateless, tooled)
│   └── database/
└── tests/
    ├── Pest.php  TestCase.php
    └── Feature/  Unit/
```

---

## Phase 0 — Repo & tooling

### `composer.json`

```jsonc
{
  "name": "redberry/synapse",
  "description": "A development dashboard for AI agents built with the Laravel AI SDK.",
  "license": "MIT",
  "require": {
    "php": "^8.3",
    "laravel/framework": "^12.0|^13.0",
    "laravel/ai": "^0.9"
  },
  "require-dev": {
    "orchestra/testbench": "^10.0|^11.0",
    "pestphp/pest": "^3.0|^4.0",
    "pestphp/pest-plugin-laravel": "^3.0|^4.0",
    "larastan/larastan": "^3.0",
    "laravel/pint": "^1.0"
  },
  "autoload": { "psr-4": { "Redberry\\Synapse\\": "src/" } },
  "autoload-dev": {
    "psr-4": {
      "Redberry\\Synapse\\Tests\\": "tests/",
      "Workbench\\App\\": "workbench/app/",
      "Workbench\\Database\\": "workbench/database/"
    }
  },
  "extra": { "laravel": { "providers": ["Redberry\\Synapse\\SynapseServiceProvider"] } },
  "scripts": {
    "post-autoload-dump": "@php ./vendor/bin/testbench package:discover --ansi",
    "test": "@php ./vendor/bin/pest",
    "lint": "@php ./vendor/bin/pint",
    "analyse": "@php ./vendor/bin/phpstan analyse"
  },
  "minimum-stability": "dev",
  "prefer-stable": true,
  "config": { "sort-packages": true }
}
```

Notes / decisions (flag if you disagree):
- **`laravel/framework` as the dependency** (not individual `illuminate/*`), matching Telescope/Horizon — Synapse is an app-level tool, not a framework-agnostic library.
- **`minimum-stability: dev` + `prefer-stable`** — required because `laravel/ai` is a 0.x dev package (same as the SDK's own composer.json).
- **`larastan`** for PHPStan (Laravel-aware), level 5 to start.

### PHP tooling

- **`pint.json`** — Laravel preset.
- **`phpstan.neon.dist`** — larastan, `level: 5`, paths `src`.
- **`phpunit.xml.dist`** — Pest/PHPUnit config, testsuite `tests/`, sqlite `:memory:`.
- **`testbench.yaml`** — registers `SynapseServiceProvider`, points workbench at `workbench/`, runs migrations.
- **`.gitignore`** — `/vendor`, `/node_modules`, `composer.lock`, `/testing-laravel-project` (the manual test app), `.phpunit.result.cache`, build caches. **`dist/` is NOT ignored** (committed per PRD).

### Frontend toolchain (React + Vite + Tailwind + shadcn)

Copies the reference plumbing (Vite, committed `dist/`, blade shell, `window.Synapse` bootstrap, inlined assets) but the app is React, not Vue. Kept intentionally lean — dependencies grow as features land.

- **`package.json`** (`"private": true`, `"type": "module"`), scripts:
  - `build` → `vite build`
  - `watch` → `vite build --watch`
  - Initial deps: `react`, `react-dom`, `react-router-dom`, `vite`, `@vitejs/plugin-react`, `typescript`, `tailwindcss` + `@tailwindcss/vite`, and the shadcn base (`class-variance-authority`, `clsx`, `tailwind-merge`, `lucide-react`, `tailwindcss-animate`). Vercel `ai`, `@uiw/react-json-view`, `react-markdown`, and per-component Radix packages are added when their features arrive.
- **`vite.config.ts`** — `@vitejs/plugin-react` + `@tailwindcss/vite`; `build.outDir = 'dist'`, `assetsDir = ''`, stable output filenames (`app.js` / `app.css`, read directly by `Synapse::css()/js()`), input `resources/js/app.tsx`.
- **`tsconfig.json`** — bundler resolution, `jsx: react-jsx`, path alias `@/* → resources/js/*`.
- **Tailwind v4** — CSS-first config (`@theme` in `resources/js/styles/app.css`); no `tailwind.config.js` needed. Light/dark tokens defined here (dark ships first per the designs; light tokens are the pending-feedback token swap).
- **`components.json`** — shadcn config (style `new-york`, `rsc: false`, aliases `@/components`, `@/lib/utils`).

**Decision to confirm:** Tailwind **v4** (current shadcn default, no config file — simplest to maintain). Fallback is v3 + `tailwind.config.ts` if you prefer the more heavily-documented path.

---

## Phase 1 — PHP skeleton (boots + serves the empty SPA)

### `config/synapse.php`
The exact config block from the PRD (`enabled`, `ui`, `discovery`, `playground.models`, `storage`, `retention`).

### `src/SynapseServiceProvider.php`
- **`register()`** — `mergeConfigFrom` the config; bind core singletons (e.g. `AgentDiscovery`) as stubs.
- **`boot()`**:
  - `registerRoutes()` — guarded by the [Authorization](PRD.md#authorization--safety) rule: skip entirely in `production` unless `SYNAPSE_ENABLED=true`; wrap in `Route::group` with prefix `config('synapse.ui.path')` + configured middleware + the `Authorize` middleware.
  - `loadViewsFrom(resources/views, 'synapse')`.
  - `registerPublishing()` — tags: `synapse-config`, `synapse-migrations` (`database/migrations` → app `database/migrations`, Telescope-style — **publish-only, not `loadMigrationsFrom`**), `synapse-provider` (stub → `app/Providers`). Migrations run when the app migrates after `synapse:install` publishes them.
  - `registerCommands()`.
  - `registerAutoPrune()` — when `config('synapse.retention.auto_prune')`, `Schedule::command('synapse:prune --days=…')->daily()` (via `$this->callAfterResolving(Schedule::class, …)`).
  - `registerRecorder()` — subscribe `SynapseRecorder` to SDK events (wired now, no-op body until the recording phase).

### `src/Synapse.php`
Static helpers, mirroring `Horizon::css()/js()`:
- `css()` / `js()` — read `dist/app.css` / `dist/app.js` and inline them as `<style>` / `<script type="module">` (Horizon/Telescope approach; no publishing, no manifest).
- `scriptVariables()` — `{ path, csrfToken, version }` injected as `window.Synapse`.
- `auth()` + `check()` — stores/evaluates the auth callback (local → open; else `Gate::check('viewSynapse')`), exactly like `Telescope::auth`.

### `src/SynapseApplicationServiceProvider.php` + `stubs/SynapseServiceProvider.stub`
Base provider defines the `viewSynapse` gate + `Synapse::auth(...)` callback; the published stub extends it (Telescope's `TelescopeApplicationServiceProvider` pattern). `synapse:install` drops the stub into `app/Providers` and registers it in `bootstrap/providers.php`.

### `src/Http/Middleware/Authorize.php`
Calls `Synapse::check($request)`; aborts 403 otherwise.

### `src/Http/Controllers/HomeController.php`
Single action returning `view('synapse::layout')` — the SPA catch-all target.

### `routes/web.php`
```php
Route::prefix('api')->group(function () {
    // Stubbed endpoints for the skeleton (return empty payloads) so the SPA boots:
    // GET agents, GET agents/{agent}, POST chat/{agent}/send, GET/PATCH/DELETE conversations…
});
Route::get('/{view?}', HomeController::class)->where('view', '(.*)')->name('synapse.index');
```

### `resources/views/layout.blade.php`
```blade
<!DOCTYPE html><html>
<head>
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Synapse</title>
  <script>window.Synapse = @json(\Redberry\Synapse\Synapse::scriptVariables());</script>
  {!! \Redberry\Synapse\Synapse::css() !!}
</head>
<body>
  <div id="synapse"></div>
  {!! \Redberry\Synapse\Synapse::js() !!}
</body></html>
```

### Frontend (`resources/js`)
```
resources/js/
├── app.tsx                 # mounts React into #synapse, reads window.Synapse
├── router.tsx              # React Router; basename = window.Synapse.path
├── styles/app.css          # Tailwind v4 @theme tokens (dark first)
├── lib/
│   ├── utils.ts            # cn()
│   └── api.ts              # fetch wrapper (CSRF header, base path)
├── components/
│   ├── ui/                 # shadcn components (added as needed; sidebar first)
│   └── AppShell.tsx        # persistent collapsible sidebar + <Outlet/>
└── pages/
    ├── Discovery.tsx       # empty placeholder
    ├── Playground.tsx      # empty placeholder
    └── History.tsx         # empty placeholder
```
The starting-point SPA renders the **real sidebar shell** (Recent Conversations / Agents / Discovery+History nav / version footer — no Settings) with empty placeholder pages, proving the whole toolchain end-to-end. Screens fill in later with final designs.

---

## Phase 2 — Persistence

### `src/Migrations/SynapseMigration.php`
Abstract base overriding `getConnection()` → `config('synapse.storage.connection', config('database.default'))` (mirrors the SDK's `AiMigration`).

### `database/migrations/*_create_synapse_tables.php`
The three tables exactly as specified in [PRD → Database Schema](PRD.md#database-schema): `synapse_conversations`, `synapse_messages`, `synapse_tool_invocations`. `text` columns for JSON; uuid7 string PKs; indexes as specified.

### `src/Models/*`
- Connection-aware (`getConnectionName()` from config), `string` non-incrementing uuid7 keys.
- Casts: JSON `text` columns → `array`.
- Relationships: `SynapseConversation hasMany messages, toolInvocations`; delete cascades handled in the model/repository layer (not DB FKs — sqlite-safe, per PRD).

---

## Phase 3 — Install command, workbench & first green test

### `src/Console/InstallCommand.php` (`synapse:install`)
`vendor:publish` for `synapse-config`, `synapse-migrations`, `synapse-provider`; register the app provider in `bootstrap/providers.php` (`ServiceProvider::addProviderToBootstrapFile`); run `migrate`. (Telescope's `InstallCommand` is the template.)

### `src/Console/PruneCommand.php` (`synapse:prune`)
`--days=` option (default `config('synapse.retention.days')`); deletes conversations older than the threshold on `updated_at`, cascading messages + tool rows + attachment files through the repository.

### `src/Console/ClearCommand.php` (`synapse:clear`)
Truncates all three tables + deletes stored attachment files.

### `workbench/`
All four sample agents (seeded in both workbench and playground) so every card type is exercisable:
- `SupportAgent` — **conversational** (`RemembersConversations`), one simple tool → multi-turn history + tool cards.
- `WeatherAgent` — **stateless**, one tool → stateless badge + independent request/response.
- `ResearchAgent` — declares a **provider tool** (e.g. `WebSearch`) → ⚡ provider-tool card.
- `ExtractorAgent` — **`HasStructuredOutput`** → JSON response card.

The conversational + stateless agents are the Phase 3 baselines; the provider-tool and structured-output agents are wired now and become useful as those features are built. `testbench.yaml` wires providers + workbench.

### `tests/`
- `TestCase.php` extends Orchestra `TestCase`, loads the provider.
- **First green tests:** `/synapse` returns 200 in `local`; migrations create the three tables; `synapse:install` publishes without error; config merges. (Feature tests for discovery/chat come with those phases.)

---

## Testing environments

Two complementary setups — one for automated CI, one for real-world install smoke-testing.

### 1. `workbench/` (committed) — automated tests
The standard Testbench pattern used by all three references. Pest tests boot the package against the workbench app + its sample agents. This is what `composer test` runs.

### 2. `testing-laravel-project/` (gitignored) — manual install & run
A **real Laravel app** to exercise the actual `composer require` + `synapse:install` flow end-to-end, exactly as a user would. Created once, gitignored, linked to this repo via a **Composer path repository (symlinked)** so package edits reflect immediately.

Helper: **`bin/setup-testing-app.sh`** (built) that:
1. Builds the Synapse assets (`npm install && npm run build`)
2. `composer create-project laravel/laravel testing-laravel-project` (if absent)
3. Adds two path repositories to `testing-laravel-project/composer.json` — the package (`../`) and the local SDK copy (`../references/laravel/ai`) — plus `minimum-stability: dev`
4. `composer require redberry/synapse:@dev`
5. `php artisan synapse:install`
6. Seeds the four sample agents into `testing-laravel-project/app/Agents/` (namespace rewritten `Workbench\App` → `App`)

`.gitignore` excludes `/testing-laravel-project`. Frontend dev loop: `npm run watch` (rebuilds `dist/`) and refresh the browser — assets are inlined from the symlinked package, so there is no publish step.

**SDK sourcing note:** `laravel/ai` is not yet on Packagist, so both the package's own `composer.json` and the test app pull it from the local `references/laravel/ai` copy via a path repository (version pinned to `0.9.1`). The package-level path repo is harmlessly ignored when the path is absent (external users), falling back to Packagist once the SDK is published.

---

## Developer workflow (once scaffolded)

```bash
composer install        # PHP deps + testbench discover
npm install             # frontend deps
npm run build           # compile dist/
composer test           # Pest against workbench
./bin/setup-testing-app.sh  # one-time: create the manual test app
# iterate: npm run watch  +  publish assets to the test app  +  browse /synapse
```

---

## Definition of done for this scaffold

- [x] `composer install` + `npm install` + `npm run build` succeed
- [x] `composer test` green — **8 passed (24 assertions)**: dashboard 200/403, commands registered, tables + uuid7 + casts, prune/clear + cascade
- [x] In `testing-laravel-project/`: `composer require redberry/synapse:@dev` → `php artisan synapse:install` → visiting `/synapse` renders the React sidebar shell with the empty Discovery page (screenshot-verified)
- [x] `synapse:prune` / `synapse:clear` covered by passing tests
- [x] `viewSynapse` gate stub published to `app/Providers`; provider registered in `bootstrap/providers.php`; production route guard is config-cache safe

---

## Resolved decisions

1. **PHP dependency → `laravel/framework`** (`^12.0|^13.0`), matching Telescope/Horizon. Not individual `illuminate/*`.
2. **Tailwind → v4** (CSS-first `@theme`, no `tailwind.config.js`; current shadcn default).
3. **Migrations → Telescope-style publish-only.** Migrations are published via the `synapse-migrations` tag (not `loadMigrationsFrom`); they run when the app migrates after `synapse:install` publishes them. See Phase 1 / Phase 3.
4. **Assets → inlined from `dist/`** with stable filenames, following current Horizon/Telescope (they no longer publish assets). No manifest, no publishing, no re-publish on upgrade.
5. **Sample agents → all four variants** seeded in workbench + testing-laravel-project (all `openai` / `gpt-5.6-luna`): conversational-with-tool, stateless, provider-tool, and structured-output — so every card type is exercisable. (Baselines land in Phase 3; provider-tool and structured-output agents are wired now and become useful as those features are built.)
