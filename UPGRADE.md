# Upgrading to the Laravel AI 1.3 migration

This guide describes the planned **0.2.0 breaking release**. The migration branch is not tagged or published yet. Deterministic qualification is recorded in [QUALIFICATION.md](plans/laravel-ai-1.3-migration/QUALIFICATION.md); live OpenAI results are recorded in [LIVE-PROVIDER-TEST.md](plans/laravel-ai-1.3-migration/LIVE-PROVIDER-TEST.md).

## Requirements

- PHP 8.3 or later.
- Laravel 12.62+ or 13.15+ for API compatibility. Use a current patched framework release for deployment; the historical minimums precede [Laravel's TokenGuard fix](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6), released in 12.68.0 / 13.29.0.
- `laravel/ai` ^1.3. Follow the SDK's [1.3 upgrade guide](https://github.com/laravel/ai/blob/v1.3.0/UPGRADE.md) for your application's agents, tools, middleware, and SDK-owned storage.

If your application uses `laravel/mcp`, it must resolve to **1.1.0 or later**. Synapse rejects older versions to enforce the patch for [GHSA-m25c-38v7-94p8](https://github.com/laravel/mcp/security/advisories/GHSA-m25c-38v7-94p8), an MCP client OAuth discovery SSRF/credential-exposure issue. MCP remains optional. The advisory also lists 0.9.7 as patched, but SDK 1.3 requires MCP 1.x when installed. Include `laravel/mcp` in your Composer update command if your lockfile still has 1.0.x.

Synapse's migrations affect only `synapse_*` tables. They do not upgrade or clear the SDK's `agent_conversations` or `agent_conversation_messages` tables.

## Existing installations

There is no history backfill or legacy-format reader. This upgrade requires explicitly discarding Synapse history and uploaded attachments. Back up anything you want to keep before proceeding.

1. Stop requests and jobs that can write to Synapse. Keep them stopped until verification is complete.
2. **Before changing dependencies**, run `php artisan synapse:clear` using the existing installation and its existing storage connection and attachment disk. This removes Synapse history and its files, not application/SDK conversation data.
3. Once the release is available, change your Composer requirements to `redberry/synapse:^0.2` in `require-dev` and `laravel/ai:^1.3` in `require`. Upgrade the framework if necessary, then resolve the changes with Composer. For example:

   ```bash
   composer require --dev 'redberry/synapse:^0.2' --no-update
   composer require 'laravel/ai:^1.3' --no-update
   composer update redberry/synapse laravel/ai --with-all-dependencies
   ```

4. Publish the follow-up migrations and run them:

   ```bash
   php artisan vendor:publish --tag=synapse-migrations
   php artisan migrate
   ```

5. If the previous installer registered Synapse unconditionally in `bootstrap/providers.php`, re-run `php artisan synapse:install` locally to migrate that registration. Preserve your custom gate. For older migrations extending `SynapseMigration`, follow the additional package-removal instructions in [README.md](README.md#installation) to refresh those files while the package remains installed.
6. Open `/synapse`, inspect discovery and agent settings, then verify chat, tools, and reload before resuming traffic. If you cache configuration, rebuild that cache using your normal deployment procedure.

The format migration refuses nonempty Synapse tables without modifying their data. If it refuses, restore the old package version before using its clear command; do not run the new clear command against an incompatible schema. If an earlier damaged installation left orphan rows, back them up and explicitly remove only those Synapse rows on the configured connection before retrying. **Never use `migrate:fresh` on the host application.**

Rollback also requires empty Synapse storage. Restore the matching dependency/schema pair from your backup if you need to recover older history.

## Fresh installations and production deployments

Fresh installs use `composer require --dev redberry/synapse` followed by `php artisan synapse:install` after the release is available. All five migrations run in order. No npm build or asset publication is needed in the host app.

The published provider and migrations remain loadable after `composer install --no-dev` removes Synapse. This path was exercised in real Laravel 12 and 13 applications; see the qualification report.

## Changed contracts

- Input/output usage replaces prompt/completion naming. Input includes cache reads/writes; output includes reasoning. These breakdowns are subsets, and unavailable breakdowns remain null.
- Assistant rows store ordered SDK steps privately and an ordered display transcript for replay. The public API exposes display data rather than raw steps, replay blocks, or provider-specific call metadata.
- Tool observations distinguish SDK execution IDs from model call IDs and retain their own provider. Partial output and successful tool work remain visible before an inline failure.
- Tool approval requests pause safely without executing the tool. Approve/deny/resume controls remain separate work in issue #1.
- Structured-output agents retain single-shot execution without earlier conversation history. SDK 1.3.0 does not support streaming that path.

Agents remain application-owned. Synapse forwards the SDK's middleware, skills, cache options, and generation settings, and keeps conversational/stateless behavior unchanged.
