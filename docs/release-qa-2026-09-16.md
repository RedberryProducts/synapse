# Release QA — 2026-09-16

Result: no release blocker found in the tested candidate. Base: main at a8dfa9a (all three reviewed PRs merged). The v0.1.3 preparation PR includes the rebuilt bundle and documentation corrections. No tag or release was created during QA.

## Gates

| Check | Result |
| --- | --- |
| Composer install and strict validation | Passed |
| Pint + PHPStan + unit/feature tests, Laravel 13.21.1 / SDK 0.10.2 | Passed; 223 tests, 622 assertions |
| Isolated PHP checks, Laravel 12.69.2 / SDK 0.9.1 / Testbench 10.11.0 | Passed; 223 tests, 622 assertions |
| Frontend production build and TypeScript | Passed |
| Final Chromium browser suite | Passed; 76 tests, 271 assertions |
| npm audit of final dependency resolution | No advisories |
| Composer audit after local dependency refresh | No advisories or abandoned packages |
| Git whitespace checks | Passed |

## Installation and deployment

Created disposable Laravel 12.69.2 and 13.32.0 applications from published skeletons. Installed a copy of the git release archive as a dev dependency, with SDK 0.10.3 resolved from Packagist (no reference-checkout override).

Both applications passed:

- Package discovery, synapse:install, and repeated installation.
- Published migrations and configuration/route caching.
- Dashboard and discovery API HTTP-kernel responses (200); 14 sample agents discovered.
- Staging access denied (403).
- Cached production routes absent by default (404).
- Explicitly enabling production routes still denies access without authorization (403).
- composer install --no-dev removes Synapse and the SDK without breaking application boot.
- Published migrations run against a fresh SQLite database after package removal.
- Production configuration and route caching after package removal.

The archive contains compiled assets, views, config, migrations, and the provider stub. Tests and frontend sources are excluded as intended. The rebuilt candidate bundle was also checked through the disposable applications before their dev dependencies were removed.

## Real HTTP streaming

Ran bin/check-streaming.sh against PHP development servers with a deterministic faked agent that invokes the real SlowTool for four seconds. No paid provider API calls were made.

| Runtime | HTTP status | First byte | Total |
| --- | --- | --- | --- |
| Laravel 12.69.2 | 200 | 47 ms | 4080 ms |
| Laravel 13.32.0 | 200 | 54 ms | 4097 ms |

Both satisfy the streaming gate. Temporary servers were stopped afterward.

## Findings and candidate changes

The original checkout passed the functional gates but its ignored local lockfiles contained advisory-affected dependencies. Refreshed local CommonMark to 2.10.1, PostCSS to 8.5.28, and nanoid to 3.3.19 within existing constraints.

An exploratory build with React Router 7.18.4 failed the existing “stays blank when a fresh thread is what you chose” browser test. That build is not the release candidate. The candidate uses security-patched React Router 7.18.2 and passes the full browser suite. Its committed-bundle diff consists only of two React Router version metadata strings compared with main. Do not substitute a new dependency resolution without rerunning browser QA; the cause of the 7.18.4 test failure was not diagnosed here.

Corrected DEV.md and the Laravel package skill: the SDK is available on Packagist, no local SDK path repository is required, and the repository currently has no automated test-matrix workflow. Also aligned the skill's authorization description with the current installer.

## Scope and release handoff

Test host: macOS, PHP 8.4.15, Node 25.8.1, SQLite, Playwright Chromium. PHP 8.3/8.5, MySQL/PostgreSQL, Safari/Firefox, and nginx/PHP-FPM were not exercised in this run. Provider I/O was faked; live credentials/provider availability were not tested. Existing documentation excludes Octane support.

After merging the v0.1.3 preparation PR, create the v0.1.3 release in GitHub against the merged main commit and use docs/releases/v0.1.3.md as the release description. If code, assets, or dependencies change before release, rerun the relevant gates. No release or tag was created by this QA run.

Detailed logs and disposable apps are under /tmp/synapse-release-* and /tmp/synapse-release-qa; temporary files may be removed by the OS.

## Release preparation revalidation — 2026-09-18

The v0.1.3 branch pins react-router-dom to 7.18.2, records the candidate's release notes and upgrade instructions, and updates the Composer-version fallback to 0.1.3. Revalidated after a clean npm install: production build, TypeScript, strict Composer validation, Pint, PHPStan, 223 unit/feature tests (622 assertions), and 76 browser tests (271 assertions) passed. Both dependency audits returned no advisories. The earlier clean-app, compatibility-matrix, and streaming results above were not rerun for these release metadata changes.
