# Working on Synapse

Guidance for any agent or contributor writing code in this repository. Read this first, then [DEV.md](DEV.md) for exact commands. For *how Laravel packages work*, invoke the **`laravel-package`** skill. For *what we're building*, [GOAL.md](GOAL.md) (user-facing) and [PRD.md](PRD.md) (technical) are the source of truth.

## Important notes

- **Plan before coding.** For a non-trivial task, state a short plan (steps → how you'll verify each) and work through it.
- **Run the gates before reporting done:** `composer check` (lint + analyse + test). All green, every time. A pre-commit hook enforces this — enable it once with `composer hooks`.
- **Run `composer test:e2e`** when you touch the frontend or the chat/stream surface. It's excluded from `composer check` and from CI (needs Playwright + built assets); it is a required gate before a release.
- **Rebuild `dist/`** (`npm run build`) whenever `resources/js` changes — it's committed, and the hook checks it.
- **Never `git commit` without being asked.**
- **PRD.md / GOAL.md are the spec.** If code and spec disagree, stop and reconcile — don't invent behavior.

## What this is

**Synapse** (`redberry/synapse`, namespace `Redberry\Synapse\`) is a Laravel package: a development dashboard for AI agents built with the Laravel AI SDK (`laravel/ai`). A React SPA served by a Laravel service provider — think Telescope/Horizon, but for AI agents. The scaffold is complete and green (Pest, Pint, PHPStan); feature work builds on top of it.

## Repository map

```
src/                          # PHP — the package
├── SynapseServiceProvider.php        # register/boot: routes, views, publish, commands, schedule
├── SynapseApplicationServiceProvider.php  # base for the published gate provider
├── Synapse.php                       # static helpers: asset tags, script vars, auth callback
├── Http/{Controllers,Middleware}/    # HomeController (SPA shell), Authorize (viewSynapse gate)
├── Console/                          # Install / Prune / Clear commands
├── Migrations/SynapseMigration.php   # connection resolver base
├── Models/                           # SynapseModel base + Conversation/Message/ToolInvocation
└── Repositories/                     # ConversationRepository (cascade delete lives here)
config/synapse.php            # publishable config (enabled, ui, discovery, playground, storage, retention)
database/migrations/          # the three synapse_* tables (publish-only)
routes/web.php                # /api group (stubbed) + SPA catch-all
resources/js/                 # React + TS SPA — elements/ → components/ → composed/ → pages/
                              #   (+ hooks, lib, types, styles)
resources/views/layout.blade.php   # SPA shell + window.Synapse bootstrap
stubs/                        # SynapseServiceProvider.stub (published viewSynapse gate)
dist/                         # compiled assets — COMMITTED, published on install
workbench/                    # Testbench dev app + 4 sample agents
tests/                        # Pest + Testbench (Unit/, Feature/, Browser/ e2e)
.githooks/                    # pre-commit hook (enable with `composer hooks`)
testing-laravel-project/      # gitignored real app for manual install testing (bin/setup-testing-app.sh)
references/                   # gitignored: laravel/ai, telescope, horizon (read-only reference)
```

Docs: [PRD.md](PRD.md), [GOAL.md](GOAL.md), [SCAFFOLDING.md](SCAFFOLDING.md), [DESIGN_FEEDBACK.md](DESIGN_FEEDBACK.md).

## Development principles

### Hierarchy of priorities

1. **Correctness** — does it match the PRD/GOAL for this feature?
2. **Simplicity** — is this the simplest solution that works?
3. **Readability** — can human understand it in 30 seconds?

### KISS · DRY · SOLID — applied pragmatically

- **KISS** — don't over-engineer. Three similar lines beat a premature abstraction. No config options, flexibility, or helper indirection nobody asked for.
- **DRY** — extract shared logic only once it appears in 2+ places with the same shape. Premature deduplication couples worse than duplication. (The `ConversationRepository` cascade earned extraction because prune, clear, and the future delete-endpoint all need it.)
- **SOLID** — at package scale, the parts that matter: **single responsibility** (a controller doesn't build SQL; a command delegates to a repository/service), and **dependency inversion** (services/repositories receive collaborators via constructor injection so they're testable). Prefer composition over inheritance; the `SynapseModel`/`SynapseMigration` base classes are the deliberate exceptions (shared framework wiring).

## What NOT to do

- **Don't write comments that restate the code.** Use intention-revealing names. Add docblocks only where they carry type information PHPStan needs (array shapes, generics, model `@property`) or explain a non-obvious *why*.
- **Don't silence PHPStan** with `@phpstan-ignore` without explicit permission from developer, baseline entries, `assert()`, inline `@var`, casts, or widened types. An "always true/false" error means an annotation is wrong.
- **Don't leave `// TODO`/`// FIXME`** without a concrete action.
- **Don't handle impossible errors** (e.g. re-validating data a FormRequest/route-model-binding already guaranteed). One catch-all owns agent-invocation failures (PRD Feature 6).
- **Don't add a dependency** before checking whether the framework or an existing dependency already solves it. Justify new frontend deps by a feature that needs them.
- **Don't use `mixed`** where a concrete type is known; narrow it. Don't reach for facades in services/repositories where constructor injection reads clearer and tests better — facades are fine at framework edges (providers, commands, controllers).
- **Don't concatenate SQL.** Eloquent/query-builder bindings only.
- **Don't reformat or refactor unrelated code** while making a change (see Surgical changes).
- **Don't touch the SDK's tables.** `synapse_*` tables are fully independent of `agent_conversations`.

## Coding standards

**PHP**
- Laravel style, enforced by **Pint (`laravel` preset)** — run `composer lint` before committing. Don't introduce `declare(strict_types=1)` (not used here); match the surrounding file.
- Type everything: params, returns, properties; constructor property promotion; `@property` docblocks on models.
- **PHPStan level 5 (larastan) stays green.** Fix the root cause, always.
- Small, single-concern classes.

**TypeScript / React**
- **Four layers**, each importing only from the ones above it:
  - `elements/` — generic UI primitives (button, input, dropdown, tooltip, badge…). Domain-agnostic: they know nothing about agents or conversations. The **only** place Radix is imported.
  - `components/` — single-purpose domain components (an agent card, a tool chip, a status badge). Presentational: data in via props, events out via callbacks. No fetching.
  - `composed/` — larger units assembled from several components (app shell, grids, panels, threads). Still props-in/events-out; no route-level data loading.
  - `pages/` — one per route. Owns data fetching, hooks, page state, and wiring; composes the layers above.
  - Details and the element inventory: [plans/FRONTEND.md](plans/FRONTEND.md).
- React + TypeScript (strict), Tailwind v4 utilities, shadcn/ui vendored into `resources/js/elements` (`components.json` `ui` alias) and restyled to our design tokens. Import via the `@/` alias.
- Logic belongs in pages and hooks, not components. A component that needs data takes it as props.
- Theme via `--color-*` CSS tokens in `resources/js/styles/app.css` (dark-first). Never hard-code colors. 
- use Icons from `lucide-react` — no inline SVG.
- Named exports; keep the bundle lean.

### Naming

| Thing | Convention | Example |
|-------|-----------|---------|
| Classes / enums | `StudlyCase` | `SynapseToolInvocation` |
| Methods / variables | `camelCase` | `deleteConversations()` |
| Constants | `SCREAMING_SNAKE` | `Synapse::VERSION` |
| Config keys | `snake_case` | `attachments_disk` |
| DB tables / columns | `snake_case` | `synapse_tool_invocations` |
| Routes / URLs | `kebab-case` | `/synapse/api/conversations` |
| Env vars | `SYNAPSE_` + `SCREAMING_SNAKE` | `SYNAPSE_DB_CONNECTION` |
| Publish tags | `synapse-` + `kebab` | `synapse-config` |
| React components | `PascalCase.tsx` | `AppShell.tsx` |
| Frontend lib modules | `camelCase.ts` | `api.ts`, `theme.ts` |

## Testing

Testbench + Pest. Run details in [DEV.md](DEV.md); philosophy here.

- **One behavior per `it()`.** If the name needs "and", split it — a failure should name exactly what regressed. Share expensive setup with `beforeEach`/`describe`, keep each test to a single assertion of intent.
- **Descriptive names:** `it('forbids access when the gate denies')`.
- **Test the real implementation.** Use the real DB (`RefreshDatabase`, in-memory sqlite). Avoid mocks; when you must fake I/O, use Laravel's fakes (`Storage::fake`, `Event::fake`, `Http::fake`, `Bus::fake`) over mocking libraries.
- **Feature-first.** Exercise real routes/commands/models over isolated units. Each test is independent.
- Every feature ships with tests; `composer test` green before done.

### Test tiers

| Tier | Location | Runs in | Purpose |
|------|----------|---------|---------|
| Unit | `tests/Unit/` | `composer test`, CI | Pure logic in isolation |
| Feature | `tests/Feature/` | `composer test`, CI | Routes, commands, models, repositories via the real app |
| Browser (e2e) | `tests/Browser/` | `composer test:e2e` only | Real browser (Pest 4 + Playwright): the compiled React app mounts, renders, streams, routes |

Browser tests are the **only** way to verify the PHP → SSE → React boundary, which is where Synapse's hardest bugs live. They are deliberately out of `composer test` and out of CI — they need Playwright and current `dist/` assets, so they're a manual gate before releases (and worth running whenever you change the frontend).

**Always drive browser tests with `Agent::fake()`** — never call a real provider: no API spend, no flakiness, deterministic streams.

### Writing browser tests

**Target with `data-testid`; assert content as text.** These are different jobs and mixing them produces tests that pass while the UI is broken.

- **Targeting / scoping → `data-testid`.** Pest resolves `@name` to `[data-testid=name]`. Use it to find an element to click, to assert presence/absence, and to scope a content assertion.
- **Content → real text**, scoped to the element it belongs to: `assertSeeIn('@tool-detail', 'Search query text')`. Asserting only that `@tool-description` *exists* would pass with empty or garbled copy — exactly the bug a dashboard must never ship.
- **Interactive controls prefer their accessible name** over a testid — `click('[aria-label="Close info panel"]')` targets *and* enforces a11y. Add a testid only when there's no sensible accessible name. (Pest's plain-string `click()` matches visible text; an icon-only button has none, and it will hang until timeout rather than fail fast.)

**Add a testid only when a test needs it** — never pre-emptively. Sparse, deliberate ids stay meaningful; a testid on every `<div>` turns the suite into structure-testing.

Two gotchas worth knowing:

- **Scoped assertions run in Playwright strict mode** — the text must match exactly one node inside the scope. `assertSeeIn('@info-panel', 'PROVIDER')` fails when the panel also contains "PROVIDER OPTIONS". Pick unambiguous strings.
- **Text assertions don't wait for a re-render; element assertions do.** After an interaction, prefer `assertPresent` / `assertMissing` over `assertSee` / `assertDontSee`.

### What to test

| Layer | What to test |
|-------|--------------|
| Config | defaults, env resolution, the production route-guard |
| Migrations / models | tables exist, casts, uuid7 keys, relationships |
| Repositories | cascade deletion, prune windows |
| Commands | behavior, output, side effects |
| Controllers / API | status codes, response contract, auth gate |
| Discovery | finds agents, classifies tools, extracts metadata |
| Recorder | SDK event → `synapse_tool_invocations` row lifecycle |

**Don't test:** framework internals, Eloquent itself, `laravel/ai` behavior, trivial accessors.

## Synapse golden rules

1. **Never touch the SDK's tables.** Synapse is a dev tool, not a production logger.
2. **Mirror the agent, don't fake it.** Conversational agents get full history; stateless agents get one message per turn (PRD Feature 2).
3. **One catch-all owns errors.** Any `\Throwable` during invocation (including a throwing tool `handle()`) becomes an inline error card (PRD Feature 6).
4. **`config()` at runtime, `env()` only in config files.** Keeps `config:cache` safe.
5. **Store replay data in the SDK's own serialization shapes** (`ToolCall::toArray()`, `Usage::toArray()`, `File::fromArray()`); Synapse-specific observations live in `synapse_tool_invocations`.
6. **Cascade deletes in `ConversationRepository`**, never DB foreign keys.
7. **`dist/` is committed; `references/` and `testing-laravel-project/` are gitignored.**

## Learning from references/

`references/` holds read-only copies (gitignored) to learn patterns from — **study, don't copy verbatim**:

| Pattern | Where |
|---------|-------|
| Install command flow (publish tags + register provider) | `references/telescope/src/Console/InstallCommand.php` |
| App service provider + `view*` gate | `references/telescope/src/TelescopeApplicationServiceProvider.php` |
| SPA serving (blade shell, catch-all, asset helpers) | `references/horizon/` (`HomeController`, `Horizon.php`, `routes/web.php`) |
| Migration connection base | `references/laravel/ai/src/Migrations/AiMigration.php` |
| SDK events / contracts / streaming events | `references/laravel/ai/src/{Events,Contracts,Streaming}` |
| Conversation store round-trip (message rehydration) | `references/laravel/ai/src/Storage/DatabaseConversationStore.php` |

## Writing-code principles

1. **Think before coding.** State assumptions; if multiple interpretations exist, surface them rather than picking silently; if something's unclear, ask.
2. **Simplicity first.** Minimum code that solves the problem — nothing speculative. If 200 lines could be 50, rewrite.
3. **Surgical changes.** Every changed line should trace to the request. Don't "improve" adjacent code, comments, or formatting. Match existing style. Remove orphans *your* change created; leave pre-existing dead code (mention it).
4. **Goal-driven.** Turn tasks into verifiable goals: "fix the bug" → write a failing test that reproduces it, then make it pass.
5. **Reuse over duplication.** Before writing something new, check whether it already exists (a helper, a base class, an SDK method). If it needs refactoring to reuse, ask first and explain what's needed.

## Keeping docs in sync

This repo deliberately keeps its docs synchronized. When your change affects one, update it:

| Change | Update |
|--------|--------|
| A technical decision / mechanism | **PRD.md** |
| User-visible behavior | **GOAL.md** (and PRD if the mechanism changed) |
| Coding conventions / patterns / standards | **AGENTS.md** (this file) |
| Dev commands / workflow | **DEV.md** |
| Laravel-package methodology | the `laravel-package` skill |
| Quick-start / prerequisites | **README.md** |

PRD and GOAL move together — a behavior change usually touches both.

## Quick reference: task → location

| You need to… | Go to… |
|--------------|--------|
| Add an API endpoint | `routes/web.php` (`/api` group, before the catch-all) + `src/Http/Controllers` |
| Add business logic | a service in `src/` (e.g. `Discovery/`, `Recording/`) |
| Add a DB table | `database/migrations/` (extend `SynapseMigration`) |
| Add a model | `src/Models/` (extend `SynapseModel`) |
| Add a command | `src/Console/` + register in `SynapseServiceProvider` |
| Add config | `config/synapse.php` |
| Add a React page | `resources/js/pages/` + `router.tsx` |
| Add a UI primitive | `resources/js/elements/` (shadcn `ui` alias points here) |
| Add a domain component | `resources/js/components/` |
| Assemble several components | `resources/js/composed/` |
| Add a sample agent | `workbench/app/Agents/` |
| Add a test | `tests/Feature/` (or `tests/Unit/`) |
| Add a browser e2e test | `tests/Browser/` — run with `composer test:e2e` |
| Check product spec | `PRD.md` / `GOAL.md` |
| Check dev commands | `DEV.md` |

## Definition of done

- Matches the PRD/GOAL intent for the feature.
- Covered by Pest tests; `composer check` green (lint + analyse + test).
- `composer test:e2e` green if the frontend or chat/stream surface changed.
- `dist/` rebuilt if `resources/js` changed.
- PRD/GOAL updated if behavior or a technical decision changed.
