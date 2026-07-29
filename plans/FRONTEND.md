# Frontend Architecture

How the React app is organised, and the inventory of basic elements with the epic that introduces each. Architecture rules live here; per-epic component lists (with build status) live in each epic's `PLAN.md`.

> The layer definitions are also summarised in [AGENTS.md](../AGENTS.md); this document adds the element inventory and the MVP-only Figma mapping.

## Layers

Four layers, each allowed to import only from the ones above it.

```
resources/js/
├── elements/     1. Basic elements   — primitives styled with Figma styles/tokens
├── components/   2. Components       — 1:1 with a named Figma component
├── composed/     3. Composed         — several Figma components assembled (optional layer)
├── pages/        4. Pages            — route wrappers: data, hooks, wiring, layout
├── hooks/            shared hooks (data fetching, UI state)
├── lib/              api client, theme, config, utils
├── types/            API payload types
└── styles/           design tokens (app.css)
```

| Layer | Contains | Imports from | Never |
|-------|----------|--------------|-------|
| **1. Elements** | Generic UI primitives: buttons, links, inputs, dropdowns, tooltips, badges, checkboxes, tabs, dialogs, table primitives — Radix/shadcn where useful, restyled to our tokens | `lib/utils`, Radix, lucide | Knows nothing about agents, conversations, or the API |
| **2. Components** | Single-purpose domain components: `AgentCard`, `ToolTag`, `StatusBadge`, `ChatInput`, `HistoryRow`, `ThemeSwitcher` | elements, `lib`, `types` | No data fetching, no routing decisions |
| **3. Composed** | Larger units assembled from several components: `AppShell`, `AgentGrid`, `HistoryFilterBar`, `MessageThread`, `InfoPanel` | elements, components, `lib`, `types` | No route-level data loading (props in, events out) |
| **4. Pages** | One per route: fetches data via hooks, owns page state, composes layers 2–3 | everything | No bespoke styling that belongs in a component |

**Rules**

- **Elements are the only place Radix is imported.** Everything else consumes our element API, so swapping a primitive is one file.
- **Never hard-code colours.** Elements and components use `--color-*` tokens from `styles/app.css`; both themes are token sets, so no component knows which theme is active.
- **Icons come from `lucide-react`.** No inline SVG.
- **Composed is optional.** Don't invent a composed component for a single-use grouping inside a page — put it in the page until it's reused or the page gets hard to read.
- **Logic lives in pages and hooks**, not in components. A component that needs data takes it as props.

### Relationship to Figma (MVP only)

For the MVP build-out, layers 1–3 map closely to the Figma component sheet — an element or component usually corresponds to a named Figma component, and we name ours after theirs (`Tool Tag` → `ToolTag.tsx`) so plans and designs are easy to cross-reference. Where there is no counterpart (e.g. `ThemeSwitcher`), a header comment says so.

**This is a one-time delivery convenience, not a contract.** We are not committing to keep the codebase mirroring Figma as the product grows — after the MVP, the layer definitions above stand on their own and components evolve with the code. Figma node ids belong in `plans/`, never in `AGENTS.md` or component docblocks as a rule to uphold.

## Basic elements inventory

Which primitive each epic introduces. Built on demand — an element appears when the first component needs it, never speculatively.

| Element | Figma source | Epic | Notes |
|---------|--------------|------|-------|
| `DropdownMenu` | `Dropdown item` `324:25180`, `More` `329:3275` | 1 ✅ | Trigger + content + item; swap internals for Radix when submenus are needed |
| `SidebarItem` | `Navigation` `355:9764` | 1 ✅ | Shared row style for nav links and non-link rows |
| `Button` | `CTA Button` `279:2791` | 1 | Default / hover / disabled |
| `Badge` | `Tool Tag` `171:1262`, `Status Badges` `327:2670` | 1 | Chip + status weights |
| `Tooltip` | `Tooltip` `248:5638` | 1 | Radix tooltip |
| `Card` | `Card` `367:11252` | 1 | Border + surface, no shadow |
| `Skeleton` | — | 1 | Loading shimmer on `--color-muted` |
| `Tabs` | `Tabs` `187:1382` | 2 | Config / Prompt / Tools |
| `Table` | `Data Table / TableHead` | 2, 6 | Tool parameter tables, history rows |
| `JsonView` | tool card bodies | 4 | Hand-rolled pretty-print + token colouring. The design is a formatted block, not an interactive tree, so a library isn't worth its weight in a bundle that's inlined on every page load |
| `Markdown` | Info panel Prompt tab | 2 | `react-markdown` wrapper |
| `Copy` | `Copy` `408:5907` | 4 | Default / hover / copied |
| `Collapsible` | `Inline Tool Call cards` `271:2353` | 3 | Expand/collapse — first needed by the error card's stack trace |
| `Textarea` | `Chat Input` `400:6362` | 3 | Auto-growing composer field |
| `Select` | `Models` `355:18178` | 5 | Model selector |
| `FileChip` | `File chip` `623:27074`, `630:5906` | 5 | Image / audio / document |
| `Input` | `Input` `514:5003` | 6 | 3 variants |
| `Checkbox` | `Checkbox` `422:6197` | 6 | Filter menus |
| `Calendar` | `Calendar` `422:5716` | 6 | Date-range picker |
| `Pagination` | history pager | 6 | Numbered + prev/next |
| `Dialog` | `Modal/Rename` `429:5900`, `Modal/Delete` `429:5878` | 6 | Radix dialog |
| `SearchField` | `Filter Agents Button` `494:6834` | 6 | Default / empty / filled |

## shadcn

`components.json` points the `ui` alias at `@/elements`, so `npx shadcn@latest add <name>` lands in the elements layer. Every generated file is then **restyled to Figma tokens** — stock shadcn colours are never shipped. Some elements (like `DropdownMenu` above) start as a small hand-rolled implementation and adopt Radix when the interaction demands it.

## Theming

Both light and dark token sets exist in Figma. `styles/app.css` defines `:root` (light) and `.dark` (dark); the blade layout applies the stored-or-OS preference inline before first paint to avoid a flash, and `lib/theme.ts` keeps `system` in sync afterwards.

The **theme switcher** lives in the sidebar Workspace menu beside Discovery and History (`components/ThemeSwitcher.tsx`). It has no dedicated Figma component yet — it reuses the `Navigation` row and `Dropdown item` styles so it matches today and can be swapped when the designer publishes one.

## See also

[PLAN.md](PLAN.md) · [AGENTS.md](../AGENTS.md) · the **`plan-epic`** skill
