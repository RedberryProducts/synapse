# Changelog

All notable changes to `redberry/synapse` are documented here.

This project follows [Semantic Versioning](https://semver.org/). While the
version is below `1.0.0`, minor releases may contain breaking changes.

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
