# Upgrading Synapse

## Upgrading from 0.2.0 to 0.3.0

Version 0.3.0 adds tool approvals, cited sources, completion explanations, and a per-response step inspector. PHP, Laravel, Laravel AI, and optional MCP requirements are unchanged; see [README.md](README.md#requirements).

**This upgrade preserves existing Synapse history and attachments. Do not run `synapse:clear`.** The two new migrations only add nullable columns to `synapse_messages`; SDK conversation tables are untouched.

Once v0.3.0 is published:

1. Back up the application's database and pause access to Synapse while updating and migrating.
2. Update the Composer constraint explicitly: `^0.2` does not select `0.3.0`.

   ```bash
   composer require --dev 'redberry/synapse:^0.3' --with-all-dependencies
   ```

3. Publish the new migrations without overwriting existing files, then run them before opening the dashboard:

   ```bash
   php artisan vendor:publish --tag=synapse-migrations
   php artisan migrate
   php artisan migrate:status
   ```

   Confirm these migrations have run on the configured Synapse storage connection:

   - `2026_10_27_000001_add_tool_approvals_to_synapse_messages`
   - `2026_10_29_000001_add_step_observations_to_synapse_messages`

   If you already ran either migration while testing the feature branches, Laravel skips it. There are seven Synapse migrations in total.

4. Rebuild cached configuration if your deployment uses it, then reopen `/synapse`. Assets ship compiled and are inlined from the package; no npm build or asset publication is needed in the host app.
5. Verify an existing conversation still opens, a new tool approval can be approved/denied and resumed after reload, and a new response's Steps inspector expands. For a citation-capable agent, verify Sources survive reload. Ordinary completions should not gain a warning notice.

### Existing history and limits

- Conversations and uploaded attachments are retained. New approval checkpoints support resumption; old 0.2.0 paused turns lack those checkpoints and require a new turn.
- Older saved steps remain inspectable, but unavailable model-call timing and completion evidence are not guessed. Citations and completion explanations appear in old history only when the needed data was retained.
- Provider-hosted tool approvals and approval requests originating inside a child agent are outside this integration. Approval before delegating to a child agent is supported.
- Structured responses remain single-shot. The inspector is read-only and does not execute tools or rerun agents.

For rollback, stop Synapse traffic and restore the matching dependency/schema backup if needed. Rolling back the two new migrations removes approval checkpoints and step observations; it does not restore resumability to 0.2.0. Avoid rolling back unrelated application migrations.

See the [v0.3.0 release notes](docs/releases/v0.3.0.md) for the feature overview.

## Upgrading from 0.1.x

The **0.2.0 Laravel AI 1.3 migration**, published on 2026-10-26, changed the storage format. Only this older upgrade requires clearing Synapse history and attachments before changing dependencies. Follow the legacy procedure below to reach 0.2.0, verify it, then follow the additive 0.3.0 procedure above. Do not apply the legacy reset to an existing 0.2.0 installation.

Historical evidence: [0.2.0 release notes](docs/releases/v0.2.0.md) and the merged [migration PR](https://github.com/RedberryProducts/synapse/pull/17).

## Legacy migration requirements

- PHP 8.3 or later.
- Laravel 12.62+ or 13.15+ for API compatibility. Use a current patched framework release for deployment; the historical minimums precede [Laravel's TokenGuard fix](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6), released in 12.68.0 / 13.29.0.
- `laravel/ai` ^1.3. Follow the SDK's [1.3 upgrade guide](https://github.com/laravel/ai/blob/v1.3.0/UPGRADE.md) for your application's agents, tools, middleware, and SDK-owned storage.

If your application uses `laravel/mcp`, it must resolve to **1.1.0 or later**. Synapse rejects older versions to enforce the patch for [GHSA-m25c-38v7-94p8](https://github.com/laravel/mcp/security/advisories/GHSA-m25c-38v7-94p8), an MCP client OAuth discovery SSRF/credential-exposure issue. MCP remains optional. The advisory also lists 0.9.7 as patched, but SDK 1.3 requires MCP 1.x when installed. Include `laravel/mcp` in your Composer update command if your lockfile still has 1.0.x.

Synapse's migrations affect only `synapse_*` tables. They do not upgrade or clear the SDK's `agent_conversations` or `agent_conversation_messages` tables.

## Legacy 0.1.x storage migration

There is no history backfill or legacy-format reader. This upgrade requires explicitly discarding Synapse history and uploaded attachments. Back up anything you want to keep before proceeding.

1. Stop requests and jobs that can write to Synapse. Keep them stopped until verification is complete.
2. **Before changing dependencies**, run `php artisan synapse:clear` using the existing installation and its existing storage connection and attachment disk. This removes Synapse history and its files, not application/SDK conversation data.
3. Change your Composer requirements to `redberry/synapse:^0.2` in `require-dev` and `laravel/ai:^1.3` in `require`. Upgrade the framework if necessary, then resolve the changes with Composer. For example:

   ```bash
   composer require --dev 'redberry/synapse:^0.2' --no-update
   composer require 'laravel/ai:^1.3' --no-update
   composer update redberry/synapse laravel/ai --with-all-dependencies
   ```

   If MCP is installed and still locked below 1.1.0, include it explicitly:

   ```bash
   composer update redberry/synapse laravel/ai laravel/mcp --with-all-dependencies
   ```

   Update any exact MCP pin that excludes 1.1.0 first. Complete the SDK's own agent/tool/middleware and storage migration as described in its linked upgrade guide; Synapse's migrations do not perform those application changes.

4. Publish the follow-up migrations and run them:

   ```bash
   php artisan vendor:publish --tag=synapse-migrations
   php artisan migrate
   ```

5. If the previous installer registered Synapse unconditionally in `bootstrap/providers.php`, re-run `php artisan synapse:install` locally to migrate that registration. Preserve your custom gate. For older migrations extending `SynapseMigration`, follow the additional package-removal instructions in [README.md](README.md#installation) to refresh those files while the package remains installed.
6. Open `/synapse`, inspect discovery and agent settings, then verify chat, tools, and reload before resuming traffic. If you cache configuration, rebuild that cache using your normal deployment procedure.

The format migration refuses nonempty Synapse tables without modifying their data. If it refuses, restore the old package version before using its clear command; do not run the new clear command against an incompatible schema. If an earlier damaged installation left orphan rows, back them up and explicitly remove only those Synapse rows on the configured connection before retrying. **Never use `migrate:fresh` on the host application.**

Rollback also requires empty Synapse storage. Restore the matching dependency/schema pair from your backup if you need to recover older history.

## Verify the legacy migration to 0.2.0

- Run `composer audit` and `php artisan migrate:status`; confirm the five Synapse migrations have run on the intended connection.
- Open `/synapse`, confirm your agents resolve, and inspect their tool/provider settings.
- Send two messages to a conversational agent, then reload and confirm its earlier context, tool results, and usage remain visible. Confirm stateless agents still receive only the current turn.
- Exercise a real tool and a structured-output agent if your application uses them. Structured output is single-shot.
- Check a tool failure followed by a new message. Approval tools should pause without execution; 0.2.0 does not add approve/resume controls; 0.3.0 does.
- Before a production `--no-dev` deployment, verify the guarded provider registration and self-contained published migrations described above. Preserve your application-owned access gate.

## Fresh installations and production deployments

A fresh 0.2.0 installation runs five migrations. A fresh 0.3.0 installation runs seven; see the current installation instructions in [README.md](README.md#installation). No npm build or asset publication is needed in the host app.

The published provider and migrations remain loadable after `composer install --no-dev` removes Synapse. This path was exercised in real Laravel 12 and 13 applications; see the [release-preparation PR](https://github.com/RedberryProducts/synapse/pull/27).

## Contracts changed in 0.2.0

- Input/output usage replaces prompt/completion naming. Input includes cache reads/writes; output includes reasoning. These breakdowns are subsets, and unavailable breakdowns remain null.
- Assistant rows store ordered SDK steps privately and an ordered display transcript for replay. The public API exposes display data rather than raw steps, replay blocks, or provider-specific call metadata.
- Tool observations distinguish SDK execution IDs from model call IDs and retain their own provider. Partial output and successful tool work remain visible before an inline failure.
- Tool approval requests pause safely without executing the tool. Approve/deny/resume controls were added in 0.3.0.
- Structured-output agents retain single-shot execution without earlier conversation history. SDK 1.3.0 does not support streaming that path.

Agents remain application-owned. Synapse forwards the SDK's middleware, skills, cache options, and generation settings, and keeps conversational/stateless behavior unchanged.
