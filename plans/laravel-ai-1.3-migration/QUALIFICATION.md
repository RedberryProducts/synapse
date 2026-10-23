# Step 8 qualification — 2026-10-23

Deterministic qualification passed for the migration working tree on `codex/laravel-ai-13-migration`. **Real-provider smoke testing is deferred at the user's request.** No provider credentials were used, and no tag or release was created. The planned breaking pre-1.0 release is **0.2.0**; see [CHANGELOG.md](../../CHANGELOG.md) and [UPGRADE.md](../../UPGRADE.md).

## Compatibility matrix

Every row runs **`composer check`**: Pint, PHPStan level 5, and **289 unit/feature tests with 916 assertions**. No new skips, weakened assertions, or analysis suppressions were introduced.

| PHP runtime | Laravel | Testbench | Laravel AI | Result |
|---|---|---|---|---|
| 8.3.36, Linux container | 12.62.0, declared minimum | 10.11.0 | 1.3.0 | Passed |
| 8.3.36, Linux container | 12.70.1, latest 12.x | 10.12.0 | 1.3.0 | Passed |
| 8.3.36, Linux container | 13.15.0, declared minimum | 11.1.0 | 1.3.0 | Passed |
| 8.3.36, Linux container | 13.38.0, latest 13.x | 11.3.0 | 1.3.0 | Passed |
| 8.4.15, macOS | 12.62.0, declared minimum | 10.11.0 | 1.3.0 | Passed |
| 8.4.15, macOS | 12.70.1, latest 12.x | 10.12.0 | 1.3.0 | Passed |
| 8.4.15, macOS | 13.15.0, declared minimum | 11.1.0 | 1.3.0 | Passed |
| 8.4.15, macOS | 13.38.0, latest 13.x | 11.3.0 | 1.3.0 | Passed |

The main checkout also passes `composer check` on Herd PHP **8.4.25**, Laravel **13.32.0**, SDK **1.3.0**. The matrix copies use the Homebrew PHP binary resolved in those directories; runtime versions were inspected rather than inferred from labels.

Composer's available-version metadata reported **1.3.0 as the newest stable SDK release**, so the exact floor and latest stable allowed SDK resolution coincide. Framework latest rows resolve normally within the declared major constraints. Laravel 13 supports PHP 8.3, so both majors were tested on both PHP versions.

### Historical floor advisory

Composer initially refused Laravel 12.62.0 and 13.15.0 because of **PKSA-d5tc-s1qs-h781 / GHSA-f9x3-7vq9-cph6**. Laravel documents the TokenGuard fix in **12.68.0 / 13.29.0** in its [security advisory](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6).

Only the four disposable historical-floor Composer files ignore that exact advisory, with an explicit compatibility-test reason. The package's Composer configuration and the preview app have no new advisory exceptions. These floor results establish API compatibility, not recommended deployment versions. Both latest-framework dependency audits report **zero advisories and zero abandoned packages**. Use current patched Laravel releases for deployment.

## Browser, build, streaming, and installation gates

| Gate | Result |
|---|---|
| `composer validate --strict` | Passed |
| `npx tsc --noEmit` | Passed |
| `npm run build` | Passed; existing >500 kB JavaScript chunk warning remains |
| Main checkout `composer test:e2e` | **81 passed / 310 assertions** |
| Laravel 12.70.1 / PHP 8.4.15 browser suite | **81 passed / 310 assertions** |
| Laravel 13.38.0 / PHP 8.4.15 browser suite | **81 passed / 310 assertions** |
| PHP 8.3.36 / Laravel 12.70.1 HTTP streaming, `output_buffering=4096` | HTTP 200; **75 ms first byte / 3700 ms total** |
| PHP 8.4.25 / Laravel 13.38.0 HTTP streaming, `output_buffering=4096` | HTTP 200; **62 ms first byte / 3653 ms total** |
| Existing preview HTTP streaming | HTTP 200; **18 ms first byte / 3501 ms total** |
| Laravel 12 / PHP 8.3 fresh install and repeat install | Passed; all five migrations; provider hashes unchanged on repeat |
| Laravel 12 / PHP 8.3 actual `--no-dev` deployment | Passed: package absent, production boot/config cache, all five migrations on separate fresh SQLite DB, no Synapse routes |
| Laravel 13 fresh install and actual `--no-dev` deployment | Passed in step 7; [full execution record](INSTALLATION-WALKTHROUGH.md) |
| Configured storage and legacy format transition | Passed in every feature matrix row: connection isolation, empty upgrade, nonempty refusal, attachment cleanup, rollback guards |
| `git diff --check` | Passed |

Both buffered HTTP runs used the installed package, actual slow tool execution, deterministic SDK middleware, and stray-request prevention. Saved assistant turns were `completed` and their tools `success`. Timing was measured by `bin/check-streaming.sh` without piping the curl response. Temporary servers on 8014/8015 were stopped after verification; the user preview on **8013** remains running.

