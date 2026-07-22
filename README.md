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
composer test      # Pest test suite
```

## License

Synapse is open-sourced software licensed under the [MIT license](LICENSE.md).
