# Releasing Synapse

Run this in order. It exists because `v0.1.0` shipped an install that could not
succeed — the SDK constraint was stale and nothing in the suite could tell,
since the package's own environment was pinned to a local checkout. Steps 4 and
6 are the ones that would have caught it.

## 1. Assets

```bash
npm run build && git status --short dist/
```

`dist/` is committed and inlined at runtime. If the build produces a diff, commit
it — a stale bundle ships a broken UI with a green suite.

## 2. Gates

```bash
composer check
```

```bash
composer test:e2e
```

Both must pass, with no skipped browser tests. The browser harness skips tests
when assets are missing; a skipped suite does not satisfy the release gate.
There is currently no automated test-matrix workflow, so run these release gates
locally after building the assets in step 1.

## 3. Streaming

```bash
bin/check-streaming.sh
```

Against a server on each runtime the docs claim. TTFB must be a few milliseconds;
if it approaches the total, nothing is streaming. See AGENTS.md.

## 4. Resolve dependencies the way a user does

```bash
composer update --dry-run
```

**Check the SDK constraint against what `laravel/ai` has actually released**, not
against what is in `references/` or your lock file. SDK releases move, and
a constraint that has fallen behind makes the package uninstallable in a fresh
app while every local test stays green.

Never add a path repository for a real dependency to `composer.json`. It is
canonical, outranks Packagist, and silently pins whatever is on disk.

## 5. Changelog

Update `CHANGELOG.md`. For anything user-visible, write release notes in
`docs/releases/vX.Y.Z.md` — features and what they're for, not a commit log.
Compare against the latest published tag so every unreleased feature is included.
Keep version-specific upgrade paths in `UPGRADE.md`: additive migrations must
not inherit the legacy 0.1.x storage-reset instructions. Review README and UPGRADE
for stale release-status notices and verify any migration counts.

## 6. Clean-app smoke test

Not `testing-laravel-project`, and not a path repository. Both skip exactly what
breaks real installs: the published archive's contents, the package name, and
dependency resolution against live Packagist.

```bash
laravel new synapse-smoke && cd synapse-smoke
```

```bash
composer require laravel/ai && composer require redberry/synapse --dev
```

```bash
php artisan synapse:install && php artisan serve
```

Then, following only the README:

- `/synapse` loads with a working interface — this is what proves `dist/` is in the published archive
- an agent written *after* install appears in Discovery on refresh
- a real provider streams a real answer incrementally (needs real credentials — a faked provider proves nothing here)
- a tool that takes seconds holds an amber `pending` card, then resolves
- History lists the conversation and replays it after a full refresh

## 7. Manual pass

Cover discovery, info panel, chat, tool inspection, attachments/structured/reasoning,
history, approvals, cited sources, completion explanations, and the step inspector
in both themes. Include reload and approval continuation. Record commands, resolved versions, results, and coverage limits in the release-preparation PR; do not create a permanent completed-plan directory.

## 8. Publish from GitHub

Before merging the release-preparation PR, replace its planned/unreleased changelog heading with the intended release version and date, remove obsolete publication caveats, and finalize the release notes. If publication is delayed, correct the date before tagging.

Merge the release-preparation PR, then open GitHub → Releases → Draft a new release.
Create the planned `vX.Y.Z` tag against the merged `main` commit, use the title from
`docs/releases/vX.Y.Z.md`, and paste that file's release notes into the description.
Publish when authorized. Keep the description feature-focused; do not append generated PR/issue lists. Do not tag the preparation branch before it is merged or move an existing published tag.

Then install the tag in a fresh app one more time and repeat step 6's first two
checks. Packagist picks the tag up automatically via the GitHub hook.

Record the published URL, date, tag commit, and post-publication install result in the release-preparation PR. Confirm the changelog and release notes on main agree with the published release.

### Outstanding post-publication check

- [ ] **v0.3.0:** install the exact published tag from Packagist in a fresh app, verify the dashboard and discovery, and record the outcome on [PR #27](https://github.com/RedberryProducts/synapse/pull/27). The pre-publication candidate archive passed; the published-tag installation has not yet been verified. Remove this item once its result is recorded.
