# Live OpenAI browser qualification — 2026-10-23

Tested the installed package in the visible Codex browser at `http://127.0.0.1:8013/synapse`, using the user's authorized OpenAI credential and **gpt-4.1-mini**. Laravel 13.38.0 / Laravel AI 1.3.0 / Laravel MCP 1.1.1; package branch `codex/laravel-ai-13-migration`.

All ten callable agents completed their intended live scenario. The eleventh is deliberately unconstructable. The two UI gaps found during the original session were subsequently fixed and rechecked below. The original test session changed no package implementation; the follow-up adds the fixes and browser regressions.

## Setup performed

1. Stored the provided credential only in the ignored fresh application's `.env`, with mode `0600`. No credential is included in this report or tracked files.
2. Set `SYNAPSE_PREVIEW=false`. The application's preview middleware now delegates to the real SDK/provider; its preview-only stray-request guard is inactive.
3. Set the application's `ai.providers.openai.models.text.default` to `gpt-4.1-mini` for this smoke test.
4. Ran `php artisan config:clear` from `build/laravel-ai13-dx`. An initial attempt from the package root had no `artisan` file and made no change.
5. Kept the existing Laravel server on port 8013 running. Opened a visible browser and submitted prompts through the Synapse composer, inspected tool cards, reloaded pages, and navigated History.

Model responses and tool selection were real OpenAI calls. Catalog, ledger, and inventory tools remain application-owned test fixtures: they do not contact real business systems. MCP coverage is an in-process Laravel MCP server tool, not a remote MCP transport.

## Agent results

| Agent | Browser action and observed result | Result |
|---|---|---|
| ConciergeAgent | Supplied fictional codename `Cedar-482`, reloaded, then asked for it; response was exactly `Cedar-482`. Loading, reply, and usage rendered. | Passed |
| StatelessAgent | Supplied a fictional passphrase, then requested the previous turn's passphrase with `NO_CONTEXT` fallback; response was `NO_CONTEXT`. | Passed |
| CatalogAgent | Requested `CatalogSearch(query: hoodie)`; expanded real tool arguments/result: Redberry Hoodie, HOODIE-01, stock 12, approximately 2 seconds execution. Reload retained tool-before-answer order. Follow-up without tools returned `12`. | Passed |
| SkillsAgent | Requested loading `catalog`; `LoadSkill` returned its actual skill instructions and the model stated the stock-checking rule. | Passed |
| ExtractContactAgent | Extracted fictional Ada Lovelace / ada@example.com with `demo: false`; structured JSON and 108 tokens survived reload. | Passed |
| ApprovalAgent | Requested `ApprovalCatalog(query: hoodie)`; paused tool card and explicit unsupported-approval notice appeared, loading stopped. History reopened the same paused turn. DB observation has no execution ID and no result. | Safe pause passed; live usage gap below |
| FailureAgent | Requested `BrokenLedger`; error tool card and one inline RuntimeException appeared. A subsequent no-tool request returned `RECOVERED`. Both survived reopening from History. | Error/recovery passed; live usage gap below |
| DelegatingAgent | Requested StatelessAgent calculate 17 × 19. Expanded tool showed a self-contained task and actual child result `323`; parent replied with `323`. | Passed |
| McpInventoryAgent | Requested `inventory_lookup(sku: HOODIE-01)`; expanded result was `HOODIE-01: 12 in stock.` Info → Tools showed MCP classification and required string `sku`. | Passed |
| ExpenditureDetailsRelatedNotesAnalyzer | Requested total of fictional train 20 and lunch 15; response totaled 35. Header/sidebar layout remained usable. | Passed |
| IncomeProtectionRecommendationDataCollectorBrokenAgent | Discovery reported the required string `$accountId` cannot be autowired, offered a concrete remedy, and omitted the playground action. Sidebar still opens its route; sending remains disabled. | Invocation blocked as intended; diagnostic gap below |

History displayed all ten live conversations, searched by `Live approval test`, and reopened the paused result. Approval usage was 109 tokens; failed turn usage was 85 and recovery 136, giving 221 total. These persisted values were independently confirmed read-only in SQLite. Both `agent_conversations` and `agent_conversation_messages` remained empty.

## Original findings (fixed in the follow-up below)

1. **Paused/failed live usage is stale until reopening.** Approval initially showed total 0 with no per-turn usage; reopening showed 91 input + 18 output = 109. The intentional failure initially omitted its 73 input + 12 output; after recovery the live header showed only the recovery's 136, whereas reopening showed 221. Persistence is correct. Reproduce with ApprovalAgent or FailureAgent, compare the immediate transcript with History/reload, and add browser assertions for those terminal paths when fixing reconciliation.
2. **Unavailable-agent sidebar route loses its diagnostic.** Discovery explains the constructor error correctly, but its sidebar link opens an empty playground with a misleading stateless notice, an editable composer, and disabled Send. The route should present the same actionable discovery diagnostic. This test did not invoke the unavailable agent or incur a model call for it.

One automation input attempt typed an incomplete history search (`Lvapvtt`). Setting the search field atomically to the full phrase worked. This is not claimed as a confirmed product defect without reproducing normal keyboard input.

## Attachment and model-accuracy check

