---
name: laravel-package
description: Best practices for developing the Synapse Laravel package (and Laravel packages in general) — service provider register/boot split, publishing config/migrations/assets, package discovery, Testbench + Pest testing, config-cache safety, and Synapse's own conventions. Use when adding or modifying package plumbing: service providers, config, routes, migrations, models, commands, publishing, or tests.
---

# Laravel Package Development (Synapse)

Distilled from the [Laravel 13 package docs](https://laravel.com/docs/13.x/packages), [laravelpackage.com](https://www.laravelpackage.com), and Synapse's own scaffolding. When these conflict with the repo's existing patterns, **follow the repo** — the conventions below already match it.

## Golden rules

1. **`register()` binds; `boot()` wires.** In `register()`: only `mergeConfigFrom` and container bindings — never resolve services, hit the DB, or touch facades that need a booted app. In `boot()`: routes, views, publishing, commands, events, gates, schedules.
2. **Config must be cache-safe.** No closures in config files (breaks `config:cache`). Never call `env()` at runtime outside a config file — bake env-derived logic into the config's default value and read `config()` everywhere else. (Synapse's production route-guard does exactly this: `'enabled' => (bool) env('SYNAPSE_ENABLED', env('APP_ENV') !== 'production')`.)
3. **`mergeConfigFrom` merges only the first level.** Don't rely on deep-merging nested config a user partially overrode.
4. **Guard console-only work.** Register commands and publish groups inside `if ($this->app->runningInConsole())`.
5. **Everything user-facing is publishable, and tagged.** Separate tags so users can publish config without assets, etc.
6. **Package discovery, not manual registration.** The provider is auto-loaded via `extra.laravel.providers` in `composer.json`.

## Service provider anatomy

```php
public function register(): void
{
    $this->mergeConfigFrom(__DIR__.'/../config/synapse.php', 'synapse');
    // container bindings only
}

public function boot(): void
{
    $this->registerRoutes();                                  // guarded by config
    $this->loadViewsFrom(__DIR__.'/../resources/views', 'synapse');
    $this->registerScheduledTasks();                          // conditional
    if ($this->app->runningInConsole()) {
        $this->registerPublishing();                          // tagged groups
        $this->registerCommands();
    }
}
```

Resource-loading methods (all called from `boot`):

| Method | Purpose |
|--------|---------|
| `mergeConfigFrom($path, $key)` | Provide config defaults (in `register`) |
| `loadViewsFrom($path, 'synapse')` | Register views as `synapse::view`; user override via `resources/views/vendor/synapse` |
| `loadRoutesFrom($path)` | Load routes; auto-skips when routes are cached |
| `loadMigrationsFrom($path)` | Auto-run migrations (Synapse does **not** use this — see below) |
| `commands([...])` | Register Artisan commands |
| `AboutCommand::add(...)` | Add a row to `php artisan about` |

## Publishing (tagged groups)

```php
$this->publishes([__DIR__.'/../config/synapse.php' => config_path('synapse.php')], 'synapse-config');
$this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'synapse-migrations');
$this->publishes([__DIR__.'/../dist' => public_path('vendor/synapse')], 'synapse-assets');
$this->publishes([__DIR__.'/../stubs/SynapseServiceProvider.stub' => app_path('Providers/SynapseServiceProvider.php')], 'synapse-provider');
```

- Users publish a group with `php artisan vendor:publish --tag=synapse-config`, or everything with `--provider="Redberry\Synapse\SynapseServiceProvider"`.
- **Assets** need `--force` on package updates (Synapse documents re-publishing in README / a `post-update-cmd`).
- **Migrations: Synapse publishes them (Telescope-style), it does not `loadMigrationsFrom`.** They run when the app migrates after `synapse:install`. (`publishesMigrations` auto-updates the timestamp; Synapse uses fixed timestamps + `publishes` so the three tables keep a stable order.)

## Migrations & models (Synapse conventions)

- **Migrations** extend `Redberry\Synapse\Migrations\SynapseMigration`, whose `getConnection()` returns `config('synapse.storage.connection') ?: config('database.default')`.
- **JSON is stored in `text` columns**, not `->json()` — sqlite has no native JSON type. Cast to `array` on the model.
- **No DB foreign keys for cascade.** sqlite's FK pragma is off by default; cascade deletes live in `ConversationRepository`, not the schema.
- **Models** extend `Redberry\Synapse\Models\SynapseModel`: `HasUuids` (this framework's `HasUuids` already yields uuid7 — the `HasVersion7Uuids` trait does not exist here), `$incrementing = false`, `$keyType = 'string'`, connection resolved from config. Use `const UPDATED_AT = null` when a table has only `created_at`.
- **Always add `@property` annotations** for magic attributes you access (larastan runs with `checkModelProperties`). Type JSON columns whose element shape isn't guaranteed as `array<int, mixed>` so runtime `is_array()` guards remain valid.

## Authorization (dashboard packages)

Telescope/Horizon pattern, used by Synapse:

- Open in `local`; gated by a `viewSynapse` Gate everywhere else; in `production` the routes don't even register unless explicitly enabled.
- The gate lives in a **published application service provider stub** (`stubs/SynapseServiceProvider.stub` → `app/Providers`) so users own it. A base `SynapseApplicationServiceProvider` + `Synapse::auth(callback)` + an `Authorize` middleware wire it together.

## Testing (Orchestra Testbench + Pest)

```php
// tests/TestCase.php
abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app): array { return [SynapseServiceProvider::class]; }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }
}
```

```php
// tests/Pest.php
uses(TestCase::class, RefreshDatabase::class)->in(__DIR__);
```

- Migrations are publish-only in production but loaded explicitly in tests via `defineDatabaseMigrations()` (they never auto-register in the app).
- Env in tests is `testing`, so `Synapse::check()` isn't "local" — set `Synapse::auth(fn () => true)` to test authorized access, omit it to test the 403 path.
- The **workbench app** (`workbench/`) holds sample agents for tests and `testbench serve`. A separate **gitignored real Laravel app** (`testing-laravel-project/`, created by `bin/setup-testing-app.sh`) exercises the true `composer require` + `synapse:install` flow.

## Frontend (React SPA in a package)

- Vite builds `resources/js` → committed `dist/` (published to `public/vendor/synapse`). Users never run npm.
- `manifest: true`; `Synapse::css()/js()` resolve hashed filenames from the published `.vite/manifest.json` (returns an HTML comment when unbuilt — never fatal).
- One blade shell + a catch-all route (`/{view?}` where `(.*)`); the JSON API lives under a `/api` prefix; config is injected via `window.Synapse = @json(Synapse::scriptVariables())`.
- Stack: React + TypeScript, Tailwind v4 (CSS-first `@theme`, no config file), shadcn/ui (vendored), path alias `@/ → resources/js`.

## SDK dependency note

`laravel/ai` is pulled from the local `references/laravel/ai` via a Composer **path repository** (pinned `version: 0.9.1`) until it's on Packagist. The package-level path repo is harmlessly ignored when the path is absent (external installs). See DEV.md.

## See also

- **AGENTS.md** — Synapse architecture map + coding standards.
- **DEV.md** — exact dev-cycle commands (test / lint / analyse / build).
- **PRD.md / GOAL.md** — product spec and user-facing behavior (source of truth for features).
