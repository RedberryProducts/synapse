# SDK opportunities to revisit

Backlog reconciled: 2026-10-29, after Synapse v0.3.0. SDK capability research below dates from 2026-10-27 against Laravel AI v1.3.0; it is not a fresh SDK release review.

Internal planning notes only. These are candidates for future reassessment, not committed product scope or requests to create GitHub issues. Recheck the latest stable SDK and current Synapse implementation before scheduling any of them. Effort estimates are relative and provisional.

## Open maintenance work

Track these in GitHub rather than duplicating their acceptance criteria here:

- [#2 — PR checks and asset freshness](https://github.com/RedberryProducts/synapse/issues/2)
- [#3 — Lowest/latest SDK compatibility CI](https://github.com/RedberryProducts/synapse/issues/3)
- [#4 — Dependabot and a committed npm lockfile](https://github.com/RedberryProducts/synapse/issues/4)
- [#22 — Reactivate the Maestro workflow when needed](https://github.com/RedberryProducts/synapse/issues/22)

The older CI issues contain pre-1.0 SDK assumptions; reconcile them with the current manifest before implementation. This cleanup does not enable workflows or change dependencies.

## Candidates to recheck

### Live sub-agent output — small to medium

Goal: show partial output inside the running sub-agent tool card, so developers can see progress before the final result arrives.

SDK 1.3 emits preliminary output snapshots associated with the parent tool call. Synapse currently ignores them. Display snapshots as provisional content while keeping the card pending, then replace them with the final result. Never count preliminary snapshots as completed executions or add their usage to turn totals.

Recheck: snapshot semantics and frequency, failure behavior, final-result replacement, and whether a real HTTP smoke can demonstrate progress. Keep this separate from full nested-agent tracing and child-agent approvals.

Reference: [SDK generation loop](https://github.com/laravel/ai/blob/v1.3.0/src/Gateway/TextGenerationLoop.php).

### Video attachments — small to medium

Goal: upload a video and test an agent that accepts video input, initially targeting Gemini.

SDK 1.3 has StoredVideo and Gemini attachment mapping. Synapse currently classifies uploads as images, audio, or documents, so video falls into the document path. Start with correct SDK file classification, an appropriate attachment display, and persistence/replay coverage. Remote video URLs are separate scope.

Recheck: current provider/model support, upload limits and MIME handling, authenticated serving, and history round trips. Preserve provider error visibility for unsupported inputs; do not advertise universal video support.

Reference: [Gemini attachment mapping](https://github.com/laravel/ai/blob/v1.3.0/src/Gateway/Gemini/Concerns/MapsAttachments.php).

### Tool controls for experiments — medium

Goal: temporarily disable selected tools to compare agent behavior without editing application code.

The SDK exposes withTools(). A playground override should be visibly identified as an experiment, default to the agent's actual configuration, and persist the exact selection needed to resume an approval. Do not add automatic approvals or silently change the agent's normal tools.

Recheck: how runtime overrides interact with SDK-injected skills, provider tools, forced tool choice, failover, and approval validation. Prefer a narrow tool-selection feature before broader generation-setting controls.

Reference: [SDK Promptable](https://github.com/laravel/ai/blob/v1.3.0/src/Promptable.php).

### Skills inspection — medium, needs an integration spike

Goal: let developers inspect which skills an agent can load, then relate those skills to observed LoadSkill calls.

Synapse already forwards HasSkills and includes the injected LoadSkill tool. A complete Skills browser is additional work: the SDK's resolved skill collection is protected, and sources can include directories, Skill objects, and closures. Avoid duplicating SDK discovery rules or parsing tool descriptions as a substitute for a public API.

Recheck: whether the SDK exposes a supported inspection API, resolution/deduplication rules, safe handling of bundled files, and the minimum useful display. A read-only inventory is preferable to a skill editor for the initial scope.

Reference: [SDK LoadSkill](https://github.com/laravel/ai/blob/v1.3.0/src/Tools/LoadSkill.php).

### Conversational structured output — separate behavior change

Goal: allow structured-output agents that also implement Conversational to use prior Synapse turns.

Synapse deliberately keeps structured agents single-shot today. The SDK's non-streaming prompt path can receive conversation messages, but structured streaming is explicitly unsupported in v1.3.0. Treat history support and structured streaming as different proposals.

Recheck: schema fidelity, strict mode, original agent capabilities, attachments, tool results, and approval continuation. Preserve stateless behavior and Synapse-only storage. Agree on acceptance criteria and update README before changing the documented single-shot exception; only reconsider streaming if a stable SDK release supports it.

References: [SDK prompt path](https://github.com/laravel/ai/blob/v1.3.0/src/Providers/Concerns/GeneratesText.php), [SDK streaming restriction](https://github.com/laravel/ai/blob/v1.3.0/src/Providers/Concerns/StreamsText.php).

## Larger expansions, not quick wins

- **Full nested-agent tracing:** inspect child invocations, their steps, tools, failures, and usage. Recheck parent/child correlation and accounting before designing storage or a tree UI; preliminary output alone is not a complete trace.
- **Classification playground:** reassess the SDK's experimental API stability and whether a separate input/result workflow belongs in Synapse's text-agent development focus.
- **Image/audio generation:** reassess developer demand, provider capabilities, media storage/display, and usage accounting. These need dedicated workflows rather than just additional chat cards.
- **Provider-hosted or child-agent approval flows:** retain the current exclusions. Reconsider only when the latest stable SDK explicitly supports the required pause/resume semantics; do not emulate unsupported approval behavior.

## Before promoting a candidate

1. Check the latest stable release and tagged SDK source, not only unreleased branch changes.
2. Compare against current Synapse behavior and open issues; remove ideas already delivered.
3. Establish a small user-facing goal, SDK support boundaries, and testable acceptance criteria.
4. Preserve existing feature/browser coverage, SDK-shaped replay, and Synapse-owned storage.
5. Create a GitHub issue only when requested or when the feature is selected for implementation.

## Other deferred ideas

- **Conversation export:** JSON/Markdown export remains unimplemented. Recheck demand and define what happens to attachments and private replay data before proposing it.
- **Additional runtime qualification:** Sail remains expected rather than directly verified; Apache/mod_php has not been qualified. Octane remains unsupported. Reassess runtime demand and verify real HTTP behavior before expanding support claims.
