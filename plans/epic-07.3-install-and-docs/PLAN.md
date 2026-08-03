# Epic 7.3 — Install, Operability & Docs

**Goal:** a developer who has never seen Synapse can install it, understand what it exposes, and find the answer to their next question without reading the source.

Delivers PRD [Authorization & Safety](../../PRD.md#authorization--safety) · PRD [Asset Delivery](../../PRD.md#asset-delivery) · GOAL [Installation](../../GOAL.md#installation) · GOAL [Artisan commands](../../GOAL.md#artisan-commands).

**Depends on:** 7.1 (the support table it publishes) · 7.2 (the GOAL corrections it publishes).
**Blocks:** 7.4 (release).

---

## Why this exists

The README is 40 lines and still opens with:

> **Status:** early scaffold. See GOAL.md for the full product documentation,
> PRD.md for the technical spec, and SCAFFOLDING.md for the build plan.

It points a prospective user at three internal planning documents. Meanwhile six
epics have shipped a working product.

There is also a **name conflict that will break the first install anyone tries**:

| Source | Package name |
|--------|--------------|
| `composer.json:2` | `redberry/synapse` |
| `README.md` | `composer require redberry/synapse --dev` |
| `GOAL.md:53` | `composer require synapse-ai/synapse --dev` |

One of these is wrong and a user will hit it as their very first command.
Reconciling it is the first task in this epic, not a docs detail.

---

## Decisions

Confirmed before planning:

1. **The README is the product's front door; GOAL.md stays the manual.** README
   gets install, a screenshot, the security note and a link. It does not become
   a second copy of GOAL.md — two documents saying the same thing drift, and the
   one that drifts is always the one you didn't update.
2. **The security note is prominent, not a footnote.** Synapse exposes an
   endpoint that *invokes real agents* — spending credits and executing tools
   that may write to a database or call webhooks. The PRD is explicit that this
   is worse than Telescope's exposure. The README says so above the fold.
3. **`php artisan about` is the operability surface, not a new command.** Every
   Laravel developer already knows it. A bespoke `synapse:doctor` is a second
   thing to learn and a second thing to maintain.

---

## Scope

**In**

- Reconcile the package name across `composer.json`, README and GOAL
- README rewrite: what it is, install, a screenshot, requirements, the security note, runtime support, link to GOAL
- `php artisan about` section: version, enabled state, dashboard path, agent count, streaming capability (from 7.1)
- `synapse:install` — idempotency, and the update path after `composer update`
- Publish the runtime support table from 7.1 into GOAL's Compatibility section
- Publish the GOAL corrections from 7.2's triage
- Config reference: every `synapse.*` key with its default and effect
- Screenshots for README and GOAL

**Out**

- A documentation site — a README plus GOAL.md is the right size for v0.1.0
- Video or GIF walkthroughs
- Translations
- CHANGELOG and tagging — that's 7.4

---

## Technical approach

### 1. Install idempotency and the update path

`InstallCommand` publishes config, migrations and the provider stub, then
migrates:

```php
$this->callSilent('vendor:publish', ['--tag' => 'synapse-config']);
$this->callSilent('vendor:publish', ['--tag' => 'synapse-migrations']);
$this->callSilent('vendor:publish', ['--tag' => 'synapse-provider']);
```

Two things to verify rather than assume:

- **Re-running it** must not clobber a customised `config/synapse.php` or an
  edited `SynapseServiceProvider` — that provider holds the user's `viewSynapse`
  gate, and silently overwriting it would be a security regression, not an
  inconvenience.
- **After `composer update`**, new migrations need running. Whether that is a
  documented `php artisan migrate` or a re-run of `synapse:install` is a
  decision this epic makes and writes down.

### 2. No asset publishing — say so

Per PRD → Asset Delivery, `dist/` is committed and inlined by `Synapse::css()` /
`Synapse::js()`; nothing is copied into the host app's `public/`. So there is no
re-publish step and a `composer update` can never leave stale assets behind.

That is a genuine advantage and the usual reason dashboards break after an
update, so it belongs in the docs explicitly — a developer who has been burned by
Horizon will otherwise go looking for the publish command.

The one failure mode worth documenting: a **source checkout without a build** has
no `dist/`, and `js()` emits an HTML comment telling the developer to run
`npm run build`.

### 3. `php artisan about`

```
Synapse ................................................. v0.1.0
  Enabled ............................................... true
  Path .................................................. synapse
  Agents discovered ..................................... 12
  Streaming ............................................. supported
```

`Streaming` reads the capability from 7.1, so an Octane user sees
`unsupported` here as well as in the playground.

### 4. Config reference

Every key in `config/synapse.php`, its default, and what it actually changes:
`enabled`, `ui.path`, `ui.middleware`, `discovery.paths`, `discovery.ignore`,
`playground.models`, `storage.connection`, `storage.attachments_disk`,
`retention.auto_prune`, `retention.days`.

Two deserve their own prose rather than a table row:

- **`enabled`** — `env('SYNAPSE_ENABLED', env('APP_ENV') !== 'production')`. Routes do not register in production without an explicit opt-in, and enabling it still requires passing the gate. Defence in depth: a forgotten `composer require` on a production box must not become an unauthenticated agent-invocation endpoint.
- **`discovery.paths`** — defaults to `app/Ai/Agents` and `app/Agents`. An agent living anywhere else is invisible, and this is the single most likely reason someone's Discovery page is empty.

---

## Frontend components to use

None. This epic ships no UI.

---

## Configuration

No new keys. This epic **documents** the existing set.

---

## Acceptance criteria

1. The package name is identical in `composer.json`, README and GOAL, and `composer require <name> --dev` resolves.
2. The README no longer describes the project as a scaffold, and does not send a new user to PRD.md or SCAFFOLDING.md to learn how to use it.
3. The README shows install, a screenshot of the playground, requirements, and the security note above the fold.
4. `php artisan about` shows a Synapse section with version, enabled state, path, agent count and streaming support.
5. Running `synapse:install` twice does not overwrite a customised `config/synapse.php` or an edited `SynapseServiceProvider`.
6. The docs state what to do after `composer update`, and it is correct when followed on a real app.
7. The docs state that assets are never published, and why there is no publish step.
8. GOAL's Compatibility section carries the runtime support table from 7.1, listing Octane as unsupported for v0.1.0.
9. Every GOAL correction from 7.2's triage is published.
10. Every `synapse.*` config key is documented with its default and effect.

---

## Code map

| Area | Path |
|------|------|
| Package name | `composer.json` · `README.md` · `GOAL.md` |
| Install | `src/Console/InstallCommand.php` |
| About | `src/SynapseServiceProvider.php` (Adjust — `AboutCommand::add()`) |
| Docs | `README.md` · `GOAL.md` |
| Screenshots | `docs/screenshots/` (Create) |

---

## Tests

### Feature

- `synapse:install` run twice leaves a customised config and an edited provider stub untouched
- `about` output contains the Synapse section and the expected keys

### Manual

- The README's install block, followed literally in a clean app — this is 7.4's smoke test and the docs are its script

---

## Risks

| Risk | Mitigation |
|------|------------|
| The package name is wrong on Packagist and the first install fails | AC 1; resolved before anything else in this epic |
| `synapse:install` overwrites a user's gate definition | AC 5, with a feature test — this is a security regression, not a papercut |
| README and GOAL drift | Decision 1: one front door, one manual, no duplication |
| Screenshots go stale as the UI changes | Take them last, after 7.1 and 7.2 have landed their UI changes |

---

## Delivered

Shipped as planned, with one row cut from `about` for being unanswerable.

### The package name

`redberry/synapse` won: it was already in `composer.json`, `package.json`
(`@redberry/synapse`), README, AGENTS.md, SCAFFOLDING.md and `bin/setup-testing-app.sh`
— eight places against two. `synapse-ai/synapse` survived only in PRD.md and
GOAL.md, and both now match the artifact that actually gets published.

### `php artisan about` — and the row that isn't there

```
Synapse ............... v0.1.0
  Agents discovered ... 13
  Enabled ............. true
  Path ................ /synapse
  Retention ........... kept until pruned manually
```

`Agents discovered` is a deferred closure — discovery instantiates every agent
class, which is far too much work to do for an unrelated `about` call, so it only
runs when the section is actually rendered.

**Streaming was in the plan and is deliberately not reported.** The first
implementation included it and printed `not supported on this runtime` — because
`about` *always* runs on the CLI SAPI, where `Synapse::streams()` is false by
definition. It would have told every developer their dashboard cannot stream,
including everyone whose dashboard streams perfectly. The playground answers the
question in the web request, where it means something, and
`bin/check-streaming.sh` measures it. A test asserts the row stays absent, so
nobody re-adds it in good faith.

This changes AC 4: `about` shows version, enabled, path, agent count and
retention. Streaming is explicitly out.

### Install idempotency

`vendor:publish` without `--force` already declined to overwrite, but nothing
proved it, and the file at risk is the one holding the developer's `viewSynapse`
gate — silently restoring the deny-everyone stub would be a security regression,
not a papercut. `tests/Feature/InstallTest.php` customises both published files,
re-runs the install, and asserts the edits survive.

**The first version of that test was not hermetic.** Published files land in the
Testbench skeleton under `vendor/`, which persists between runs, so a second run
would have started with the previous run's "customised" content already in place
and passed without publishing anything. Fixed with `beforeEach`/`afterEach`
cleanup, and verified by running the suite twice and checking the skeleton in
between.

### Docs

- **README rewritten.** It no longer calls the project an early scaffold or send a new user to PRD.md to learn how to use it. Install, a screenshot, what you get, the full config table, commands, the runtime matrix, and the security warning — with the "this invokes your real agents and spends real credits" note given its own section above the feature list rather than buried.
- **GOAL** gained the update path (`php artisan migrate`, plus the reassurance that re-running the install is safe), and an explicit statement that assets are never published and why that matters after `composer update`.
- **GOAL's sample config had drifted** from the shipped file: it showed `env('SYNAPSE_ENABLED', true)` where the real default is production-aware, and a hardcoded `'path' => 'synapse'` where the real key reads `SYNAPSE_PATH`. Both corrected, and `SYNAPSE_PATH` added to the env table.

### The screenshot

Generated from a workbench fixture through the browser driver, committed at
`docs/screenshots/playground.png`.

Two things had to be corrected before it was usable, and both are worth
recording. The driver runs on the CLI SAPI, so the playground correctly showed
its **"this runtime buffers responses"** notice — true of the harness, false of
every runtime the README lists, and deeply misleading as the first image a
prospective user sees. And the faked response carried no usage, so the token
counters all read zero. The capture now sets the streaming flag to what a
supported runtime reports and uses a `TextResponse` with realistic usage.

## Definition of done

- All 10 acceptance criteria verified
- `composer check` green
- README readable end to end by someone who has never seen the project
- GOAL carries the runtime table and every 7.2 correction
- Screenshots current with the shipped UI
- **PRD updated** if the update path changed anything about install
