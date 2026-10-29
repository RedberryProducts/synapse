---
name: plan-epic
description: Plan unfinished Synapse features from the user's request, GitHub issue acceptance criteria, the current implementation, and verified SDK APIs. Use when starting an epic, enriching a future plan, or breaking a feature into implementation steps.
---

# Planning a Synapse feature

Start with the user's request and the active GitHub issue. Read [AGENTS.md](../../../AGENTS.md) and [DEV.md](../../../DEV.md) for conventions and checks. Use [README.md](../../../README.md) and [UPGRADE.md](../../../UPGRADE.md) for supported behavior and migration constraints.

## Gather evidence

- Read the issue's goal, scope, acceptance criteria, and relevant discussion. User instructions take precedence; identify unresolved contradictions rather than silently expanding scope.
- Inspect current source and tests before proposing new helpers or abstractions. Consult closed issues and merged PRs for earlier decisions when relevant.
- Verify SDK facts against the installed/tagged source. `references/` is read-only research material and may be stale; record the version inspected. Use released APIs, not assumptions from an unreleased branch.
- For UI work, reuse the component layers and tokens documented in AGENTS. Consult Figma only when the feature has a relevant design; record the exact design reference and distinguish it from an implementation choice.
- Read `config/synapse.php` for current keys. Propose new configuration only when the feature needs it.

## Write a focused plan

Scale detail to the task. A small change needs a short plan; a substantial feature should cover:

1. **Goal and scope:** user-visible outcome, issue link, acceptance criteria, and explicit exclusions.
2. **Evidence and decisions:** SDK version/APIs, current implementation, and material tradeoffs or uncertainties.
3. **Implementation sequence:** concrete steps, affected classes/components/routes/storage, and verification for each step. Reuse existing primitives before adding new ones.
4. **Contracts and migration:** request/response shapes, persistence, replay, and upgrade behavior only where changed. Synapse never modifies SDK conversation tables.
5. **Tests:** map acceptance criteria to feature tests and, for UI/chat changes, browser tests. Automated browser tests use faked agents. Verify real HTTP flushing separately if streaming mechanics change.
6. **Risks and completion:** unresolved decisions, relevant regression coverage, documentation updates, and the applicable gates from DEV.

For frontend work, follow the four layers in AGENTS: elements, components, composed, and pages. Identify only components actually affected, with whether each is reused, changed, or introduced. Include both themes, narrow layouts, reload, and failure/approval behavior where relevant.

## Keep plans temporary

Use the issue/PR for the main discussion. Create `plans/<feature>/PLAN.md` only when a substantial unfinished task needs a local plan; no master roadmap or completed-plan archive is required. Keep speculative SDK ideas in [SDK-OPPORTUNITIES.md](../../../plans/SDK-OPPORTUNITIES.md) until selected.

Before removing a completed plan, preserve unique unfinished work in an open issue or the future backlog, record implementation/validation in the PR, and update maintained documentation. Remove unreferenced plan assets with the plan. Git history and merged PRs retain completed work.

## Definition of done

- The implementation meets the agreed acceptance criteria.
- `composer check` passes; `composer test:e2e` passes when frontend/chat behavior changes.
- Rebuild committed `dist/` when frontend source changes.
- Update README/UPGRADE for behavior or migration changes, DEV for commands, and AGENTS for lasting contributor rules.
- Describe validation and remaining limitations in the PR without exposing credentials or local application data.
