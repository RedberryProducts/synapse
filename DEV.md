# DEV.md — Development Cycle

How to build, test, and verify Synapse locally. For architecture and standards see [AGENTS.md](AGENTS.md); for Laravel-package mechanics invoke the **`laravel-package`** skill.

## Prerequisites

- PHP **8.3+** (developed on 8.4)
- GD for the image-upload test fixtures, plus the normal Laravel/Pest extensions and SQLite
- Composer 2
- Node **20+** and npm
- Playwright + Chromium — only for browser e2e tests (`npm install && npx playwright install chromium`)

`laravel/ai` resolves from Packagist using the constraints in `composer.json`. The optional `references/laravel/ai` checkout is read-only reference material, not an installation dependency.

## First-time setup

```bash
composer install     # PHP deps (+ testbench, pest, larastan, pint); auto-discovers the package
npm ci               # frontend deps from the committed lockfile
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

- `composer test` → all unit and feature tests pass
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
npm ci               # install the exact locked dependency versions
npm run build        # one-off production build → dist/
npm run watch        # rebuild dist/ on change while iterating
```

`react-router-dom` is pinned to 7.18.6. Re-test fresh-thread navigation before upgrading the pin.

`package-lock.json` is committed so local and CI builds use the same dependency versions. When intentionally updating npm dependencies, include the updated lockfile and rebuilt `dist/` in the same PR. `composer.lock` remains ignored so CI resolves the package's allowed PHP dependencies afresh.

`dist/` is **committed** — rebuild and commit it whenever `resources/js` changes. `Synapse::css()/js()` read `dist/app.css` and `dist/app.js` directly and inline them into the layout, so there is nothing to publish: a running app picks up a rebuild on the next page load. If `dist/` is missing they emit a harmless comment (never fatal).

## Manual install test (real Laravel app)

The package's own suite runs against the `workbench/` app. To exercise the *real* `composer require` + `synapse:install` flow as a user would:

```bash
./bin/setup-testing-app.sh          # creates gitignored testing-laravel-project/ (one-time)
cd testing-laravel-project
php artisan serve                   # then open http://127.0.0.1:8000/synapse
```

The script builds assets, creates a fresh Laravel app, wires a path repository for Synapse and resolves the SDK from Packagist, requires `redberry/synapse:@dev`, runs `synapse:install`, and seeds the four sample agents into `app/Agents`.

Iterating on the package while the test app is running: because the package is **symlinked** into the app's `vendor/`, both PHP *and* frontend changes are live — run `npm run watch` in the package and just refresh the browser. Assets are inlined from `dist/`, so there is no publish step.

When refreshing an existing app, the script removes the legacy `laravel-ai` path repository and updates Synapse's dependencies so the lockfile no longer points to the local SDK.

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
- [ ] README / UPGRADE updated for user-visible changes; lasting contributor guidance kept in AGENTS
- [ ] `composer test:e2e` green if you touched the frontend or the chat/stream surface (always before a release)

## Release checklist

1. `composer check` — lint, static analysis, unit + feature tests
2. `npx tsc --noEmit` and `npm run build`; include current `dist/` in the release commit
3. **`composer test:e2e`** — the manual browser gate (not run in CI); do not overlap it with feature tests in the same checkout
4. Verify both supported Laravel majors on actual PHP 8.3 and 8.4, at the declared framework/SDK minimums and latest allowed stable resolutions
5. Smoke-test fresh installation, repeated installation, configured storage, and production boot/migrations after removing dev dependencies
6. Measure real HTTP streaming with output buffering enabled and a deterministic slow tool; use the [runtime qualification fixtures](tests/Runtime/README.md) when changing runtime support claims
7. Review [UPGRADE.md](UPGRADE.md) and [CHANGELOG.md](CHANGELOG.md); follow [docs/RELEASE.md](docs/RELEASE.md) and record validation in the release-preparation PR
8. Commit/tag/publish only when explicitly requested

## CI

Every pull request and push to `main` runs:

