# DEV.md — Development Cycle

How to build, test, and verify Synapse locally. For architecture and standards see [AGENTS.md](AGENTS.md); for Laravel-package mechanics invoke the **`laravel-package`** skill.

## Prerequisites

- PHP **8.3+** (developed on 8.4)
- Composer 2
- Node **20+** and npm

`laravel/ai` is not yet on Packagist, so it is resolved from the local `references/laravel/ai` copy via a Composer **path repository** declared in `composer.json`. Keep that reference copy in place (it's gitignored). When the SDK is published, the path repo is ignored automatically and Packagist is used instead.

## First-time setup

```bash
composer install     # PHP deps (+ testbench, pest, larastan, pint); auto-discovers the package
npm install          # frontend deps
npm run build        # compile resources/js → dist/ (with .vite/manifest.json)
```

## The three quality gates

Run all three before every commit. Each has a Composer script:

```bash
composer test        # Pest test suite (Testbench, in-memory sqlite)
composer lint        # Pint — auto-fixes formatting (laravel preset)
composer lint:test   # Pint in check-only mode (use in CI / to verify without writing)
composer analyse     # PHPStan level 5 via larastan (memory limit baked in at 1G)
```

Expected clean output:

- `composer test` → `Tests: 8 passed`
- `composer lint:test` → `{"tool":"pint","result":"passed"}`
- `composer analyse` → `[OK] No errors`

**PHPStan philosophy:** fix the underlying type/bug. Do not add `@phpstan-ignore`, baseline entries, `assert()`, inline `@var`, or casts to make an error disappear. An "always true/false" error means an annotation is wrong (e.g. a model `@property` typed too narrowly for a JSON column — use `array<int, mixed>` where element shape isn't guaranteed).

## Frontend workflow

```bash
npm run build        # one-off production build → dist/
npm run watch        # rebuild dist/ on change while iterating
```

`dist/` is **committed** — rebuild and commit it whenever `resources/js` changes. `Synapse::css()/js()` read the published `.vite/manifest.json`; if `dist/` is missing they emit a harmless comment (never fatal), so the PHP side won't break before a build.

## Manual install test (real Laravel app)

The package's own suite runs against the `workbench/` app. To exercise the *real* `composer require` + `synapse:install` flow as a user would:

```bash
./bin/setup-testing-app.sh          # creates gitignored testing-laravel-project/ (one-time)
cd testing-laravel-project
php artisan serve                   # then open http://127.0.0.1:8000/synapse
```

The script builds assets, creates a fresh Laravel app, wires path repositories for the package and the SDK, requires `redberry/synapse:@dev`, runs `synapse:install`, and seeds the four sample agents into `app/Agents`.

Iterating on the package while the test app is running: because the package is **symlinked** into the app's `vendor/`, PHP changes are live. For frontend changes, `npm run watch` in the package, then refresh published assets in the app:

```bash
# inside testing-laravel-project/
php artisan vendor:publish --tag=synapse-assets --force
```

`testing-laravel-project/` is gitignored; recreate it anytime with the setup script.

## Common tasks

| Task | How |
|------|-----|
| Add a migration | Create `database/migrations/*_*.php` extending `SynapseMigration`; `text` columns for JSON; add a `defineDatabaseMigrations`-covered test. Migrations are publish-only — tests load them explicitly. |
| Add a model | Extend `SynapseModel`; add `array` casts + `@property` docblocks; `const UPDATED_AT = null` if the table has only `created_at`. |
| Add a command | Create in `src/Console`, register in `SynapseServiceProvider::registerCommands()`, add a Pest test asserting behavior. |
| Add an API route | Add under the `/api` group in `routes/web.php` (before the catch-all); back it with a controller in `src/Http/Controllers`. |
| Add a sample agent | Add to `workbench/app/Agents` (namespace `Workbench\App`); the setup script rewrites it to `App\` for the test app. |
| Verify a change end-to-end | Run the three gates, then boot the test app and check `/synapse` in the browser. |

## Pre-commit checklist

- [ ] `composer test` green
- [ ] `composer lint` run (formatting applied) and `composer lint:test` green
- [ ] `composer analyse` green
- [ ] `npm run build` run if `resources/js` changed, and `dist/` staged
- [ ] PRD.md / GOAL.md updated if behavior or a technical decision changed
