# Synapse — Development Plan

High-level roadmap: phases, epics, and sequence. Each epic has its own folder here with a detailed plan (acceptance criteria, Figma components, API surface, tests).

**Sources of truth:** [GOAL.md](../GOAL.md) (user-facing behavior) · [PRD.md](../PRD.md) (technical spec) · [Figma](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse) (visual) · [AGENTS.md](../AGENTS.md) (how we build) · [DEV.md](../DEV.md) (commands) · [FRONTEND.md](FRONTEND.md) (React layering + element inventory).

## Approach

**Vertical slices.** Every epic delivers a complete, user-visible capability — backend, API, UI, and tests together — and is independently shippable. No "backend phase" followed by a "frontend phase"; you can install Synapse after any epic and it does something real.

**Definition of done for every epic** (in addition to its own ACs):
- Behavior matches PRD/GOAL for the feature
- `composer check` green (Pint · PHPStan · Pest)
- Feature tests for the backend; browser test for the user-visible flow (`composer test:e2e`)
- Both themes verified (light + dark)
- `dist/` rebuilt and committed
- PRD/GOAL updated if a decision changed

## Epic sequence

| # | Epic | Delivers | Size | Depends on |
|---|------|----------|------|-----------|
| 0 | **Scaffold** ✅ done | Installable package, migrations, empty SPA, CI gates, e2e harness | — | — |
| 1 | [Agent Discovery](epic-01-discovery/PLAN.md) ✅ done | Install → your real agents appear on the dashboard | M | 0 |
| 2 | [Agent Info Panel](epic-02-info-panel/PLAN.md) ✅ done | Inspect any agent's full config, prompt, and tools | M | 1 |
| 3 | [Chat MVP](epic-03-chat-mvp/PLAN.md) ✅ done | Actually talk to an agent: streaming, persistence, errors, tokens | XL | 1 |
| 4 | [Tool Inspection](epic-04-tool-inspection/PLAN.md) | Inline tool cards: pending → success/error, args/results, provider tools | L | 3 |
| 5 | [Chat Advanced](epic-05-chat-advanced/PLAN.md) | Attachments, model selector, reasoning pane, structured output | L | 3, 4 |
| 6 | [History](epic-06-history/PLAN.md) | Searchable history, filters, replay, rename/delete, sidebar recents | L | 3, 4, 5 |
| 7 | [Release](epic-07-release/PLAN.md) | Install polish, docs, `about`, perf pass, v0.1.0 | M | all |

```
0 Scaffold ✅
      │
      ├─► 1 Discovery ✅ ──► 2 Info Panel ✅
      │        │
      │        └────────────► 3 Chat MVP ✅ ──► 4 Tool Inspection ──► 5 Chat Advanced ──► 6 History ──► 7 Release
```

### Why this order

- **Discovery first** — everything else needs agent resolution (slug → class) and metadata extraction. It's also Success Criterion #1 and immediately useful on its own.
- **Info Panel second** — small, read-only, and it forces the full tool-classification logic (`Tool` / `ProviderTool` / sub-agent / MCP) that tool cards later depend on. Cheap de-risking before the big epic.
- **Chat split into three** (3 → 4 → 5) — the playground is the largest surface in the product. Text streaming proves the whole pipeline (decorator → SSE → `useChat` → persistence) before tool cards, attachments, and reasoning pile on. Each sub-phase is shippable.
- **History after chat is complete** — replay must render every message type, so it's cheapest once attachments and tool cards already exist.
- **Release last** — polish, docs, and the manual e2e/install pass.

## Epic summaries

### Epic 1 — Agent Discovery
`AgentDiscovery` service (configurable paths, `Agent` contract filter, ignore list, slug resolution), metadata extraction via `TextGenerationOptions::forAgent()` + attribute/method precedence, `GET /api/agents`, Discovery page with real cards (name, provider/model, tool chips, `+N` overflow tooltip), sidebar Agents list, theme tokens ported from Figma for **both** themes.
**Success:** create an agent class → refresh → it appears with correct provider/model/tools.

### Epic 2 — Agent Info Panel
`GET /api/agents/{agent}` returning full config; tool classification mirroring the gateway's `resolveTool()`; Info Panel with Config / Prompt / Tools tabs, plus the `Collapsed` variant; parameter tables from `schema(JsonSchema)`; markdown-rendered instructions; middleware list.
**Success:** every value shown matches what the SDK would actually use at invocation time.

### Epic 3 — Chat MVP
Conversation + message persistence; `SynapseConversationalAgent` decorator with the **conversational vs stateless** split (and the Stateless badge); the single invocation catch-all (PRD Feature 6); the SSE emitter speaking the Vercel UI-message protocol (including the two gaps the SDK drops); `POST /api/chat/{agent}/send`; chat UI with streaming text, composer, per-message + conversation token counts, error cards, new/clear conversation, persistence across refresh.
**Success:** send a message, watch it stream, refresh the page, the thread is intact.

### Epic 4 — Tool Inspection
`SynapseRecorder` (`InvokingTool` → pending row, `ToolInvoked` → success, catch-all → error); provider-tool events upserted by `itemId`; tool stream parts emitted to the browser; inline tool cards with pending/success/error, collapsed/expanded, JSON viewer, copy button, and the distinct provider-tool variant.
**Success:** a tool call appears live as a pending card and resolves in place; a throwing tool shows a failed card plus an error card.

### Epic 5 — Chat Advanced
Attachments (upload to configured disk, `Stored*` classes, chips + dropzone, thumbnails in replay, cleanup on prune/clear); model selector (agent model + cheapest/smartest tiers + configured extras, recorded per message); reasoning "Thinking…" pane; structured-output JSON card.
**Note:** the reasoning pane's [`✦ Thinking…` state](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=531-5178&m=dev) is designed but still WIP (not yet a published component) — build to it and adjust if the designer refines it.

### Epic 6 — History
`GET /api/conversations` with search, agent/status/tools filters, date range, sort, pagination; conversation replay merging messages + tool invocations chronologically; `PATCH` rename and `DELETE` with modals; History page with the full filter bar; sidebar Recent Conversations wired with call counts and error indicators.
**Success:** find any past conversation and reopen it with every card intact.

### Epic 7 — Release
`synapse:install` UX polish, `AboutCommand` entry, README/docs pass, asset-publish-on-update guidance, bundle/query review, full manual e2e + real-install smoke test, CHANGELOG, tag v0.1.0.

## Conventions for epic folders

```
plans/epic-NN-name/
├── PLAN.md          # goal, scope, ACs, API, data, components, tests, DoD
└── screenshots/     # Figma exports for offline reference
```

Each epic's `PLAN.md` carries: goal · in/out of scope · acceptance criteria · Figma component links (node ids) · API surface · data touched · test plan · risks · definition of done.

**Detailed plans are written just-in-time**, when an epic is about to start — that keeps them from drifting as we learn. [Epic 1](epic-01-discovery/PLAN.md) is written and is the template for the rest.

## Design status

All designer-feedback items are addressed — the reasoning pane is a WIP in-file state rather than a published component (Epic 5 builds to it). Both light and dark component sets exist in Figma, so components are built theme-aware from Epic 1 onward rather than retrofitted. See [DESIGN_FEEDBACK.md](../DESIGN_FEEDBACK.md).
