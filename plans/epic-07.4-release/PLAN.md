# Epic 7.4 — Release

**Goal:** `composer require redberry/synapse --dev` in a clean Laravel application produces a working dashboard, and v0.1.0 is tagged.

Delivers every Success Criterion, end to end, on a real install rather than in the workbench.

**Depends on:** 7.1, 7.2, 7.3 — all of them.
**Blocks:** nothing. This is the last one.

---

## Why this exists

Everything up to here has been verified in the workbench, in Testbench, or in
`testing-laravel-project` — an app that was built by a script from this
repository and has been carried forward through six epics. None of that is the
thing a user does.

The install has never been performed the way a stranger will perform it: from
Packagist, into an application that knows nothing about Synapse, following the
README as written.

The streaming bug is the argument for this epic. It survived six epics of green
tests because every tier ran Laravel in-process. A release gate that only re-runs
the same suites would have shipped it.

---

## Decisions

Confirmed before planning:

1. **The smoke test uses a genuinely clean app, from Packagist.** Not
   `testing-laravel-project`, not a path repository. A path repo skips exactly the
   things that break real installs: the published archive's file list, the
   package name, autoload paths, and whether `dist/` is actually in the tarball.
2. **The manual e2e pass covers all six epics, not just the last one.** Epic 6's
   manual pass found three bugs in code that had been green for weeks. The
   suites are not a substitute for looking at it.
3. **v0.1.0, not v1.0.0.** MVP scope, one supported runtime family, Octane
   explicitly unsupported. The version should say that.

---

## Scope

**In**

- Clean-app install smoke test from Packagist
- Full manual e2e across Epics 1–6
- The 7.1 runtime matrix re-run on the release commit
- `CHANGELOG.md`
- Version metadata, tag `v0.1.0`, Packagist release
- Release checklist committed, so v0.1.1 is cheaper than v0.1.0 was

**Out**

- CI for the browser suite — it needs Playwright and current `dist/`; it stays a manual gate (AGENTS.md)
- An automated release pipeline — worth it once the checklist has been run by hand at least once
- Any new feature or fix that isn't a release blocker; those go to v0.1.1

---

## Technical approach

### 1. Clean-app smoke test

```bash
laravel new synapse-smoke
cd synapse-smoke
composer require laravel/ai
composer require redberry/synapse --dev
php artisan synapse:install
php artisan serve
```

Then, following only the README:

- `/synapse` loads and is not gated in `local`
- Discovery lists the agents in a freshly written `app/Agents/`
- The playground streams a real answer from a real provider
- A tool call renders, and a slow one holds `pending`
- History lists the conversation and replays it after a refresh

**Trap:** the smoke app needs real provider credentials to prove anything past
Discovery. Streaming, tool cards and token counts are exactly what a faked
provider cannot verify, and are exactly what the product is for.

### 2. What the archive must contain

`dist/app.js` and `dist/app.css` are inlined at runtime, so if they are missing
from the published archive the dashboard renders a comment instead of an
interface. Verify against the actual downloaded package, not the git tree —
`.gitattributes` `export-ignore` rules are the usual way this breaks.

### 3. Manual e2e across all six epics

| Epic | What to look at |
|------|-----------------|
| 1 Discovery | New agent appears on refresh; provider/model/tools correct |
| 2 Info Panel | Config, prompt, tools, middleware match what the SDK would use |
| 3 Chat MVP | Streaming text, token counts, error cards, persistence across refresh |
| 4 Tool Inspection | Tool cards, arguments, results, failures, provider-native tools |
| 5 Chat Advanced | Attachments, structured output, reasoning, model override, failover notice |
| 6 History | Filters, search, pagination, rename, delete, replay, orphaned agents |

Both themes throughout.

### 4. Release checklist

Committed to the repo so it is re-runnable:

1. `composer check` green
2. `composer test:e2e` green
3. `npm run build`; `dist/` committed and matching source
4. `bin/check-streaming.sh` green on each supported runtime
5. Clean-app smoke test passed
6. Manual e2e passed, both themes
7. CHANGELOG updated
8. Tag, push, verify on Packagist
9. Install the tagged version in a fresh app one more time

---

## Frontend components to use

None. This epic ships no UI.

---

## Configuration

No changes.

---

## Acceptance criteria

1. `composer require redberry/synapse --dev` in a clean Laravel app installs from Packagist without a path repository.
2. `php artisan synapse:install` completes and `/synapse` loads with a working interface — proving `dist/` is in the published archive.
3. An agent written after install appears in Discovery on refresh.
4. A real provider streams a real answer, incrementally, in the smoke app.
5. A four-second tool shows `pending` throughout, then `success`, in the smoke app.
6. History lists and replays the smoke conversation after a full refresh.
7. All six epics pass their manual e2e, in both themes.
8. `bin/check-streaming.sh` passes on every runtime the docs claim.
9. `CHANGELOG.md` describes v0.1.0, including the streaming fix and the Octane limitation.
10. `v0.1.0` is tagged and resolvable from Packagist, and installing the tag reproduces AC 1–6.