- [Tests](https://github.com/RedberryProducts/synapse/actions/workflows/ci.yml): `composer check` on PHP 8.3 and 8.4 with both the minimum supported SDK (1.3.0) and the latest stable SDK allowed by `composer.json` — four required PHP jobs. All other dependencies resolve to current allowed stable versions; this tests the SDK minimum rather than globally lowering Laravel and development tools with `--prefer-lowest`. Keep the minimum SDK pin aligned with the declared lower bound. A separate Node 24 job runs `npm ci`, rebuilds the assets, and fails if `git diff --exit-code -- dist/` finds stale committed assets. All PHP jobs run to completion even if one fails.
- [Code style](https://github.com/RedberryProducts/synapse/actions/workflows/code-style.yml): Pint's check-only command, `composer lint:test`, on PHP 8.4. A separate workflow gives the code-style badge its own result.

The README's live badges select `main` push runs. The tests badge covers the entire Tests workflow, including all four PHP/SDK jobs and the asset check; a single passing matrix job cannot hide a failure. The code-style badge covers only the Pint workflow. Missing workflow results are not replaced with a static passing badge.

After these workflows first run on `main`, verify both README badge images render the corresponding workflow result and both links open the filtered `main` push runs. Record that post-merge verification on the implementation PR; local checks cannot establish a default-branch workflow result.

[SDK early warning](https://github.com/RedberryProducts/synapse/actions/workflows/sdk-latest.yml) runs its SDK checks every other Monday at 06:17 UTC (10:17 in Tbilisi), on a 14-day cadence anchored to November 16, 2026. A small weekly gate skips the SDK job on alternate weeks; the next eligible Mondays after that anchor are November 30 and December 14. **Run workflow** always runs the SDK checks, including on skipped weeks. The workflow selects the newest published stable SDK from Composer's available-version metadata and pins that exact version in the disposable checkout, replacing the supported SDK constraint for that run. This prevents Composer from silently selecting an older release when the newest SDK requires an unsupported PHP or Laravel version; such conflicts fail visibly. Both workflows restrict resolution to stable dependencies in their CI checkout without changing the committed package requirements.

The early-warning workflow fails normally when dependency resolution or `composer check` fails. It has no PR trigger and is separate from the required Tests workflow and README badges, so its failure remains visible without blocking PRs. A passing run outside the declared SDK constraint is a compatibility signal, not a support claim; review the SDK changelog and complete release qualification before expanding support. Scheduled and manual runs become available after the workflow reaches `main`.

Browser tests remain outside CI. Run the release gates locally before tagging, including `composer check`, `npm run build`, and `composer test:e2e`.

The Maestro task-trigger workflow remains temporarily disabled in GitHub Actions and its job is disabled in source. [Issue #22](https://github.com/RedberryProducts/synapse/issues/22) tracks reactivation when the integration is needed again.

Compatibility should be checked on both supported Laravel majors (12 and 13), including a fresh install and production boot after `composer install --no-dev`. Browser e2e requires Playwright, Chromium, and current compiled assets.

## Dependency updates

Dependabot alerts and security updates are enabled in the repository settings. GitHub monitors detected npm and Composer dependencies and attempts to open security-fix PRs when a compatible patch is available. If it cannot generate a fix, inspect the alert for the reason. These [security updates](https://docs.github.com/en/code-security/concepts/supply-chain-security/dependabot-security-updates) run independently of the monthly Composer version-update schedule in [.github/dependabot.yml](.github/dependabot.yml).

- **npm:** Dependabot uses `package.json` and the committed `package-lock.json`. For a security PR, review the manifest and lockfile changes, run `npm ci`, `npx tsc --noEmit`, and `npm run build`, and include any changed `dist/` assets in the PR. Run `composer check`, then `composer test:e2e`. Users receive the compiled frontend, so a lockfile change alone does not update their bundle; the existing CI asset check catches stale build output.
- **Composer:** Dependabot checks for minor and patch version updates monthly, using `increase-if-necessary` to leave requirements unchanged when they already allow a release; routine major-version updates are excluded. Since `composer.lock` remains ignored, an already-allowed release may produce no PR. Existing CI resolves allowed dependencies afresh on each PR and push to `main`. Review any proposed constraint changes against supported Laravel and SDK versions, and require the PHP/SDK CI matrix to pass. Changes affecting chat, streaming, or the PHP-to-React boundary also need `composer test:e2e`. This manifest coverage does not audit a consuming application's exact PHP dependency tree; each application owns its lockfile and should run `composer audit` against its resolved dependencies.

Keep Composer dependency constraints in three-part form (for example, `^1.3.0`), with the highest supported major first in unions. Without a lockfile, Dependabot extracts its base version from the first three-part version to apply the routine major-update filter.

## Upgrade and release history

Follow [UPGRADE.md](UPGRADE.md) for version-specific migration instructions. Only legacy 0.1.x storage requires a reset; 0.2.0 → 0.3.0 uses two additive migrations and preserves history. Fresh 0.3.0 installations run seven published Synapse migrations, separate from the SDK's own tables.

Use [closed issues](https://github.com/RedberryProducts/synapse/issues?q=is%3Aissue+is%3Aclosed) and [merged PRs](https://github.com/RedberryProducts/synapse/pulls?q=is%3Apr+is%3Amerged) for completed implementation and validation history. [SDK opportunities](plans/SDK-OPPORTUNITIES.md) contains future candidates, not committed scope.
