# Epic 7.2 — Cost & Correctness

**Goal:** the dashboard stays fast on a real application, and everything it claims in GOAL.md is either true or explicitly retracted.

Delivers PRD [Tech Stack & Architecture](../../PRD.md#tech-stack--architecture) · GOAL [Compatibility](../../GOAL.md#compatibility) · no single Success Criterion — this is the epic that keeps the other six honest.

**Depends on:** 7.1 (a slow runtime and a buffering runtime are different problems; prove delivery first).
**Blocks:** 7.4 (release).

> **Status: correctness half done, cost half open.** A review turned up four gaps
> between GOAL.md and the code — see [Correctness backlog](#correctness-backlog-triaged).
> All four are now closed ahead of the epic: **A** and **C** built, **B** and
> **D** retracted in the docs. What remains here is the cost work.

---

## Scope

**In — cost**

- Agent discovery cost on a large application, and whether the per-request rescan needs a cache
- Query review across the dashboard: the History list, conversation replay, sidebar recents
- Bundle review: `dist/app.js` is **515KB** uncompressed today
- Replay payload size for a long conversation with attachments and tool calls

**In — correctness**

- Triage of the four review findings, then implementation of whatever is adopted

**Out**

- New features. A finding that turns out to be a missing *feature* rather than a broken *claim* gets logged for post-v0.1.0, not absorbed here
- Micro-optimisation without a measurement to justify it
- Anything 7.1 covers (streaming delivery) or 7.3 covers (install, docs)

---

## Technical approach — cost

### 1. Discovery

`AgentDiscovery` is bound as a singleton and scans with Symfony `Finder`:

```php
// src/SynapseServiceProvider.php:20
// Singleton = discovery runs once per request, never persistently cached.
$this->app->singleton(AgentDiscovery::class);
```

```php
// src/Discovery/AgentDiscovery.php:89
foreach (Finder::create()->files()->in($paths)->name('*.php') as $file) {
```

The docblock states the intent — no persistent cache, so a newly written agent
appears on refresh with no cache-clear step. That is the right default for a dev
tool and Success Criterion #1 depends on it.

**What is unmeasured** is the cost. Every dashboard request re-scans the
configured paths, reflects on each class, and resolves provider/model metadata.
On a project with a handful of agents that is free. The question is where it
stops being free.

Measure first, then decide. If a cache is needed, the shape that preserves
Criterion #1 is an mtime-keyed cache, not a TTL — a file changes, the entry dies.
**No cache goes in without a measurement that justifies it.**

### 2. Queries

`ConversationQuery` already avoids the obvious N+1 with
`withExists(['messages as has_error' => …])`. Worth checking under a realistic
dataset:

- History list at 25/page with all filters active
- Sidebar recents on every page load
- Full replay of a long conversation — messages, tool invocations, attachments

Seed enough data to make an N+1 visible rather than theoretical.

### 3. Bundle

`dist/app.js` is 515KB uncompressed, `dist/app.css` 37KB. Both are inlined into
the layout by `Synapse::js()` / `Synapse::css()` rather than served as files
(PRD → Asset Delivery), so **the whole bundle is in the HTML of every dashboard
page load** and cannot be browser-cached separately.

Establish the gzip/brotli number first — inlined-in-HTML content is compressed by
the server, so the uncompressed figure overstates the cost. Then decide whether
anything is worth doing for v0.1.0. A dev tool on localhost has a very different
budget from a public app, and this is explicitly not a place to spend effort
without evidence.

### 4. Replay payload

A conversation with many tool calls carries full arguments and results per card.
Measure the JSON size of a heavy replay; if it is large, the question is whether
tool payloads should load on card expansion rather than up front — but again,
only with a number behind it.

---

## Correctness backlog (triaged)

Four gaps between GOAL.md and the code, from review. **All four are confirmed
against the source.**

The findings split into two kinds. **C** is a bug: two code paths disagree about
the same fact, and one of them is lying. **A**, **B** and **D** are documentation
debt — the code is defensible, the docs oversold. For those, "correct GOAL.md" is
a complete and legitimate fix for v0.1.0, and cheaper than building a feature to
match a sentence nobody committed to. The bar for building instead of retracting
is whether a developer would reasonably feel misled after installing.

### A. Sidebar row menu — ✅ **adopted, shipped ahead of the epic**

GOAL: *"Each has a menu to open, rename, or delete it."*
`SidebarConversationList.tsx` rendered a plain `<Link>`. Rename and delete
existed only on History rows. Epic 6's plan never scoped it either — a spec miss,
not a regression.

Built rather than retracted: the sidebar is on every page, so it is where you
reach for a conversation you were just in, and sending someone to History to
rename it is a detour the design never intended.

Implementation notes worth carrying forward:

- The actions moved into a shared `useConversationActions()` hook. Two copies of "what a delete means" is exactly how the two lists drift, and the hook also makes it impossible for a caller to forget the two easily-missed parts: broadcasting `conversationsChanged()` and dropping the resume pointer via `forget()`.
- The menu is a **sibling** of the row link, not a child — a button nested inside an anchor is invalid HTML and browsers disagree about which one owns the click.
- The trigger's accessible name is `Recent conversation actions for X`, deliberately different from History's `Actions for X`: both menus are on screen together, and a duplicated accessible name is ambiguous for a screen reader as well as for Playwright's strict mode.
- **It surfaced a second bug.** `useConversations` (History's own list) did not listen to the `conversationsChanged` broadcast, so a delete from the sidebar left the History table showing a row that no longer existed. Now fixed — every list reacts to the broadcast rather than only the one that issued the write.

### B. Citations — ❌ **retracted and logged**

GOAL's Compatibility table claimed Synapse inspects citations. `StreamEmitter`
does forward the SDK's `source-url` part, but `stream.ts:173` drops it into the
ignored `default` branch, and nothing is persisted on the message. No epic covers
it.

Verified against the installed SDK, two facts decided this:

- **Only 2 of 5 gateways emit citations while streaming.** `Anthropic` and `OpenRouter` yield `CitationEvent` from `HandlesTextStreaming`; OpenAI, Gemini and xAI parse citations only in `ParsesTextResponses`, the non-streaming path. Streaming is Synapse's main path, so a citations panel would sit empty for most setups.
- **The SDK's Vercel serialization drops the valuable half.** `Citation::toVercelProtocolArray()` returns `{type: 'source-url', sourceId, url}` — discarding `title`, `startIndex` and `endIndex`. Those indices map a source to the span of the answer it supports, which is the whole point: they separate an agent that searched and *used* what it found from one that searched, ignored the results, and wrote something plausible. Recovering them means carrying the raw event, a **third** workaround alongside the two `StreamEmitter` already applies to `ProviderToolEvent` and `ToolResult`.

Done properly this needs a serializer bypass, a persistence shape, a replay path
and a UI component — an epic, not a 7.2 item — and it would light up for two
providers out of five. A thin version (a bare list of source links, no titles, no
spans) was considered and rejected: shipping a weak version of a feature whose
strong version is genuinely valuable makes it harder to justify building the
strong one later.

**Done:** the Compatibility row no longer claims citations, and GOAL has a
**Planned** section explaining what they are and why they're absent.

### C. Provider-tool prefix missing live — ✅ **adopted, fixed**

Replay showed provider-native tool cards with the `provider /` prefix; the live
stream did not, because `useConversation` hardcoded `provider: null`. The same
call looked different during the run than after a refresh.

The only genuine defect of the four, and it matters where it hurts: the prefix is
how you tell whether a failure was yours or the provider's — the distinction Epic
4 built the lightning variant for.

**The obvious fix was the wrong one.** Reading the provider off `agent.provider`
in the UI would have been a two-line change, but replay does not read it from the
agent's configuration — it reads `meta.provider`, which the SDK reports as the
provider that *actually served the request*:

```php
// ConversationWriter, from the SDK response Meta
meta: array_filter([...$response->meta->toArray(), ...])
```

Those two values diverge after a failover — precisely the case where attribution
is worth having. So the fix announces the real provider on the stream instead:

- `StreamEmitter::provider()` emits `data-synapse-provider`
- `AgentInvoker::announceProvider()` listens for the SDK's `StreamingAgent` event and reads `$event->prompt->provider()->name()`, so it fires once the provider is resolved and again on a failover retry
- `useConversation` holds it in a ref and folds it into provider-tool cards as they are created

**A failover does not re-label cards already on screen**, and that is deliberate:
those cards belong to the attempt that failed, and the failover notice sitting
above them says so. Re-labelling them would have overwritten cards from earlier
turns too.

### D. Structured-output agents get no memory — ✅ **GOAL corrected, code unchanged**

`AgentInvoker::promptOnce()` invokes a `HasStructuredOutput` agent undecorated,
even when it is also `Conversational`. The reason is sound and now stated in both
places: `StreamsText::stream()` rejects any agent with structured output, and the
decorator that supplies conversation history cannot carry the
`HasStructuredOutput` contract without breaking every other conversational
stream.

GOAL's memory section gained the exception **and its reason**, so a developer
reading it learns something true about the SDK rather than hitting a surprise.
The stale *"Epic 5 revisits the rendering"* line in the code comment — Epic 5
shipped — was replaced with a pointer to the documented behaviour.

---

## Configuration

This epic introduces **no new config keys** unless a discovery cache proves
necessary, in which case a key is added to the PRD first.

---

## Acceptance criteria

Cost:

1. Discovery cost is measured on a project with a realistic number of agent classes, and the number is recorded in the plan.
2. Creating a new agent class and refreshing still lists it, with no cache-clear step — whatever the outcome of (1).
3. The History list, sidebar recents and a full replay each issue a bounded number of queries, verified against a seeded dataset rather than by reading code.
4. The compressed size of the inlined bundle is measured and recorded.

Correctness — **all four closed ahead of the epic:**

- **A** — rename and delete work from the sidebar menu, and a write from either list updates both. Two browser tests in `tests/Browser/HistoryTest.php`.
- **B** — GOAL's Compatibility row no longer claims citations; the **Planned** section explains their absence.
- **C** — the stream announces the provider before any tool part, and it matches the value replay reads from the stored message. Three feature tests in `tests/Feature/Chat/ProviderAnnouncementTest.php`.
- **D** — GOAL's memory section states the structured-output exception and its reason.

> **Not coverable by a browser test:** the live `provider /` prefix itself. The
> SDK's fake gateway replays tool calls but never emits a `ProviderToolEvent`
> (noted in `tests/Feature/Chat/ProviderToolTest.php`), so a faked run cannot
> produce a provider-tool card at all. The announcement and its agreement with
> replay are asserted at the feature tier; the rendering is manual-only.

---

## Code map

| Area | Path |
|------|------|
| Discovery | `src/Discovery/AgentDiscovery.php` · `src/SynapseServiceProvider.php` |
| Queries | `src/Repositories/ConversationQuery.php` · `src/Http/Resources/` |
| Bundle | `vite.config.ts` · `dist/` |
| Sidebar menu (A) | Done — `resources/js/components/SidebarConversationList.tsx` · `resources/js/hooks/useConversationActions.tsx` |
| Citations (B) | Retracted — `GOAL.md` → Planned |
| Provider prefix (C) | Done — `src/Chat/StreamEmitter.php` · `src/Chat/AgentInvoker.php` · `resources/js/lib/stream.ts` · `resources/js/hooks/useConversation.ts` |
| Structured memory (D) | Done — `GOAL.md` · `src/Chat/AgentInvoker.php` (comment) |

---

## Tests

### Feature

- Discovery still finds a class written after the container booted
- Query counts on the History list, recents and replay, asserted against a seeded dataset
- Per adopted correctness finding: a test that fails against today's code

### Browser

- Only where an adopted finding changes the UI

### Fixtures

- A seeder producing enough conversations, messages and tool invocations to make an N+1 visible

---

## Risks

| Risk | Mitigation |
|------|------------|
| A discovery cache breaks Success Criterion #1 | AC 2 is non-negotiable; mtime-keyed, never TTL |
| Optimising without evidence | Every cost AC requires a recorded measurement; no change ships without one |
| Triage silently becomes a feature epic | The triage rule: correcting an oversold doc is a complete fix. Anything larger is logged for post-v0.1.0 |
| GOAL.md keeps drifting from the code | 7.3's docs pass reads GOAL end to end against the shipped behaviour — this review is evidence that is worth doing |

---

## Definition of done

- Every cost AC has a recorded number, not an assertion
- All four findings triaged, each with a decision and a reason written into this plan
- Adopted findings implemented and tested; excluded findings corrected in GOAL.md
- `composer check` green and `composer test:e2e` green
- `dist/` rebuilt and committed if the bundle changed
- **PRD updated** if a cache or a new config key was introduced
- **GOAL updated** for every excluded finding