---

## Code map

| Area | Path |
|------|------|
| Release checklist | `docs/RELEASE.md` (Create) |
| Changelog | `CHANGELOG.md` (Create) |
| Archive contents | `.gitattributes` |
| Package metadata | `composer.json` |
| Streaming gate | `bin/check-streaming.sh` (from 7.1) |

---

## Tests

No new automated tests. This epic's instrument is the clean install; its output
is a checklist that makes the next release cheaper.

If the smoke test finds a bug, the fix ships **with a regression test at the tier
that could have caught it** — which, on today's evidence, is often not the tier
you would reach for first.

---

## Risks

| Risk | Mitigation |
|------|------------|
| `dist/` missing from the published archive | AC 2 tests the downloaded package, not the git tree |
| Package name wrong on Packagist | Fixed in 7.3 (AC 1 there); re-verified here |
| The smoke test needs provider credentials and gets skipped | Decision 1 + the trap in §1: without a real provider it proves only that the page loads |
| A blocker is found late and scope creeps into a fix-everything epic | Blockers ship in v0.1.0; everything else is v0.1.1. Decide with the checklist, not in the moment |
| v0.1.0 is read as production-ready | Decision 3, plus the README security note from 7.3 |

---

## Delivered

The epic found what it was written to find, on its first step.

### v0.1.0 on Packagist cannot be installed

The documented sequence fails in a fresh Laravel 13 app:

```
redberry/synapse v0.1.0 requires laravel/ai ^0.9 -> found laravel/ai[v0.9.0, v0.9.1]
but it conflicts with your root composer.json require (^0.10.2).
```

`laravel/ai` released `0.10` while Synapse pinned `^0.9`. Anyone following the
README gets a hard resolution failure.

**Why no test caught it.** `composer.json` carried a **path repository** for
`references/laravel/ai`. A path repo is canonical and outranks Packagist, so the
package's own environment — and `bin/setup-testing-app.sh`, which wired the same
repo into the manual-testing app — was pinned to a local checkout of `0.9.1`.
Every gate validated against a version of the SDK the world had already moved
past. Decision 1's "not a path repository" applied to Synapse itself, and we had
not noticed.

### The fix

All 52 `laravel/ai` classes Synapse imports exist unchanged in `0.10.2`, and the
whole suite passes against it — **194 backend tests, 67 browser tests, PHPStan
clean**. So the constraint was stale, not the code:

- `laravel/ai` widened to `^0.9|^0.10`
- the path repository removed from `composer.json` and from the setup script, with the reason recorded where someone would be tempted to add it back
- `README`, `GOAL` and `PRD` updated to state the supported range

### Clean-app smoke test — passed

A fresh `laravel new`, real Packagist resolution, no path repo for the SDK:

| Check | Result |
|---|---|
| Installs alongside `laravel/ai` | ✅ `v0.10.2` + Synapse |
| `synapse:install` | ✅ config, provider and 3 migrations |
| `dist/` in the installed package | ✅ 516KB JS, 37KB CSS |
| `/synapse` loads | ✅ 200, 555KB, assets inlined, `streaming: true` |
| Agent written **after** install | ✅ discovered on refresh |
| Real provider streams | ✅ TTFB **40ms** of a 9506ms run |
| Slow tool holds `pending` | ✅ **4020ms** of amber, matching a 4s tool |
| History replay after refresh | ✅ tool card at 4487ms, 224/32/256 tokens |

### Known limitation introduced by 0.10

`laravel/ai 0.10` adds tool approvals and a `tool-output-denied` stream part.
Synapse drops it in the client's ignored `default` branch, so a denied call would
sit as a card that never resolves. Recorded in the changelog; worth an epic of
its own if approvals get used.

### Not verified here

- **AC 7** — the full manual pass across all six epics in both themes. The smoke app covered discovery, playground streaming, tool inspection, history and replay; the info panel, attachments, structured output and reasoning were last verified on `testing-laravel-project`, not on a clean install.
- **AC 1 and 10 against Packagist** stay open until `v0.1.1` is tagged: the fix is verified locally, but the published `v0.1.0` is still broken.

## Definition of done

- All 10 acceptance criteria verified
- `docs/RELEASE.md` committed and followed once, by hand, start to finish
- `CHANGELOG.md` covering v0.1.0
- `v0.1.0` tagged and live on Packagist
- A fresh install of the tag verified after publishing
- `plans/PLAN.md` Epic 7 rows marked ✅ done
