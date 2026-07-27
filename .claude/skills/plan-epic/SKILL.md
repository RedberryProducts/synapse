---
name: plan-epic
description: Write or enrich a Synapse epic plan in plans/epic-NN-name/PLAN.md — the required sections (design, frontend components, config, technical approach, API, ACs, code map, tests, risks, DoD) and how to source each from PRD.md, GOAL.md, Figma, and the laravel/ai reference. Use when starting a new epic, enriching an existing epic plan, or breaking a feature into a plan.
---

# Planning a Synapse epic

Epic plans live in `plans/epic-NN-name/PLAN.md` with a `screenshots/` folder alongside. [plans/PLAN.md](../../../plans/PLAN.md) is the roadmap; [epic-01-discovery](../../../plans/epic-01-discovery/PLAN.md) is the reference implementation of this format.

A good epic plan is **executable without re-deriving research**: every SDK fact verified, every Figma component identified by node id, every config key listed, every AC testable.

## Sourcing rules

| Section | Source | Rule |
|---------|--------|------|
| Goal / scope | [GOAL.md](../../../GOAL.md) + [PRD.md](../../../PRD.md) | GOAL for user-visible behavior, PRD for the feature's mechanism. Link both. |
| Design | Figma via MCP | Cite **node ids**, not just screen names. Export a screenshot into `screenshots/`. |
| Frontend components | Figma components sheet + [plans/FRONTEND.md](../../../plans/FRONTEND.md) + the current repo | List **every** component the epic uses, in its layer (elements / components / composed / pages), each marked Done / Create / Adjust. During the MVP we name ours after the Figma component when there is one. |
| Config | PRD "Configuration" + GOAL "Configuration" | List only keys this epic **reads**. Flag any new key as a PRD change first. |
| Technical approach | PRD feature section, **verified** against `references/laravel/ai` | Never restate the PRD from memory — open the SDK source and confirm. |
| Tests | PRD + ACs | Every AC maps to at least one test. |

**Verify, don't assume.** The PRD is already SDK-verified, but APIs move. Before writing a technical section, read the actual classes in `references/laravel/ai/src`. If reality differs from the PRD, **stop and reconcile the PRD** — don't silently plan against the new finding.

## Required sections

```markdown
# Epic N — Name

**Goal:** one sentence, user-visible.
Delivers PRD [Feature X](...) · GOAL [section](...) · Success Criterion #N.
**Depends on:** ... · **Blocks:** ...

## Design
Screen links (node ids) + local screenshot + a component table:
| Figma component | Node | Variants |

## Frontend components to use
Follow the four layers in plans/FRONTEND.md — one table per layer used.
EVERY row carries a Status: Done | Create | Adjust (see below).
### 1. Elements (resources/js/elements/)
| Element | Status | Figma | Used by | Notes |
### 2. Components (resources/js/components/)
| Component | Status | Figma | Responsibility |
### 3. Composed (resources/js/composed/)
| Component | Status | Figma | Responsibility |
### 4. Pages (resources/js/pages/)
| Page | Status | Responsibility |
### Data layer
| File | Status | Responsibility |     ← types, hooks, api client additions
### Styling
Which token/style files change.

## Configuration
| Key | Default | Use here |            ← synapse.* keys this epic reads
| Key | Use here |                       ← host-app keys (ai.*) read but never written
State explicitly whether the epic introduces new config (it should usually not).

## Technical approach
Numbered subsections, each with verified SDK detail and a code sketch where it
removes ambiguity. Call out traps (e.g. "ProviderTool has no name()").

## API
Exact request + response JSON with comments on enum-ish fields.

## Acceptance criteria
Numbered, testable, user-observable. Include auth/gate and both themes.

## Code map
| Area | Path |

## Tests
Feature bullets + Browser bullets + workbench fixtures to add.

## Risks
| Risk | Mitigation |

## Definition of done
ACs verified · `composer check` green · tests added · both themes · dist rebuilt ·
PRD/GOAL updated if a decision changed.
```

## Frontend component status

The frontend section is a **complete list of every component the epic touches** — not just new ones. Each row states what has to happen to it:

| Status | Meaning |
|--------|---------|
| **Done** | Exists and is used as-is. Listed so the plan shows the full picture and nobody rebuilds it. |
| **Create** | Doesn't exist yet; this epic builds it. |
| **Adjust** | Exists but needs changes for this epic — say *what* changes in the Responsibility/Notes cell (e.g. "add agent count to footer"). |

Check the repo before writing the table (`find resources/js -type f`) so the statuses are accurate. Getting this right is what prevents duplicate components and silent rewrites of working code.

## Conventions

- **Acceptance criteria are observable.** "Discovery caches per request" is an implementation note; "a newly created agent appears after a refresh" is an AC.
- **Scope out loud.** An explicit "Out" list prevents the epic from absorbing the next one.
- **Name the traps.** If the SDK has a sharp edge (a method that fatals, an event that only fires on success, a serializer that drops data), it belongs in the technical approach with the reason — that's what makes the plan worth more than the PRD.
- **Every epic ships tests in both tiers** where it has UI: Pest feature tests for the backend, a browser test for the user-visible flow.
- **Both themes, always.** Light and dark component sets exist in Figma; theme-aware from the start, never retrofitted.
- **Screenshots go in the folder.** Figma asset URLs expire — export a PNG so the plan reads offline.

## Figma workflow

```
get_metadata(nodeId)   → component/layer names + node ids (use for the component table)
get_screenshot(nodeId) → visual + a URL to curl into screenshots/ (expires fast — download immediately)
get_design_context     → only when implementing, for tokens/measurements
```

Prefer `get_metadata` when building the component table: it gives exact component names and variant lists, which is what the plan needs.

## Writing cadence

Detailed epic plans are written **just-in-time**, as an epic is about to start — not all up front, where they'd drift. Keep [plans/PLAN.md](../../../plans/PLAN.md) current with the epic summaries and sequence; expand one folder at a time.

## See also

- [AGENTS.md](../../../AGENTS.md) — coding standards the plan must respect
- [DEV.md](../../../DEV.md) — the commands the DoD refers to
- **`laravel-package`** skill — package mechanics (providers, publishing, Testbench)
