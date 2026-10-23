# Fresh Laravel AI 1.3 installation walkthrough

This is the execution record for the new app at `build/laravel-ai13-dx`, created on 2026-10-23 for DX/UX inspection. It is a separate, gitignored project, not the existing `testing-laravel-project`. Synapse is installed from this working branch via Composer's path repository; Laravel and the AI SDK come from Packagist.

## Starting point

From the Synapse checkout:

```bash
composer create-project laravel/laravel build/laravel-ai13-dx '^13.0' --no-interaction
cd build/laravel-ai13-dx
composer require laravel/ai:1.3.0 --no-interaction
```

Laravel created `.env`, generated an application key, created SQLite storage, and ran the standard users/cache/jobs migrations. The SDK is pinned to 1.3.0 to test the migration floor. No provider keys were copied from another app.

## Every step after installing Laravel AI

### 1. Inspect and publish SDK resources

```bash
php artisan list --raw
php artisan vendor:publish --provider='Laravel\Ai\AiServiceProvider' --no-interaction
php artisan migrate --no-interaction
php artisan make:agent --help
```

Publishing the whole provider created `config/ai.php`, the four SDK generator stubs, and the SDK's own conversation migration. That migration belongs to this host app; Synapse does not install or write those tables. Publishing only `--tag=ai-config` would be enough for agents that don't use SDK conversation storage. The broad publish here deliberately exercises coexistence with SDK tables.

### 2. Generate agents and a tool using SDK commands

```bash
php artisan make:agent ConciergeAgent --no-interaction
php artisan make:agent ExtractContactAgent --structured --no-interaction
php artisan make:tool CatalogSearch --no-interaction
```

The SDK generates files in `app/Ai/Agents` and `app/Ai/Tools`. Synapse's default discovery already includes `app/Ai/Agents`, so no discovery configuration is needed. The normal agent stub implements conversational memory and tools; the structured stub additionally defines an output schema. The generated tool's `handle()` must be implemented before use.

### 3. Add the optional MCP integration dependency

```bash
composer require 'laravel/mcp:^1.0' --dev --no-interaction
```

This is for integration QA of real MCP 1.x classes. It is not required by Synapse and is not added to the package's production dependencies.

### 4. Implement the demo agents before installing Synapse

Edited the generated files and added these application-owned examples:

| Agent | Purpose |
|---|---|
| `ConciergeAgent` | Conversation history; reports how many user messages are in context |
| `StatelessAgent` | Independent single-turn context |
| `CatalogAgent` | Real two-second `CatalogSearch` tool with arguments and JSON results |
| `SkillsAgent` | `HasSkills` without `HasTools`; SDK-injected `LoadSkill` |
| `ExtractContactAgent` | Structured name/email JSON output |
| `ApprovalAgent` | Approvable catalog tool; must pause without execution |
| `FailureAgent` | Real throwing `BrokenLedger` tool and inline error |
| `DelegatingAgent` | SDK `AgentTool` wrapping `StatelessAgent` |
| `McpInventoryAgent` | Real local MCP 1.x server tool |
| `ExpenditureDetailsRelatedNotesAnalyzer` | Available long-name truncation example |
| `IncomeProtectionRecommendationDataCollectorBrokenAgent` | Unresolvable constructor and unavailable long-name truncation example |

Implementation files: `app/Ai/Agents/*.php`, `app/Ai/Tools/{CatalogSearch,ApprovalCatalog,BrokenLedger}.php`, and `app/Mcp/Tools/InventoryLookup.php`. The two generated agent stubs were edited to define their actual behavior; additional agents were written directly as PHP classes.

Added `app/Preview/Responses.php`, `app/Preview/Scenario.php`, and `config/preview.php`. These provide local-only, deterministic SDK step middleware with reasoning, streamed text, tool calls, and sample usage. Each response explicitly says it is a local demo. It waits 600 ms before the first model event and streams small chunks so loading is observable. `CatalogSearch` really waits two seconds; the failure tool really throws; the approval tool really never executes. No fake history rows are inserted.

