# Epic 6 — History

**Goal:** find any conversation you've had — by agent, by what you asked, by whether it failed — and reopen it with every message, tool card and attachment intact.

Delivers PRD [Feature 5](../../PRD.md#feature-5-invocation-history) · GOAL [History](../../GOAL.md#history) · Success Criterion **#6** (history persists, past conversations loadable with full message + tool call state).

**Depends on:** Epics 3–5 — replay has to render every message type, and after Epic 5 that set is complete.
**Blocks:** Epic 7 (release polish).

---

## Design

- **History screen:** [History](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=355-8263&m=dev) `355:8263` — [`screenshots/history.png`](screenshots/history.png)
- **Rename modal:** [`Modal/Rename`](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=429-5900&m=dev) `429:5900` — [`screenshots/modal-rename.png`](screenshots/modal-rename.png)
- **Delete modal:** [`Modal/Delete`](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=429-5878&m=dev) `429:5878`

| Figma component | Node | Variants |
|-----------------|------|----------|
| `Input` | `514:5003` | Default · empty · filled — search field and rename input |
| `SearchField` / `Filter Agents Button` | `494:6834` | Default · empty · filled |
| `Checkbox` | `422:6197` | Filter menus (Agents, Status, Tools) |
| `Calendar` | `422:5716` | Date-range picker |
| `Data Table / TableHead` | — | Agent · Message · Status · Tokens · Date & Time |
| `Modal/Rename` · `Modal/Delete` | `429:5900` · `429:5878` | — |
| `Navigation` | `355:9764` | Sidebar recent-conversation rows |
| Pagination | in `355:8263` | Previous · numbered · `…` · Next |

**What the design shows**

- **Header** — `History (140)` with the count beside the title, and the corrected subtitle "View your previous conversations and continue where you left off" (DESIGN_FEEDBACK #6).
- **Filter bar** — Search, then `Agents`, `Status`, `Tools` dropdowns each carrying a **count badge** when active, a date-range control (`09.10.24 - 18.10.24`), and `Sort By: Newest First` pushed right.
- **Table** — Agent · Message · Status (a green check or a red warning glyph, no text) · Tokens (`3.5k`) · Date & Time (`Oct 23, 2026  10:45AM`) · a `⋯` row menu.
- **Pagination** — `Previous  1 2 3 …  Next`, centred.
- **Sidebar recents** — agent name, `8 calls`, the truncated title, and a red warning glyph when the conversation contains an error.

**Design gaps handled here**

- No **empty state** for a history with no conversations, and none for a filter combination that matches nothing. Both are built from the existing `EmptyState`, with different copy — "nothing yet" and "nothing matches" are different problems and should not read the same.
- No **read-only** playground state for a conversation whose agent no longer exists (see [Decisions](#decisions)).

---

## Decisions

Confirmed before planning:

1. **A conversation whose agent is gone opens read-only.** Delete or rename an agent class and its conversations remain — they are Synapse's own records. History lists them, and clicking one replays the full thread with a notice that the agent no longer exists and a disabled composer. Listing a row you can't open would read as a bug; hiding it would silently drop data nobody deleted.
2. **The dead `POST /api/conversations/clear` route is removed.** It has returned 204 and done nothing since scaffolding, and the design has no clear-all control. `synapse:clear` already does the job from the CLI. A route that pretends to work is worse than no route.
3. **Per-agent resume** — carried over from the Epic 3 supersession recorded in [plans/PLAN.md](../PLAN.md#epic-6--history). Opening an agent returns you to the conversation you were last in, or to a fresh page if that is where you deliberately were. `localStorage` keyed by agent slug: per-browser UI state, not stored data, so it never resurrects a thread on a machine you have never used.

---

## Scope

**In**

- `GET /api/conversations` — search, agent/status/tools filters, date range, sort, pagination (25/page)
- `PATCH /api/conversations/{id}` — rename
- History page: header with count, full filter bar, table, row menu, pagination, both empty states
- Rename and delete modals
- Sidebar Recent Conversations, with call counts and the error indicator
- Per-agent resume (Decision 3)
- Read-only replay for an orphaned conversation (Decision 1)

**Out**

- **Cross-conversation search inside tool arguments/results** — search covers titles and message content, as the PRD specifies
- Bulk selection or bulk delete
- Export (JSON/markdown) — noted in GOAL as out of scope for the MVP
- Any change to how conversations are *recorded*; this epic only reads what Epics 3–5 write

---

## Frontend components to use

Layers per [plans/FRONTEND.md](../FRONTEND.md). **Status:** `Done` · `Create` · `Adjust`.

### 1. Elements (`resources/js/elements/`)

| Element | Status | Figma | Used by | Notes |
|---------|--------|-------|---------|-------|
| `Badge` · `Button` · `Card` · `Tooltip` · `Skeleton` | Done | — | filter counts, actions, loading rows | — |
| `DropdownMenu` | Done | `Dropdown item` `324:25180` | filter menus, row menu, sort | — |
| `SidebarItem` | Done | `Navigation` `355:9764` | recents rows | — |
| `Input` | **Create** | `Input` `514:5003` | search, rename | Text input with the three designed states |
| `Checkbox` | **Create** | `Checkbox` `422:6197` | multi-select filters | — |
| `Table` | **Create** | `Data Table / TableHead` | `HistoryTable` | Header/row/cell primitives only — no sorting or data logic |
| `Dialog` | **Create** | `Modal/Rename` `429:5900` | rename, delete | Radix dialog: focus trap, `Escape`, backdrop click |
| `Pagination` | **Create** | in `355:8263` | history pager | Previous / numbered / `…` / Next |
| `DateRangePicker` | **Create** | `Calendar` `422:5716` | filter bar | Two-month range picker. The largest new element — see [Risks](#risks) |

### 2. Components (`resources/js/components/`)

| Component | Status | Figma | Responsibility |
|-----------|--------|-------|----------------|
| `HistoryRow` | **Create** | `355:8263` | One conversation: agent, title, status glyph, abbreviated tokens, date, `⋯` menu |
| `HistoryFilters` | **Create** | `355:8263` | Search + Agents/Status/Tools + date range + sort; emits one filter object |
| `FilterMenu` | **Create** | `494:6834` | A multi-select dropdown with a count badge — used three times |
| `RenameDialog` | **Create** | `429:5900` | Title, subtitle, input, Save / Cancel |
| `DeleteDialog` | **Create** | `429:5878` | "This action cannot be undone", destructive confirm |
| `SidebarConversationList` | **Create** | `355:9764` | Recents with call count and error glyph |
| `EmptyState` | Done | — | Reused for both empty cases with different copy |
| `PageHeader` | **Adjust** | `355:8263` | Accept a count beside the title (`History (140)`) |
| `StatelessNotice` | Done | — | Pattern reused for the orphaned-agent notice |

### 3. Composed (`resources/js/composed/`)

| Component | Status | Figma | Responsibility |
|-----------|--------|-------|----------------|
| `HistoryTable` | **Create** | `355:8263` | Header + rows + pagination; props in, events out |
| `AppShell` | **Adjust** | — | Replace the hardcoded "No conversations yet." with `SidebarConversationList` |
| `PlaygroundShell` | **Adjust** | — | Show the orphaned-agent notice and disable the composer when the agent is gone |

### 4. Pages (`resources/js/pages/`)

| Page | Status | Responsibility |
|------|--------|----------------|
| `History` | **Adjust** | Replace the placeholder: own filter state (synced to the URL), fetch, rename/delete, navigation to a conversation |
| `Playground` | **Adjust** | Remember the last conversation per agent slug; restore it on open |

### Data layer

| File | Status | Responsibility |
|------|--------|----------------|
| `types/conversation.ts` | **Create** | `ConversationSummary`, `ConversationFilters`, `Paginated<T>` |
| `hooks/useConversations.ts` | **Create** | List + filters + pagination; debounced search |
| `hooks/useRecentConversations.ts` | **Create** | The sidebar's short list, refreshed when a conversation is written |
| `lib/api.ts` | **Adjust** | `getConversations(filters)`, `renameConversation(id, title)` |
| `lib/lastConversation.ts` | **Create** | The per-agent `localStorage` record behind Decision 3 |

### Styling

`styles/app.css` — table row hover/border tokens and the calendar's selected-range states. No new colours: the status glyphs reuse `--color-success` and `--color-destructive`.

---

## Configuration

**No new keys.** Reads `synapse.storage.connection` (via the models) and `synapse.ui.path` (for links). Retention keys are read by the existing commands, not by this epic.

---

## Technical approach

### 1. The list query

```php
SynapseConversation::query()
    ->withCount('toolInvocations')
    ->withSum('messages as prompt_tokens', 'prompt_tokens')
    ->withSum('messages as completion_tokens', 'completion_tokens')
    ->withExists(['messages as has_error' => fn ($q) => $q->where('role', 'error')])
    ->orderBy('updated_at', $sort === 'oldest' ? 'asc' : 'desc')
    ->paginate(25);
```

`withExists` is what makes **status** a single query rather than N+1 — a conversation is `error` if any message row has `role = error`, `success` otherwise.

**Status is message-level, never tool-level.** A failed tool does **not** fail the conversation: agents routinely recover from one and answer anyway, and marking the whole conversation red would train you to ignore the column. Epic 4's `synapse_tool_invocations.status` is deliberately not consulted here.

**Trap: `withSum` aliases collide with real columns.** `withSum('messages as prompt_tokens', ...)` puts an aggregate on the conversation model under a name that also exists on `synapse_messages`. Harmless here because the two models are separate, but the alias must not shadow a column on `synapse_conversations` itself — hence `prompt_tokens` (which that table doesn't have) rather than `title`.

### 2. Filters

| Filter | Query |
|--------|-------|
| `search` | `where(fn ($q) => $q->where('title', 'like', "%{$s}%")->orWhereHas('messages', fn ($m) => $m->where('content', 'like', "%{$s}%")))` |
| `agents[]` | `whereIn('agent_class', $classes)` — resolved from slugs via the discovered set, never by transforming a slug into a class name |
| `status` | `whereHas` / `whereDoesntHave('messages', fn ($q) => $q->where('role', 'error'))` |
| `tools[]` | `whereHas('toolInvocations', fn ($q) => $q->whereIn('name', $tools))` |
| `from` / `to` | `whereDate('updated_at', '>=' / '<=')` |

The **agent filter takes slugs and maps them through discovery**, the same rule Epic 1 set: a slug is only ever resolved by looking it up among discovered agents, so a crafted query parameter can't name an arbitrary class. Conversations whose agent is no longer discovered are unreachable by that filter but still appear unfiltered — which is correct, since you can't filter by something the app no longer knows about.

**Tool names for the filter menu** come from `distinct` on `synapse_tool_invocations.name`, not from discovery: the point is to filter by what actually ran, including tools that have since been deleted.

### 3. Orphaned conversations (Decision 1)

The list resolves each row's display name from `agent_class` with `class_basename()`, so a row renders whether or not the class still exists. `agent_slug` is included for linking.

The conversation endpoint gains one field:

```php
'agent_available' => $discovery->find(AgentSlug::make($conversation->agent_class)) !== null,
```

`Playground` currently 404s when `useAgent` reports `notFound`. It now checks for a conversation id first: with one, it renders the thread read-only plus a notice; without one, the existing not-found state stands. The replay endpoint never needed the agent to exist — it reads rows — so this is a UI branch, not new backend work.

### 4. Per-agent resume (Decision 3)

```ts
// lib/lastConversation.ts
remember(slug, conversationId | null)   // null when a fresh thread is deliberate
recall(slug): string | null
```

`Playground` reads it on mount when the URL carries no `?c=`, and writes on every conversation change including **New conversation**, which stores `null` so a deliberate blank page stays blank. Cleared for a conversation that has been deleted, so a stale id can't send you to a thread that no longer exists.

This supersedes the Epic 3 behaviour and needs the GOAL passage that documents "always opens empty" rewritten.

### 5. Rename

`PATCH /api/conversations/{id}` with `{title}`, validated `required|string|max:255`. Titles are never model-generated — not on creation (Epic 3 truncates the first message) and not here. The SDK has a `generate_title` behaviour; Synapse deliberately does not use it, because a debugging tool should not spend a provider call on cosmetics.

### 6. Sidebar recents

The same list endpoint with `per_page=5`, no filters, sorted newest-first. It has to refresh when a conversation is created or renamed — the list lives in `AppShell` while writes happen in `Playground`, so a small counter in shared state (or a re-fetch on route change) triggers it. Re-fetching on navigation is enough for the MVP and avoids introducing a store for one list.

---

## API

### `GET /synapse/api/conversations`

```
?search=headphones&agents[]=app.agents.support-agent&status=error
&tools[]=SearchProductsTool&from=2026-07-01&to=2026-07-30&sort=newest&page=2&per_page=25
```

```jsonc
{
  "data": [
    {
      "id": "0198f…",
      "agent_class": "App\\Agents\\SupportAgent",
      "agent_slug": "app.agents.support-agent",
      "agent_name": "SupportAgent",
      "agent_available": true,          // false once the class is gone
      "title": "Find me a hoodie",
      "status": "success",              // success | error — message-level only
      "tool_calls": 8,
      "prompt_tokens": 3200,
      "completion_tokens": 300,
      "total_tokens": 3500,
      "created_at": "2026-07-23T10:45:00+00:00",
      "updated_at": "2026-07-23T10:47:12+00:00"
    }
  ],
  "meta": { "current_page": 2, "last_page": 6, "per_page": 25, "total": 140 },
  "filters": {
    // Everything the filter menus need, so the page needs one request
    "agents": [{ "slug": "app.agents.support-agent", "name": "SupportAgent" }],
    "tools": ["SearchProductsTool", "web_search_call"]
  }
}
```

### `PATCH /synapse/api/conversations/{id}`

```jsonc
{ "title": "Hoodie search, take three" }   // → 200 with the updated summary; 404 unknown; 422 empty
```

### Removed

`POST /synapse/api/conversations/clear` — dead since scaffolding (Decision 2).

---

## Acceptance criteria

1. The History page lists past conversations newest-first, with agent, title, status glyph, abbreviated token total and date; the header shows the total count.
2. Typing in Search narrows the list by conversation title **and** by message content, without a full page reload.
3. Each of Agents, Status and Tools filters the list and shows a count badge while active; combining them narrows further.
4. The date range restricts results to conversations active within it.
5. Sort toggles between Newest and Oldest First.
6. With more than 25 results, pagination appears and moving pages keeps the active filters.
7. Reloading the page with filters applied restores the same view — filters live in the URL.
8. Clicking a row opens the conversation with every user message, assistant answer, tool card, error card and attachment intact.
9. `⋯ → Rename` opens the modal, saves a new title, and the row and sidebar both show it. The title is never model-generated.
10. `⋯ → Delete` asks for confirmation, removes the conversation, its messages, tool rows and stored files, and the row disappears.
11. A history with no conversations shows a "nothing yet" empty state; a filter combination matching nothing shows a distinct "nothing matches" state with a way to clear the filters.
12. The sidebar lists recent conversations with their agent, call count and an error indicator, and clicking one opens it.
13. Reopening an agent from Discovery returns to the conversation you were last in; after **New conversation** it returns to a fresh page.
14. A conversation whose agent class no longer exists still appears in History, opens read-only with the full thread, and says why the composer is disabled.
15. A conversation is marked `error` only when a message failed — a recovered tool failure still reads `success`.
16. All of the above in **both themes**, behind the `viewSynapse` gate.

---

## Code map

| Area | Path |
|------|------|
| List query, filters, pagination | `src/Repositories/ConversationQuery.php` |
| List + rename endpoints | `src/Http/Controllers/ConversationsController.php` (Adjust) |
| Summary payload | `src/Http/Resources/ConversationSummary.php` |
| Routes | `routes/web.php` — wire list + patch, remove the clear stub |
| History page | `resources/js/pages/History.tsx` · `resources/js/composed/HistoryTable.tsx` |
| Filters | `resources/js/components/{HistoryFilters,FilterMenu}.tsx` |
| Rows + dialogs | `resources/js/components/{HistoryRow,RenameDialog,DeleteDialog}.tsx` |
| Sidebar | `resources/js/components/SidebarConversationList.tsx` · `resources/js/composed/AppShell.tsx` (Adjust) |
| New elements | `resources/js/elements/{Input,Checkbox,Table,Dialog,Pagination,DateRangePicker}.tsx` |
| Resume | `resources/js/lib/lastConversation.ts` · `resources/js/pages/Playground.tsx` (Adjust) |

---

## Tests

### Feature (`tests/Feature/History/`)

- **`ConversationListTest`** — newest-first order; the summary shape; token sums and tool-call counts match the rows; pagination meta. (AC 1, 6)
- **`ConversationListTest`** — status is `error` only when a message failed, and **a conversation with a failed tool but no error message is `success`**. (AC 15)
- **`ConversationFilterTest`** — search hits titles *and* message content; agents, status, tools, date range each narrow; combinations compose. (AC 2, 3, 4)
- **`ConversationFilterTest`** — an unknown agent slug filters to nothing rather than erroring, and never resolves to an arbitrary class. (AC 3)
- **`ConversationFilterTest`** — the `filters` payload lists tool names that actually ran, including one whose tool class no longer exists. (AC 3)
- **`RenameTest`** — renames, 404s on unknown, 422s on empty, and leaves messages untouched. (AC 9)
- **`OrphanedConversationTest`** — a conversation whose class is gone still lists, reports `agent_available: false`, and replays in full. (AC 14)
- **`ConversationListTest`** — behind the gate. (AC 16)
- **Removed route** — `POST /api/conversations/clear` 404s. (Decision 2)

### Browser (`tests/Browser/HistoryTest.php`)

- The table lists seeded conversations; the header count matches
- Search narrows; a filter badge appears; clearing restores
- Filters survive a reload (URL state)
- Row click opens the conversation with its tool card intact
- Rename → the row and sidebar update
- Delete → confirm → the row goes
- Both empty states
- Sidebar recents show call counts and the error glyph
- Reopening an agent lands on the last conversation; after New conversation it lands blank
- Both themes

New testids: `history-table`, `history-row`, `history-search`, `filter-agents`, `filter-status`, `filter-tools`, `history-empty`, `history-no-matches`, `rename-dialog`, `delete-dialog`, `sidebar-conversations`, `pagination`.

### Fixtures

Conversations are seeded through the existing chat pipeline with `fakeAgent()` rather than by inserting rows, so the tests exercise the same data the product writes. A helper seeds N conversations across two agents, one containing an error message and one a failed tool with a successful answer — the pair that pins AC 15.

---

## Risks

| Risk | Mitigation |
|------|------------|
| `DateRangePicker` is the biggest new element and easy to over-build | Two-month range, keyboard-navigable, no presets. If it slips, the filter bar ships with the other four controls and the date range follows — it is the least-used filter on a dev tool where most conversations are from today |
| Search across message content is a `LIKE` join that grows with history | Bounded by `synapse:prune` and by this being a dev-time dataset. `synapse_messages.conversation_id` is already indexed; if it ever matters, the fix is a proper index or FTS, not a smaller feature |
| Sidebar recents and the History page drift out of sync after a write | Both read the same endpoint; recents re-fetch on navigation rather than holding their own cached copy |
| A stale `localStorage` id points at a deleted conversation | The replay fetch 404s and the playground falls back to a fresh thread, clearing the record |
| Filters in the URL make for ugly links | Accepted: reload-survivability and shareable filtered views are worth more than tidy URLs in a debugging tool |

---

## Definition of done

- All 16 acceptance criteria verified
- `composer check` green and `composer test:e2e` green
- Feature tests for the query, filters, rename and orphan handling; browser test for the visible flow
- Both themes verified on the table, filter menus, both empty states and both modals
- `dist/` rebuilt and committed
- **PRD updated:** Feature 5's row-menu/actions list reconciled if anything changed; the removed clear route noted in the HTTP API surface
- **GOAL updated:** History behaviour, per-agent resume (superseding the "always opens empty" passage), and what happens to a conversation whose agent is gone
- `plans/PLAN.md` Epic 6 row marked ✅ done
