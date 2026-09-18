# Changelog

All notable changes to `redberry/synapse` are documented here.

This project follows [Semantic Versioning](https://semver.org/). While the
version is below `1.0.0`, minor releases may contain breaking changes.

## Unreleased

## Unreleased

### Fixed

- Allow Laravel AI SDK 0.11 alongside 0.9 and 0.10, fixing Composer conflicts in applications already using SDK 0.11. Future SDK minor versions remain subject to compatibility testing.

## v0.1.3

### Fixed

- **The playground no longer looks stalled before the first stream event.** Sending a message now inserts an assistant-side `Loading...` row immediately, keeps it visible during slow provider startup, and clears or replaces it on the first reasoning, tool, notice, text, structured-output, or error event. Pre-stream failures remove the placeholder instead of leaving an empty assistant block behind.
- **Dev-only installs no longer break production boot.** `synapse:install` now registers the published application provider only in `local` and only while Synapse's package classes exist. It also removes the old unconditional `bootstrap/providers.php` entry, so applications continue to boot after `composer install --no-dev`; repeat installs remain idempotent and preserve application edits.
- **Production migrations remain runnable after Synapse is removed.** Published migrations and the application provider no longer require package classes, while preserving `synapse.storage.connection`. Dev-mode package removal also unregisters the published provider automatically. Existing installations can force-republish migrations and update or unregister their old provider before deploying with `composer install --no-dev`.
- **Long agent names no longer spill out of Discovery cards or the sidebar.** Constrained labels now shrink correctly, truncate to a single line with an ellipsis, and keep the full class name available through the browser's native tooltip on both Discovery cards and the sidebar agent list. Browser coverage exercises available and unavailable cards at desktop and narrow widths.

### Changed

- The compiled dashboard uses the tested React Router 7.18.2 patch. Frontend builds pin that version while the navigation regression observed with 7.18.4 is investigated.

### Upgrade notes

- Before deploying without development dependencies, run `php artisan synapse:install` locally to migrate legacy provider registration. If the installer reports an ambiguous existing registration, follow its guidance rather than leaving an unguarded call in place.
- Refresh older package-dependent migrations with `php artisan vendor:publish --tag=synapse-migrations --force` while Synapse is installed. Review any custom migration edits before replacing files.
- Outside `local`, explicitly register the published provider and configure its `viewSynapse` gate if you intend to enable the dashboard. Enabling routes alone does not grant access.
- See [v0.1.3 release notes](https://github.com/RedberryProducts/synapse/blob/main/docs/releases/v0.1.3.md) for the full upgrade procedure and known limitations.

## v0.1.2

### Fixed

- **The dashboard reported the wrong version.** `Synapse::VERSION` was a hardcoded `0.1.0` that was not bumped for the `v0.1.1` release, so `php artisan about`, the sidebar footer and `window.Synapse.version` all claimed `0.1.0` on a `0.1.1` install. The version is now read from Composer's runtime metadata and cannot drift from what is installed; a test asserts the two agree.

### Changed

- **The published archive shrank from 2.8 MB to 0.8 MB.** Added `.gitattributes` with `export-ignore` for planning documents, tests, fixtures, tooling config and the TypeScript source — none of which a consuming application runs. `plans/` alone was 1.3 MB downloaded into every project on every install. `dist/` is deliberately kept: it is inlined at runtime from inside the package.

## v0.1.1

**Fixes an install that cannot succeed.** `v0.1.0` requires `laravel/ai ^0.9`,
but the SDK had already released `0.10`. A fresh application following the
documented steps — `composer require laravel/ai` then
`composer require redberry/synapse --dev` — resolved the SDK to `0.10.2` and
then failed outright:

```
redberry/synapse v0.1.0 requires laravel/ai ^0.9 -> found laravel/ai[v0.9.0, v0.9.1]
but it conflicts with your root composer.json require (^0.10.2).
```

### Fixed

- Widened the SDK constraint to `^0.9|^0.10`. Every class Synapse imports exists unchanged in `0.10.2`, and the full suite — 194 backend tests, 67 browser tests, PHPStan — passes against both.
- Removed the `references/laravel/ai` path repository from `composer.json`, and from the manual-testing setup script. A path repository is canonical and outranks Packagist, so the package's own development environment was pinned to a local checkout of `0.9.1` — which is why `0.10` went unnoticed. `references/` is reading material, not a dependency source.

### Known limitation

`laravel/ai 0.10` adds tool approvals, which introduce a `tool-output-denied`
stream part. Synapse does not render it yet: a denied tool call will show as a
card that stays `pending`. Nothing else is affected, and this will be addressed
in a following release.

## v0.1.0

First public release. A development dashboard for AI agents built with the
Laravel AI SDK — discover your agents, chat with them in the browser, and
inspect every tool call, token, reasoning step and error.

See the [release notes](docs/releases/v0.1.0.md) for the full picture.

### Added

- **Agent discovery** — scans configured paths for classes implementing the SDK's `Agent` contract and lists them with provider, model and tools. No registration, no cache to clear; a class written after boot appears on refresh.
- **Chat playground** — token-by-token streaming, attachments (images, documents, audio), a per-message model selector, and full persistence across refreshes.
- **Inline tool inspection** — a card per call showing arguments, result, duration and status, opened the moment the call is announced. Provider-native tools are distinguished from your own, and failures are marked as failures.
- **Agent info panel** — resolved configuration, system prompt, tool schemas, generation options and middleware, as the SDK resolves them at invocation time.
- **History** — search across titles and message content, filters by agent, status and tools, date range, sort, pagination, rename, delete, and full replay of any conversation.
- **Error surfacing** — every failure becomes an inline card with the exception class, message, the provider's own response body and a collapsible stack trace. The playground stays usable.
- **Token counting** — prompt, completion and reasoning tokens per message, with a running conversation total.
- **Access control** — routes do not register in production without `SYNAPSE_ENABLED=true`, and outside `local` every route passes a `viewSynapse` gate published into the host app.
- **Retention** — `synapse:prune`, `synapse:clear`, and an optional daily scheduled prune.
- `php artisan about` reports Synapse's version, enabled state, path, discovered agent count and retention setting.

### Notes

- Assets ship compiled inside the package and are inlined at request time. There is no publish step, and `composer update` cannot leave stale assets behind.
- Streaming is verified on `php artisan serve`, nginx + PHP-FPM and FrankenPHP. **Laravel Octane is not supported**: its workers run under the CLI SAPI, where output cannot be flushed mid-request, so replies arrive complete rather than streaming. Synapse detects this and says so in the playground.
- Citations are not surfaced yet — see the release notes.
