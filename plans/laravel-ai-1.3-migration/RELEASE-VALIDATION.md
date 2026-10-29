# 0.2.0 release validation — 2026-10-26

**Result: the tested release gates pass; no new blocker was found.** Implementation candidate: `6261a5851dd5b7f1a3ff5244f99099cba87bac15`, PR [#17](https://github.com/RedberryProducts/synapse/pull/17). This session adds release documentation only. No merge, tag, or release was performed. GitHub returned no workflow runs or combined statuses for this SHA, so this report claims local validation, not a green hosted CI run.

## Automated gates

`composer validate --strict`, `composer check`, `npx tsc --noEmit`, and `npm run build` passed in the primary checkout. Pint and PHPStan passed with **290 PHP tests / 932 assertions**. The existing >500 kB build warning remains. The build reproduced the committed assets without changes.

The eight existing isolated compatibility copies were refreshed with the candidate's tracked source, tests, resources, and compiled assets. Their dependency lockfiles were preserved; these are the resolved versions from the earlier qualification, not a claim that each framework version is still newest today. Each row passed `composer check` with **290 tests / 932 assertions**:

| PHP | Laravel | Testbench | SDK |
|---|---|---|---|
| 8.3.36 | 12.62.0 | 10.11.0 | 1.3.0 |
| 8.3.36 | 12.70.1 | 10.12.0 | 1.3.0 |
| 8.3.36 | 13.15.0 | 11.1.0 | 1.3.0 |
| 8.3.36 | 13.38.0 | 11.3.0 | 1.3.0 |
| 8.4.15 | 12.62.0 | 10.11.0 | 1.3.0 |
| 8.4.15 | 12.70.1 | 10.12.0 | 1.3.0 |
| 8.4.15 | 13.15.0 | 11.1.0 | 1.3.0 |
| 8.4.15 | 13.38.0 | 11.3.0 | 1.3.0 |

PHP 8.3 used the existing `synapse-qualification:php83-gd` image with Docker networking disabled. PHP 8.4 used the Homebrew runtime. The primary checkout uses Herd PHP 8.4.25 / Laravel 13.32.0. Composer metadata confirmed SDK **1.3.0** is still the latest stable SDK, matching the tested floor.

`composer test:e2e` passed **86 tests / 353 assertions** in the primary checkout and in both PHP 8.4 copies with Laravel 12.70.1 and 13.38.0. Feature and browser suites ran sequentially in each checkout. All automated browser agents were faked. The retained tests cover closed production issues [#7–#10](REGRESSION-AUDIT.md), the live-session UI fixes, and the Stringable result regression.

Package and live-app `composer audit --locked` reported **no security vulnerability advisories**. Optional MCP remains constrained to `>=1.1.0`; the installed live app uses 1.1.1. Historical framework-floor copies retain only their previously documented TokenGuard advisory exception for API compatibility testing. They are not recommended deployment versions; no exception was added to the package or live app.

## Live OpenAI smoke

Used the already configured credential in the ignored `build/laravel-ai13-dx` app. No credential was copied into tracked files. Confirmed `SYNAPSE_PREVIEW=false`, Laravel 13.38.0, SDK 1.3.0, MCP 1.1.1, and OpenAI's configured `gpt-4.1-mini`; responses identify `gpt-4.1-mini-2025-04-14`.

Restarted the local server on port **8013** with `output_buffering=4096`. A CSRF-authenticated HTTP harness sent 14 live turns across all ten callable agents. It checked one stream terminator per turn, expected completion status, positive input/output usage, provider/model identity, and exact agreement between stream completion and reloaded message/tool projections. Discovery returned all 11 agents and the expected `$accountId` constructor diagnostic for the unavailable fixture.

| Scenario | Observed result | Conversation ID |
|---|---|---|
| Conversational history | Recalled `Maple-739` after a separate reload request | `01a178bb-2a46-72ed-a795-77b82cb8b1d8` |
| Stateless history | Returned `NO_CONTEXT` for the previous passphrase | `01a178bb-3d19-7084-8705-c5e62de40e10` |
| Catalog tool + follow-up | One successful tool, stock 12, 2007 ms duration; follow-up recalled 12 without another tool | `01a178bb-48d0-70ff-b7cd-c4e01ceba1cb` |
| Skills | `LoadSkill` returned the actual catalog rule; model stated it correctly | `01a178bb-5f64-72d0-8348-9d163193e4fc` |
| Structured output | Ada Lovelace / ada@example.com / demo false | `01a178bb-6d38-729b-8344-5205aa88479d` |
| Approval | Paused with no execution ID or result; 95 tokens recorded | `01a178bb-7233-7152-a3ad-5da32b73549a` |
| Failure + recovery | Intentional tool error, then `RECOVERED` without tools | `01a178bb-76c1-7284-97ab-f1ff2c5cc4a3` |
| Delegation | Real child-agent call returned 323 | `01a178bb-8160-72a8-8e25-437c9755ede2` |
| Local MCP | `inventory_lookup` returned `HOODIE-01: 12 in stock.` | `01a178bb-912d-737d-94b4-4f5ea21601f4` |
| Long-name agent | Returned expense total 35 | `01a178bb-9a02-7208-8a55-cb4b5369b084` |

Browser inspection confirmed the catalog's one card, arguments, stock result, timing, follow-up, and total **439** tokens. A separate message submitted through the browser composer reached a safe approval pause and displayed **79 input + 18 output = 97** immediately, without reload. Conversation: `01a178bc-945d-7120-bc7a-27ef64e1685a`. Read-only SQLite checks confirmed both fresh approval observations have no execution ID/result, and both SDK conversation tables still contain zero rows.

Actual network timings for the catalog turn: first SSE data **8 ms**, tool input **1485 ms**, tool output **3647 ms**, stream completion **4763 ms**. The harness's total including the reload request was **4775 ms**. This verifies incremental delivery with output buffering enabled; it does not infer streaming from the in-process test suite.

## Release documents and boundaries

- Prepared [v0.2.0 release notes](../../docs/releases/v0.2.0.md) and reviewed [UPGRADE.md](../../UPGRADE.md). The guide explicitly requires clearing old Synapse storage before updating, preserves SDK/application data, and includes optional MCP dependency updates and a post-upgrade checklist.
- Earlier fresh/repeat installations, real `--no-dev` deployment checks, and migration rehearsals remain documented in [QUALIFICATION.md](QUALIFICATION.md) and [INSTALLATION-WALKTHROUGH.md](INSTALLATION-WALKTHROUGH.md). They were not repeated in this documentation-only session; current installation/migration feature regressions passed in all eight rows.
- Live coverage is OpenAI on one model. Tools use local demo business data; MCP is in-process. Other providers, failover, reasoning-specific output, provider-hosted tools, remote MCP transport, audio/PDF flows, and deployment-specific proxy servers remain outside this live qualification. Earlier image transport/persistence passed with mixed semantic accuracy; see [LIVE-PROVIDER-TEST.md](LIVE-PROVIDER-TEST.md).
- Approval resume remains issue #1; structured output remains single-shot. The preview remains live on port 8013, so additional prompts use the configured provider.

## Local evidence

Ignored harness and detailed transcript: `build/laravel-ai13-dx/bin/check-live-release.py` and `release-smoke-2026-10-26.json` in that app. Screenshot: `build/laravel-ai13-dx/release-approval-2026-10-26.jpg`.

Logs: `/tmp/synapse-release-{check,browser,build,live}.log`, `/tmp/synapse-release-php{83,84}-l{12,13}-{floor,latest}.log`, and `/tmp/synapse-release-l{12,13}-latest-browser.log`.

```text
dist/app.js  sha256 18407cc76c06e6a93c9a5489842889ba308389ca70b30fd5fb6e3d0db968449d
dist/app.css sha256 ee13dafd4a1cbe9be0fb4d00f13e1ced04cc06882e10f1e6f753264ba44b9228
```
