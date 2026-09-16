# DEV.md — Development Cycle

How to build, test, and verify Synapse locally. For architecture and standards see [AGENTS.md](AGENTS.md); for Laravel-package mechanics invoke the **`laravel-package`** skill.

## Prerequisites

- PHP **8.3+** (developed on 8.4)
- Composer 2
- Node **20+** and npm
- Playwright + Chromium — only for browser e2e tests (`npm install && npx playwright install chromium`)

`laravel/ai` is not yet on Packagist, so it is resolved from the local `references/laravel/ai` copy via a Composer **path repository** declared in `composer.json`. Keep that reference copy in place (it's gitignored). When the SDK is published, the path repo is ignored automatically and Packagist is used instead.

## First-time setup

```bash
composer install     # PHP deps (+ testbench, pest, larastan, pint); auto-discovers the package
npm install          # frontend deps
npm run build        # compile resources/js → dist/app.js + dist/app.css
composer hooks       # enable the pre-commit hook (once per clone)
```

## The three quality gates

Run all three before every commit — or let the [pre-commit hook](#pre-commit-hook) do it:

```bash
composer test        # Pest: unit + feature suites (Testbench, in-memory sqlite)
composer lint        # Pint — auto-fixes formatting (laravel preset)
composer lint:test   # Pint in check-only mode (used by CI and the hook)
composer analyse     # PHPStan level 5 via larastan (memory limit baked in at 1G)
composer check       # all three in sequence (lint:test → analyse → test)
```

Expected clean output:

- `composer test` → `Tests: 8 passed`
- `composer lint:test` → `{"tool":"pint","result":"passed"}`
- `composer analyse` → `[OK] No errors`

**PHPStan philosophy:** fix the underlying type/bug. Do not add `@phpstan-ignore`, baseline entries, `assert()`, inline `@var`, or casts to make an error disappear. An "always true/false" error means an annotation is wrong (e.g. a model `@property` typed too narrowly for a JSON column — use `array<int, mixed>` where element shape isn't guaranteed).

## Browser end-to-end tests

A separate tier that drives a **real browser** (Pest 4 + Playwright) against the dashboard — the only way to verify what no PHP test can reach: that the compiled React app mounts, renders, and routes. It is **excluded from `composer test` and from CI by design**; run it manually before tagging a release.

```bash
npm run build                            # e2e needs current compiled assets
npx playwright install chromium          # one-time
composer test:e2e                        # runs tests/Browser (suite "e2e")
```

How it works: the Pest browser plugin boots the Testbench app **in-process** (no `artisan serve` needed). Assets are inlined from `dist/`, so `tests/BrowserTestCase.php` only opens the `viewSynapse` gate; if `dist/` isn't built, the tests **skip** with a clear message rather than fail.

Suites are defined in `phpunit.xml.dist` (`unit`, `feature`, `e2e`); `composer test` runs `--testsuite=unit,feature`, `composer test:e2e` runs `--testsuite=e2e`. Browser tests are also tagged with the Pest group `e2e`.

When writing browser tests for agent behavior, drive them with the SDK's `Agent::fake()` so they're deterministic and never call a real provider (no API spend, no flakiness). Failure screenshots land in `tests/Browser/Screenshots` (gitignored).

## Pre-commit hook

`composer hooks` points `core.hooksPath` at `.githooks/`. The hook runs the three fast gates plus a check that `dist/` was rebuilt when `resources/js` changed.

- **Not** included: browser e2e (needs Playwright + built assets — an environment problem shouldn't block an unrelated commit).
- Bypass a single commit with `git commit --no-verify`.

## Frontend workflow

```bash
npm run build        # one-off production build → dist/
npm run watch        # rebuild dist/ on change while iterating
```

`dist/` is **committed** — rebuild and commit it whenever `resources/js` changes. `Synapse::css()/js()` read `dist/app.css` and `dist/app.js` directly and inline them into the layout, so there is nothing to publish: a running app picks up a rebuild on the next page load. If `dist/` is missing they emit a harmless comment (never fatal).

## Manual install test (real Laravel app)

The package's own suite runs against the `workbench/` app. To exercise the *real* `composer require` + `synapse:install` flow as a user would:

```bash
./bin/setup-testing-app.sh          # creates gitignored testing-laravel-project/ (one-time)
cd testing-laravel-project
php artisan serve                   # then open http://127.0.0.1:8000/synapse
```

The script builds assets, creates a fresh Laravel app, wires path repositories for the package and the SDK, requires `redberry/synapse:@dev`, runs `synapse:install`, and seeds the four sample agents into `app/Agents`.

Iterating on the package while the test app is running: because the package is **symlinked** into the app's `vendor/`, both PHP *and* frontend changes are live — run `npm run watch` in the package and just refresh the browser. Assets are inlined from `dist/`, so there is no publish step.

`testing-laravel-project/` is gitignored; recreate it anytime with the setup script.

## Common tasks

| Task | How |
|------|-----|
| Add a migration | Create a self-contained `database/migrations/*_*.php` extending Laravel's `Migration`; resolve `synapse.storage.connection` in the file, use `text` columns for JSON, and add a `defineDatabaseMigrations`-covered test. Migrations are publish-only and must remain loadable after Synapse is removed. |
| Add a model | Extend `SynapseModel`; add `array` casts + `@property` docblocks; `const UPDATED_AT = null` if the table has only `created_at`. |
| Add a command | Create in `src/Console`, register in `SynapseServiceProvider::registerCommands()`, add a Pest test asserting behavior. |
| Add an API route | Add under the `/api` group in `routes/web.php` (before the catch-all); back it with a controller in `src/Http/Controllers`. |
| Add a sample agent | Add to `workbench/app/Agents` (namespace `Workbench\App`); the setup script rewrites it to `App\` for the test app. |
| Add a browser test | Add to `tests/Browser/` (auto-uses `BrowserTestCase` + group `e2e`); target with `@testid`, assert content with `assertSeeIn`, drive agents with `Agent::fake()`. See AGENTS.md → Writing browser tests. |
| Verify a change end-to-end | Run `composer check`, then `composer test:e2e` (or boot the test app and check `/synapse` in the browser). |

## Pre-commit checklist

Mostly automated by the hook (`composer hooks`):

- [ ] `composer check` green (lint + analyse + test) — *enforced by the hook*
- [ ] `npm run build` run if `resources/js` changed, and `dist/` staged — *enforced by the hook*
- [ ] PRD.md / GOAL.md updated if behavior or a technical decision changed
- [ ] `composer test:e2e` green if you touched the frontend or the chat/stream surface (always before a release)

## Release checklist

1. `composer check` — lint, static analysis, unit + feature tests
2. `npm run build` and commit `dist/` if the frontend changed
3. **`composer test:e2e`** — the manual browser gate (not run in CI)
4. Smoke-test the real install flow: `./bin/setup-testing-app.sh` → open `/synapse`
5. Tag the release

## CI

CI runs the fast gates only, on a matrix of PHP 8.3/8.4 × Laravel 12/13:

```bash
composer install --no-interaction --prefer-dist
composer check
```

**Browser e2e is intentionally out of CI** — no Playwright, no browser binaries, no build step in the pipeline. It's a manual pre-release gate (`composer test:e2e`).

Note: CI needs `laravel/ai` resolvable. Until it's on Packagist, either commit the reference copy for CI or add a CI-only path/VCS repository for it.
