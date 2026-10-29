# v0.3.0 release preparation — 2026-10-29

Status: **pre-publication validation passed with the qualifications below**. Prepared for review; not tagged or published. Branch: `codex/prepare-v0.3.0`.

## Scope

The latest published release checked was v0.2.0 (2026-10-26). Candidate baseline is merged main `3526416e161988e836387921eff7c01b8c2e689a`, plus the release-preparation changes in this working tree. The feature release includes tool approvals, cited sources, completion explanations, and step inspection. Version 0.3.0 follows the repository's pre-1.0 minor-release policy. Composer supplies the installed version; no hardcoded version bump is needed.

## Documentation

- [Release notes](../../docs/releases/v0.3.0.md) describe features and their developer value, without PR or issue references.
- [Changelog](../../CHANGELOG.md) identifies the planned release and the Laravel 12 citation fix discovered during qualification.
- [Upgrade guide](../../UPGRADE.md) separates the additive 0.2 → 0.3 upgrade from the destructive legacy 0.1 → 0.2 migration. Existing 0.2 history and attachments are preserved; two migrations are added, seven total.
- README, GOAL, DEV, and the release checklist reflect the current installation and upgrade behavior.
- Existing v0.2.0 qualification artifacts remain historical evidence, not proof for this candidate.

## Before publishing

Completed during this qualification; see [VALIDATION.md](VALIDATION.md) for versions, procedures, results, and limitations.

- [x] PHP 8.3/8.4 × Laravel 12/13 compatibility at the declared framework minimums and current allowed stable resolutions: eight rows, each passing Pint, PHPStan, and 417 unit/feature tests.
- [x] Full browser suites on Laravel 12 and 13 with PHP 8.4, and on the primary PHP 8.5 environment: 126 tests and 779 assertions per run, no skips.
- [x] Resolve current dependencies from Packagist; audit current resolutions and the frontend build environment. Current resolutions have no reported advisories or abandoned packages. Historical framework floors required three narrowly scoped advisory exceptions in disposable compatibility copies only; they are not deployment recommendations.
- [x] Clean ZIP-distribution installs on Laravel 12 and 13, repeat installation, configured storage, and production boot/config caching/fresh published migrations after removing dev dependencies.
- [x] Upgrade an installed v0.2.0 archive to the candidate and verify every existing stored column, tool observation, conversation, message, and attachment is retained.
- [x] Real HTTP streaming on PHP 8.3, 8.4, and 8.5 with output buffering enabled.
- [x] Live OpenAI flows and manual UI checks, including both themes, approvals/reload/continuation, sources, completion notices, steps, history, structured output, and an uploaded document. Reasoning display was checked with a deterministic SDK fixture; not every provider or provider-specific stop condition was live-tested.
- [x] Investigate the earlier PHP 8.5 browser run: reproduced an ambiguous History test selector, corrected both navigation tests to target the Discovery card, and passed the full suite on PHP 8.5.
- [x] Rebuild assets, type-check, validate Composer metadata, and check the final diff. Rebuilt `dist/` is unchanged.

Qualification found and fixed a real compatibility defect: citation normalization called `Uri::toString()`, which is unavailable on Laravel 12. It now uses the shared `Uri::value()` API. Existing citation tests reproduced the failure and pass across the matrix after the fix.

## Release finalization

These actions remain intentionally separate from validation:

1. Review and merge the release-preparation PR, and put the actual publication date in the changelog.
2. When publication is authorized, create `v0.3.0` from the resulting main commit and use the prepared notes as the release body. Do not tag this preparation branch.
3. After Packagist receives the tag, install that published version in a fresh application and repeat the dashboard/discovery smoke. Before a tag exists, this exact published-tag check is impossible; the candidate archive installation has already been tested.

No new runtime changes should be added after qualification without rerunning the affected gates.
