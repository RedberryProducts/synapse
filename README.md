# Synapse

*See every connection your AI agents make.*

Synapse is a development dashboard for AI agents built with the [Laravel AI SDK](https://github.com/laravel/ai) (`laravel/ai`). Discover the agents in your app, chat with them in the browser, and inspect every tool call, token, reasoning step, and error.

> **Status:** early scaffold. See [GOAL.md](GOAL.md) for the full product documentation, [PRD.md](PRD.md) for the technical spec, and [SCAFFOLDING.md](SCAFFOLDING.md) for the build plan.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- `laravel/ai` 0.9.x

## Installation

```bash
composer require redberry/synapse --dev
php artisan synapse:install
```

Then visit `/synapse`.

## Development

```bash
composer install
npm install
npm run build      # compile assets into dist/
composer hooks     # enable the pre-commit hook (once)

composer check     # lint + static analysis + tests
composer test:e2e  # browser end-to-end tests (needs Playwright; not run in CI)
```

See **[DEV.md](DEV.md)** for the full development cycle and **[AGENTS.md](AGENTS.md)** for architecture and coding standards. Agents can invoke the **`laravel-package`** skill for package-development best practices.

## License

Synapse is open-sourced software licensed under the [MIT license](LICENSE.md).
