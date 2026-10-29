# v0.3.0 release validation — 2026-10-29

## Candidate and evidence

Baseline: `3526416e161988e836387921eff7c01b8c2e689a`, with release documentation, the Laravel 12 citation compatibility fix, and two more precise browser-test selectors on `codex/prepare-v0.3.0`. No release tag or publication was created.

Disposable copies, fresh applications, scripts, logs, and provider transcripts live under gitignored `build/release-v030/`. They contain local-only application configuration and are not release contents. Primary gate logs are `/tmp/v030-final-{check,e2e}.log`; audit/build logs use the same `/tmp/v030-` prefix. Do not commit the smoke apps or their environment files.

## Compatibility matrix

Each row resolved dependencies independently from Packagist and passed `composer check`: Pint, PHPStan, **417 tests / 1,508 assertions**. SDK version is **1.3.0** throughout. PHP 8.3 ran in `synapse-qualification:php83-gd`; PHP 8.4 used the local Herd runtime. Feature and browser suites were sequential within each checkout.

| PHP | Laravel | Testbench | Resolution | Result |
| --- | --- | --- | --- | --- |
| 8.3.36 | 12.62.0 | 10.11.0 | Framework minimum | Pass |
| 8.3.36 | 12.70.2 | 10.12.0 | Current allowed stable | Pass |
| 8.3.36 | 13.15.0 | 11.1.0 | Framework minimum | Pass |
| 8.3.36 | 13.39.0 | 11.3.0 | Current allowed stable | Pass |
| 8.4.27 | 12.62.0 | 10.11.0 | Framework minimum | Pass |
| 8.4.27 | 12.70.2 | 10.12.0 | Current allowed stable | Pass |
| 8.4.27 | 13.15.0 | 11.1.0 | Framework minimum | Pass |
| 8.4.27 | 13.39.0 | 11.3.0 | Current allowed stable | Pass |

The minimum rows pin the declared framework/Testbench floors; they are not a `--prefer-lowest` test of every transitive dependency. Final matrix evidence: `results-php83.json` / `results-php84.json` for latest rows, and `results-php83-floor.json` / `results-php84-floor.json` for corrected floor reruns. Earlier floor failures in the first files are superseded by the explicit floor rerun results, not counted as passes.

Full browser suites, all **126 passed / 779 assertions**, no skips:

| Runtime | Laravel | Duration | Log |
| --- | --- | --- | --- |
| PHP 8.4.27 | 12.70.2 | 37.21s | `php84-l12-latest-browser.log` |
| PHP 8.4.27 | 13.39.0 | 36.76s | `php84-l13-latest-browser.log` |
| PHP 8.5.12 | 13.32.0 | 33.97s | `/tmp/v030-final-e2e.log` |

The primary PHP 8.5 checkout also passed `composer check` after refreshing its vulnerable local dependency. `composer validate --strict`, `npx tsc --noEmit`, `npm run build`, and `git diff --check` passed. Build output matches committed `dist/` exactly.

## Findings fixed during validation

### Laravel 12 citation normalization

`CitationSources` called `Illuminate\Support\Uri::toString()`, an API absent on Laravel 12. The existing citation suite reproduced **12 failures / 11 passes**, and PHPStan reported the unavailable method. Switched to `value()`, supported by both framework majors, while retaining Unicode normalization and URL safety checks. All matrix rows and citation cases then passed. Reproduction log: `/tmp/v030-l12-citation-before.log`.

### Ambiguous History browser navigation

The original PHP 8.5 run appeared stalled and was interrupted. A subsequent diagnostic run completed with 125 passes and one History failure. `click('SupportAgent')` could select the recent-conversation link instead of the intended Discovery card. The application had correctly remembered the fresh-thread state; the test navigated to an explicit old conversation.

Both affected tests now scope their click to `agent-card` / `agent-card-name`. The focused tests passed, followed by complete passing browser suites. No frontend behavior was changed to accommodate the test. Evidence: `/tmp/v030-php85-history.log`, `/tmp/v030-history-after.log`, and the final browser logs.