`SYNAPSE_PREVIEW` defaults to true in this app's `config/preview.php`. Setting it to false passes through to the real provider; real credentials would then be required. This preview infrastructure belongs only to the sample app and is not part of installing Synapse.

Formatted the new application files:

```bash
./vendor/bin/pint app/Ai app/Preview app/Mcp config/preview.php
```

### 5. Install this Synapse branch

```bash
composer config repositories.synapse '{"type":"path","url":"../..","options":{"symlink":true}}'
composer require --dev 'redberry/synapse:@dev' --no-interaction
php artisan synapse:install --no-interaction
```

The path repository points two levels up to the Synapse checkout. This tests the working tree; a normal released install only needs `composer require --dev redberry/synapse`.

The installer published config, five self-contained migrations, and the application gate provider. It added the guarded local registration in `AppServiceProvider::register()`. It ran all five migrations successfully, alongside the host app and SDK tables. No frontend install, npm build, asset publishing, manual provider registration, or discovery-path change was necessary.

### 6. Verify installer idempotency

```bash
shasum app/Providers/AppServiceProvider.php app/Providers/SynapseServiceProvider.php bootstrap/providers.php
php artisan synapse:install --no-interaction
shasum app/Providers/AppServiceProvider.php app/Providers/SynapseServiceProvider.php bootstrap/providers.php
```

Before/after hashes matched. The second install reported “Nothing to migrate” and did not duplicate provider registration.

### 7. Verify optional MCP classes

Added `bin/verify-mcp.php`, which boots the actual app and checks both raw and SDK-wrapped MCP server/client tools through Synapse's classifier and detail projection. The client primitive has no transport; it is inspected, never called. The script verifies classification, names, required `sku` parameters and absence of schema errors.

```bash
php bin/verify-mcp.php
```

All four variants passed. Added `Http::preventStrayRequests()` in `AppServiceProvider::boot()`, guarded by local environment and the preview setting, to ensure the preview cannot accidentally contact a provider.

### 8. Reproduce the production deployment path for issues #9 and #10

```bash
APP_ENV=production composer install --no-dev --no-interaction
php -r 'require "vendor/autoload.php"; if (class_exists(Redberry\Synapse\SynapseServiceProvider::class)) { exit(1); } echo "Synapse absent from production autoload\n";'
APP_ENV=production php artisan about --only=environment
touch database/production-check.sqlite
APP_ENV=production DB_DATABASE=/Users/revazgh/Projects/Synapse/build/laravel-ai13-dx/database/production-check.sqlite php artisan config:cache
php artisan migrate --force --no-interaction
php artisan route:list --path=synapse --json
php artisan config:clear
composer install --no-interaction
php artisan config:clear
```

Synapse was absent from the production autoloader. Laravel booted and cached its configuration successfully. All application, SDK, and **five Synapse migrations** ran from scratch on the separate `production-check.sqlite` database while Synapse was absent. The Synapse route list was empty. Dev dependencies were then restored and config cache cleared for the local preview. The normal app database was preserved throughout.

### 9. Start the preview

```bash
php artisan serve --host=127.0.0.1 --port=8013 --no-reload
```

Dashboard: **http://127.0.0.1:8013/synapse**. Server output is captured at `/tmp/synapse-step7-preview-server.log`. The server is intentionally left running for inspection. This is the normal Laravel server using the installed package and published config; no special routing or authorization bypass is used.

### 10. Exercise and correct the preview fixtures

Opened the dashboard in the browser and sent real requests through the installed package. This found three mistakes in the application-owned preview middleware:

1. Changed its entry point from `__invoke()` to `handle()`, which is what the SDK invokes. The initial mistake appeared as Synapse's inline error card.
2. Added explicit streamed `ToolCall` events as well as returning calls in `StepResponse`, so the local stream accurately represents the model/tool sequence.
3. Compared message roles with `MessageRole::User` instead of the string `user`, correcting the demo's context counter.