Browser suites were run on PHP 8.4 for both Laravel majors; no PHP 8.3 browser result is claimed. PHP 8.3 runs the full feature suite and a real HTTP streaming check. The PHP/Laravel matrix does not requalify every web-server product: nginx/FPM, FrankenPHP, and Sail deployment-specific proxy behavior still require their own release-environment checks when used.

Built artifacts:

```text
dist/app.js  sha256 084eb8da6a5fb005a1fbf7d56b36a601a8db7315022b4cc857716be26bfa784f
dist/app.css sha256 d56ce50900c40bb9616a7067c002a8a74ffc704203bece28e9340eb0b7924520
```

## Review and regression preservation

- All **282 original `it()` / `test()` declarations** from baseline `a1b24fa` remain. Fixture changes reflect SDK serialization; existing user-facing behavior assertions are retained. Existing conditional skips remain unchanged.
- Closed production issues **#7–#10** remain covered and passing; see [REGRESSION-AUDIT.md](REGRESSION-AUDIT.md).
- Reviewed PHP, frontend, schema, and test diffs for ordered replay, partial failures, inclusive usage, approval non-execution, provider identity, private serialization, and listener cleanup. Existing focused tests cover the SDK-internal `recordedResponse()` dependency, repeated calls, nested tools, and failure recovery.
- Package code contains no SDK-table access. Provider request-shape tests use fake HTTP; browser agents remain faked. Preview middleware prevents stray provider requests.
- Removed the now-unused `StreamEmitter::streamError()` path and its import. Invocation errors already use the single owning path. Updated its stale provider attribution comment.
- No dependency changes, new frontend dependencies, unrelated refactors, or test assertion weakening were needed in step 8. No frontend source changed; assets were rebuilt for qualification.

## How the checks were run

Snapshots live in gitignored `build/release-matrix/php{83,84}-l{12,13}-{floor,latest}`. They copy current tracked and nonignored new files, omit the host's vendor/lockfiles, then constrain only the framework, Testbench major, and SDK version in each copy. The original checkout's dependencies and preview app were preserved.

For each isolated copy, set `laravel/framework` to `12.62.0`, `^12.62`, `13.15.0`, or `^13.15`; `orchestra/testbench` to `^10.0` or `^11.0`; and `laravel/ai` to `1.3.0`. Then:

```bash
composer update --no-interaction --prefer-dist
composer check
# Only after the feature suite finishes, with node_modules and current dist available:
composer test:e2e
```

The PHP 8.3 container was built from official `php:8.3-cli-bookworm`, with Laravel/Pest extensions including GD, SQLite, intl, mbstring, zip, dom, bcmath, pcntl, and sockets, plus Composer. The local image named `sail-8.3/app` actually ran 8.4, so it was not used as PHP 8.3 evidence. Final PHP 8.3 checks ran with Docker networking disabled after dependency installation:

```bash
docker run --rm --network=none \
  -e COMPOSER_ALLOW_SUPERUSER=1 \
  -v "$PWD/build/release-matrix/php83-l12-latest:/app" -w /app \
  synapse-qualification:php83-gd sh -c 'php -v && composer check'
```

The Laravel 12 host was created with `composer create-project laravel/laravel build/release-matrix/app-l12 '^12.0' --no-install --no-scripts --no-interaction`. Its isolated Composer config requires SDK 1.3.0 and Synapse as a dev path dependency. `qualify.sh` in that app records the full install, provider-hash comparison, actual dev-dependency removal, production config cache/migration, and restoration commands. It mounts the package snapshot at `/package`; it is a qualification fixture, not the user's preview app.

For buffered streaming, PHP was started **from the host app's `public/` directory** with `-d output_buffering=4096 -S ... -t . ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`. The initial attempt from the app root did not load `public/index.php`; it produced no valid timing and was corrected before measurement.

Two other setup failures were resolved without weakening tests: the initial PHP 8.3 image lacked GD, and an overlapping Laravel 12 feature/browser run raced over Testbench migration files. After adding GD and sequencing the suites, complete reruns passed. The first sandboxed main-checkout run also lacked Pest's local port access; the authorized rerun passed.

Logs: `/tmp/synapse-step8-check.log`, `-build.log`, `-browser.log`, `-l12-browser.log`, `-l13-browser.log`, `-php{83,84}-l{12,13}-{floor,latest}-check.log`, `-l12-install.log`, `-stream83.log`, `-stream84.log`, and the latest-resolution `-audit.json` files. Container build recipes and resolved lockfiles remain in `build/release-matrix` for inspection.

## Deferred real-provider check

When the user supplies/configures an API key, disable the local preview middleware for the selected agent and verify real text streaming, tool execution, two-turn history, structured output, error presentation, reload, and usage/provider identity. Exercise another provider and failover if those are intended for the release. This has **not** been run or inferred from fake responses. Approval resume is still issue #1 and remains outside this migration.

Tag/publish require a separate instruction. The planned release number appears only in draft release documentation; Composer's tagged version remains the source of truth when a release is actually prepared.