Created a synthetic 64×64 RGB (0, 0, 255) PNG in the ignored app, uploaded it through Attach → Image, and sent it to ConciergeAgent. The chip and persisted attachment link rendered; original and stored file SHA-256 matched (`fb59293297bbed4792e45b1e8a353c63f942d86ac3b235a8c5c38b81ab75e09a`). Reload preserved the attachment and response.

The unconstrained color answer was **Turquoise**, which is incorrect for this fixture. After reload, a follow-up asking for the closest primary RGB color returned **Blue**. Upload/persistence and multi-turn request completion passed; exact visual-answer accuracy is mixed, not a clean semantic vision pass. No audio/PDF provider flow was tested.

## Evidence and limits

Saved transcripts in the fresh app:

| Scenario | Conversation ID |
|---|---|
| Concierge / attachment | `01a168a2-479c-720b-a6fc-ff0a4c308014` |
| Stateless | `01a168a3-756a-70a1-bf5d-0198fd298708` |
| Catalog | `01a168a4-638f-70bb-80e7-fd45ecc79305` |
| Skills | `01a168a5-7551-713b-84b3-792c79a65985` |
| Structured | `01a168a5-ff91-71cc-9a71-3e0ab4d82d8f` |
| Approval | `01a168a6-79ad-715a-b2c8-0dc64944d3e7` |
| Failure / recovery | `01a168a6-fbd2-70b4-a6a8-80d61cd95188` |
| Delegation | `01a168a7-968e-72cb-8410-1f641eec92e0` |
| MCP | `01a168a9-caa3-71f0-ad7f-81d80e47998b` |
| Long name | `01a168aa-2793-7137-ba63-2407272bf241` |

Screenshots are local, ignored artifacts: `build/laravel-ai13-dx/live-catalog.jpg` and `build/laravel-ai13-dx/live-failure-recovery.jpg`.

Post-session `composer check` passed: Pint, PHPStan, **289 tests / 916 assertions**. The first sandboxed run could not bind the local port required by Pest's browser plugin; the rerun with local port access passed. Log: `/tmp/synapse-live-provider-check.log`. `git diff --check` also passed. Automated browser tests remain deterministic; this manual live-provider session does not replace their earlier 81-test qualification.

This qualifies the listed flows on one OpenAI model. Other providers, failover, reasoning-specific output, provider-hosted tools, audio/PDF attachments, and remote MCP transport remain untested with live credentials. Browser loading/response observations do not replace the earlier quantitative HTTP streaming checks. Tool approval resume remains outside scope (issue #1).

The preview remains **live**, with `SYNAPSE_PREVIEW=false`; additional prompts make real provider calls. To return to deterministic demonstrations, set it to `true` in the app's `.env` and run `php artisan config:clear` there. No release/tag was created.

## Fix and security follow-up — 2026-10-23

- Stream cleanup now retains entries with recorded usage, even when the assistant produced no text. Four new browser cases cover paused/failed live totals and subsequent sends, unavailable sidebar navigation, and readable saved history for an unavailable agent. All four failed against the previous compiled build and pass after the fixes.
- Unavailable playgrounds reuse the discovery diagnostic, hide the composer and misleading stateless/empty-chat state, and keep saved transcripts readable. The existing Info panel assertions now scope to the panel because the same diagnostic also appears in the playground; their content assertions are retained.
- Real OpenAI recheck: approval conversation `01a168bd-2e71-71e9-b04b-7285c3343675` displayed **85 input + 18 output = 103** immediately. Failed conversation `01a168bd-e64d-72f0-bd3e-6eab067a30a7` displayed **67 + 12 = 79**, then the no-tool recovery added **122 + 5 = 127**, yielding **206** without reload. The broken-agent sidebar page displayed its `$accountId` remedy and no composer.
- Evidence: `build/laravel-ai13-dx/fixed-live-usage.jpg` and `fixed-unavailable-agent.jpg`.

Reviewed [GHSA-m25c-38v7-94p8](https://github.com/laravel/mcp/security/advisories/GHSA-m25c-38v7-94p8). The MCP client OAuth discovery SSRF/credential-exposure issue is patched in **1.1.0** and **0.9.7**. SDK 1.3 already excludes MCP 0.x. Synapse now declares `conflict: {"laravel/mcp": "<1.1.0"}`: MCP remains optional, but installed versions must meet the patched 1.x floor.

Composer dry runs in the fresh app proved that **1.0.0 is rejected by Synapse's conflict** even with advisory blocking disabled for that no-install check, while **1.1.0 resolves** with normal security blocking. No unsafe version was installed and no persistent audit exception was added. Refreshed only the app's Synapse lock entry; installed MCP remains **1.1.1**, and Composer reports no security advisories. This verifies dependency resolution at 1.1.0; live MCP execution was tested on 1.1.1.

Final validation: `composer validate --strict`, `composer check` (**289 tests / 916 assertions**, Pint and PHPStan), `npx tsc --noEmit`, rebuilt `dist/`, `composer test:e2e` (**85 tests / 344 assertions**), and `git diff --check` passed. The first full browser run exposed the now-corrected ambiguous Info panel selector; the complete rerun passed. The existing build chunk-size warning remains.

Logs: `/tmp/synapse-live-fixes-{check,browser,build,lock,app-update}.log`, `/tmp/synapse-mcp-{vulnerable,patched}-resolution.log`. No release or tag was created.