## Dependency audits

Current stable matrix rows, both fresh applications, and the final primary Composer environment reported no unignored advisories or abandoned packages. Final npm audit reported zero vulnerabilities.

The primary checkout's ignored Composer lock was stale: updating Symfony Mime **8.1.7 → 8.1.10** resolved PKSA-wtxr-p26d-nn42. Its ignored npm lock resolved **Vite 6.4.5** and **source-map-js 1.2.2** after the initial audit flagged two high-severity packages. Existing manifest constraints already permit these fixes; no manifest constraint or tracked lock changed. Rebuilding with the patched tooling produced identical assets. These are audit results for the checked resolutions, not a claim that every version permitted by the package constraints is advisory-free.

Exact historical framework floors are affected by three advisories. Composer refused to resolve those old versions until the following IDs were explicitly exempted **only in the four disposable floor copies** using Composer's advisory policy:

| ID | Advisory |
| --- | --- |
| PKSA-b41t-wbzk-9q51 | TokenGuard authentication bypass through non-string API credentials |
| PKSA-4hy6-m98j-dz9f | Relationship inclusion can trigger unauthorized data destruction |
| PKSA-d5tc-s1qs-h781 | Debug page XSS |

Their audit output retains these under `ignored-advisories`. No exceptions were added to Synapse's manifest, the primary checkout, latest-version rows, or fresh apps. Minimum-version tests establish API compatibility; deploy current patched framework versions. Audit logs: `{row}-audit.log`, `app-l{12,13}-audit.log`, `/tmp/v030-composer-audit-final.json`, `/tmp/v030-npm-audit-final.json`.

## Distribution and installation

Created fresh Laravel 12 and 13 applications (`app-l12`, `app-l13`) and resolved `laravel/ai:^1.3` from Packagist. Synapse was installed through a Composer **artifact ZIP repository**, never a path repository. This exercises exported archive contents and dependency resolution before a public tag exists.

The candidate ZIP was generated with `git archive` export rules, then overlaid with the current exported working-tree files, including the citation fix. Only its Composer metadata received the local version `0.3.0-RC1`. SHA-256: `fc3ed4298ee0db24e0a7f32db6153d26559d52d03d941b8f8cc11b401951f6f1`. Later release-note edits do not alter its runtime; the final tag must include the completed documentation. Runtime and built-asset contents were checked against the working tree.

Performed in each application:

1. Install the SDK, publish SDK assets, then require Synapse as a dev dependency from the archive.
2. Define the `synapse_qa` SQLite connection and set `SYNAPSE_DB_CONNECTION=synapse_qa`.
3. Run `php artisan synapse:install`; verify the dashboard assets and published migrations.
4. Run install again; compare the application provider, published Synapse provider, and `bootstrap/providers.php` to ensure no duplicated/overwritten registration.
5. Audit dependencies.
6. Run `composer install --no-dev` in production; verify the package class is absent, `artisan about` boots, and `config:cache` succeeds.
7. Against fresh application and custom-storage SQLite files, run all published migrations while Synapse is absent. Verify the three Synapse tables and new columns exist on the configured connection and Synapse routes are absent.
8. Clear cached configuration and restore dev dependencies for UI testing.

Logs: `app-l{12,13}-{sdk,sdk-publish,package,install,repeat,audit,production}.log`; procedure scripts: `install-apps.py`, `deploy-apps.py`.

### Upgrade preservation

Laravel 12 first installed the real `v0.2.0` archive. Seeded a conversation, user/assistant messages, a tool observation with a stored result, and a stored attachment; snapshotted every existing column/value. Upgraded to the candidate, published migrations, and ran exactly the two new migrations. Verified original records and attachment bytes unchanged, new approval/state/observation columns present, old history still presentable, and Synapse data absent from the default application connection.

