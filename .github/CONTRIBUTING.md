# Contributing to Synapse

Code and documentation contributions are welcome. For a substantial change, [open an issue](https://github.com/RedberryProducts/synapse/issues) first to discuss the problem and approach. Keep pull requests focused and include reproduction or verification steps.

Read the [repository guide](../AGENTS.md) for architecture and coding standards, and [DEV.md](../DEV.md) for the maintained development workflow.

## Set up

The development environment requires PHP 8.3 or later, Composer 2, and Node 20 or later. GD, SQLite, and the normal Laravel/Pest extensions are required for the test suite. Playwright and Chromium are needed for browser tests.

```bash
composer install
npm ci
npm run build
composer hooks
```

## Validate your change

```bash
composer check
```

This runs Pint, PHPStan, and the unit and feature suites. Frontend changes also require rebuilding and committing `dist/`. Run browser tests when changing frontend or chat/stream behavior, and before a release:

```bash
composer test:e2e
```

Run feature and browser suites sequentially within one checkout. Follow [DEV.md](../DEV.md) for browser prerequisites and streaming checks. Test fixtures should use fake providers rather than spending API credits.

## Open a pull request

Explain the problem, the change, any linked issue, and the checks you actually ran. Include screenshots for UI changes and document installation, configuration, or migration effects when relevant. Keep credentials, private prompts, and customer data out of examples and logs.

Synapse is built and maintained by [Redberry](https://redberry.international/), an official [Laravel Premier Partner](https://laravel.com/partners/redberry).
