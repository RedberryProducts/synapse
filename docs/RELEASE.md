# Releasing Synapse

Run this in order. It exists because `v0.1.0` shipped an install that could not
succeed — the SDK constraint was stale and nothing in the suite could tell,
since the package's own environment was pinned to a local checkout. Steps 4 and
6 are the ones that would have caught it.

## 1. Gates

```bash
composer check
```

```bash
composer test:e2e
```

Both green. `composer test:e2e` is excluded from CI (it needs Playwright and
current assets), so it only runs if you run it.

## 2. Assets

```bash
npm run build && git status --short dist/
```

`dist/` is committed and inlined at runtime. If the build produces a diff, commit
it — a stale bundle ships a broken UI with a green suite.

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
against what is in `references/` or your lock file. A `0.x` dependency moves, and
a constraint that has fallen behind makes the package uninstallable in a fresh
app while every local test stays green.

Never add a path repository for a real dependency to `composer.json`. It is
canonical, outranks Packagist, and silently pins whatever is on disk.

## 5. Changelog

Update `CHANGELOG.md`. For anything user-visible, write release notes in
`docs/releases/vX.Y.Z.md` — features and what they're for, not a commit log.

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

All six feature epics, in both themes: discovery, info panel, chat, tool
inspection, attachments/structured/reasoning, history. Epic 6's manual pass
found three bugs in code that had been green for weeks.

## 8. Tag

```bash
git tag vX.Y.Z && git push origin vX.Y.Z
```

Then install the tag in a fresh app one more time and repeat step 6's first two
checks. Packagist picks the tag up automatically via the GitHub hook.