A first synthetic message fixture lacked its required display-part ID; the fixture was corrected and the two additive migrations were rolled back/replayed in this disposable app before the final preservation verification. This was a fixture error, not a package migration failure. Procedure: `app-l12/qa-upgrade.php`; logs: `app-l12-upgrade*.log`.

## HTTP streaming and live provider checks

All servers used `output_buffering=4096`. Timings measured direct HTTP reads, without piping SSE through a buffering process.

| Runtime | Framework | Scenario | First byte | Total |
| --- | --- | --- | --- | --- |
| PHP 8.3.36 | Laravel 12.70.2 | Real HTTP, deterministic provider transport, three-second local tool | 65 ms | 3,474 ms |
| PHP 8.4.27 | Laravel 13.39.0 | Real OpenAI model, two-second local tool, two model rounds | 12 ms | 4,634 ms |
| PHP 8.5.12 | Laravel 12.70.2 | Real HTTP, deterministic provider transport, three-second local tool | 53 ms | 3,446 ms |

PHP 8.3 used a separate app copy with dependencies resolved on PHP 8.3; reusing a lock resolved on PHP 8.4 initially failed the Composer platform check. No platform checks were bypassed. PHP 8.3/8.5 results verify actual HTTP flushing, not real provider connectivity. Logs: `streaming-php{83,85}.log`; PHP 8.4 details in `live-smoke.json`.

Live OpenAI `gpt-4.1-mini` checks in the clean Laravel 13 app:

- Conversational memory survives a follow-up turn.
- Tool execution appears once, returns fictional stock, has its own measured duration, and belongs to the correct model round. Follow-up uses history without executing again.
- Approval pause persists. Denial executes nothing; approval executes exactly once. Both continue the same assistant message and retain one coherent tool result.
- A 16-token limit returns partial text with `length`, not an invocation failure.
- Structured output returns the expected name/email object.
- Native WebSearch returns two saved URL sources.
- Each harness request has exactly one terminal completion and DONE marker; its final message/tool projection matches the subsequent conversation read exactly.
- Manual browser upload of `release-note.txt` returns the fictional `CEDAR-314` code from its content. After reload the attachment is served from the saved attachment route.

The HTTP harness made 11 successful requests in its final run; additional browser calls checked approval and document upload. Existing authorized credentials were used without printing them. All tool effects use fictional local fixtures. Transcripts and timing logs: `live-smoke.{py,json,log}`.

## Manual UI pass

Used the in-app browser against the archive-installed app at `http://127.0.0.1:8016/synapse`, not the old development preview. Agents written after installation appeared automatically in Discovery.

Checked Discovery and agent information, live chat and tool inspection, History navigation, saved attachment links, structured output, approval controls and resume after a full reload, denied tool display, cited source links, token-limit explanations, and collapsed/expanded model steps. Saved surfaces were revisited in both Light and Dark themes. Inspected compact headers and the separation between model duration, tool duration, and total turn time.

A deterministic SDK middleware agent additionally supplied a reasoning stream, checked in both themes and in the step inspector. Its unobserved model call correctly displays `recorded` / unavailable timing rather than an invented duration. This is display coverage, not a live reasoning-provider claim. Full browser tests additionally cover narrow-screen layouts and both-theme step inspection.

Screenshot evidence: `build/release-v030/citations-dark.png`. The candidate app remains available for local review. No browser suite calls a real provider; live-provider qualification was separate from the deterministic automated suites.

## Boundaries and finalization

Live provider coverage is OpenAI only. Content-filter/cancellation, failure/failover, partial-history preservation, Unicode URL safety, and interrupted ordering also have passing deterministic regression coverage; no claim is made that every provider emitted these conditions during this live smoke.

Pre-publication gates passed for the candidate runtime. The only installation check that necessarily waits until publication is fetching the actual `v0.3.0` tag from Packagist. This preparation is submitted for review; merge, release dating, tagging, and publication remain separate release actions. Follow [READINESS.md](READINESS.md) for that sequence.