These were preview fixture corrections, not changes to Synapse. Added `bin/check-preview.py`, which obtains a real session/CSRF token, submits messages over HTTP, parses SSE, and reloads each saved conversation:

```bash
python3 bin/check-preview.py
```

All nine scenarios passed: conversational and stateless history, ordered text/tool replay, skills, structured output, approval pause without execution, throwing tools, delegated agents, and MCP execution. Each stream had one terminator. The catalog transcript retained `reasoning → text → tool → reasoning → text` in that order.

Added and ran `bin/cleanup-preview-fixtures.php` to remove the two scratch conversations from the faulty fixtures through `ConversationRepository`'s cascade. It also checked that both SDK conversation tables still contain zero rows. Nine valid sample conversations remain for inspection.

```bash
php bin/cleanup-preview-fixtures.php
./vendor/bin/pint app/Ai app/Preview app/Mcp app/Providers config/preview.php bin/verify-mcp.php bin/cleanup-preview-fixtures.php
```

Inspected the browser's discovery page (11 agents), visible loading placeholder, catalog tool arguments/results, history reload, and required tool parameters in the Info panel. Saved a screenshot as `build/laravel-ai13-dx/preview.jpg` relative to the Synapse checkout. The browser and server remain open.

### Versions and DX findings

Verified installed versions through Composer's `InstalledVersions`: Laravel **13.38.0**, Laravel AI **1.3.0**, Laravel MCP **1.1.1**, and Synapse **dev-codex/laravel-ai-13-migration**, running on PHP **8.4**.

- Synapse installation itself needed only Composer installation and `synapse:install`. The longer setup above creates the demonstration and exercises integration boundaries.
- SDK-generated agent paths work out of the box. Skills-only agents now show `LoadSkill`; SDK-wrapped sub-agents and MCP tools retain their proper labels/details.
- A deliberately unresolvable agent remains visible with an actionable diagnostic instead of breaking discovery.
- Approval controls are still a separate feature: `ApprovalAgent` demonstrates a safe pause and an explicit unsupported-approval notice. Structured agents retain the existing single-shot JSON flow.
- No real provider compatibility or response quality is claimed by deterministic preview responses. Step 8 still owns the supported runtime/dependency release matrix.

### Inspect or restart

Open **http://127.0.0.1:8013/synapse**. Try `CatalogAgent` with “Find a hoodie”, `ConciergeAgent` for two turns, `SkillsAgent` with “Use the catalog skill”, or `ExtractContactAgent` with “Ada, ada@example.com”. Existing conversations also demonstrate approval and failure states. The deliberately unavailable long-name agent is expected.

If the server stops, restart from the fresh app:

```bash
cd /Users/revazgh/Projects/Synapse/build/laravel-ai13-dx
php artisan serve --host=127.0.0.1 --port=8013 --no-reload
```

Keep this app's local preview enabled for deterministic responses without API spend. Because Synapse is symlinked, edits in the package checkout are reflected in this app; frontend edits still require rebuilding the package's `dist/`.

### Step 8 follow-up checks

Rebuilt the package assets and ran `bin/check-streaming.sh http://127.0.0.1:8013 app.ai.agents.catalog-agent` from the package checkout. Also started a temporary PHP 8.4.25 server from this app's `public/` directory with `output_buffering=4096` on port 8015, ran the same script against that port, and checked that the latest assistant/tool records were completed/successful. Both passed. The first temporary-server attempt used the wrong working directory; it was corrected before obtaining valid timings.

Stopped the temporary 8015 server afterward. The original 8013 server and additional successful slow-tool conversations remain available. No app dependencies, provider keys, or demo settings changed. The separate PHP 8.3/Laravel 12 installation and full matrix are recorded in [QUALIFICATION.md](QUALIFICATION.md).
