# Epic 1 — Agent Discovery

**Goal:** install Synapse, open `/synapse`, and see the real agents from your project — name, provider/model, and tools — with a click-through into the (still empty) playground.

Delivers PRD [Feature 1](../../PRD.md#feature-1-agent-discovery) · GOAL [Agent discovery](../../GOAL.md#agent-discovery) · Success Criterion #1. First vertical slice: service → API → UI → tests.

**Depends on:** Epic 0 (scaffold) · **Blocks:** Epic 2 (info panel), Epic 3 (chat needs agent resolution)

---

## Design

- **Screen:** [Discovery](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=324-2362&m=dev) · local copy: [`screenshots/discovery-dark.png`](screenshots/discovery-dark.png)
- **Components:** [Components_Dark](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=187-2364&m=dev) · [Components_Light](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=656-5975&m=dev)

| Figma component | Node | Variants |
|-----------------|------|----------|
| `Card` | `367:11252` | Default · Variant2 (hover) |
| `Tool Tag` | `171:1262` | — |
| `Tooltip` | `248:5638` | — |
| `CTA Button` | `279:2791` | Default · Hover · Disabled |
| `Left Sidebar` | `324:14596` | Collapsed · Discovery · Inside |
| `Recent Conversations` | `196:1421` | Default · Hover |
| `Agents` (sidebar list) | `187:2620` | Default · Variant2 |
| `Navigation` | `355:9764` | SidebarMenuItem · Variant2 |
| `Icon` (chevron/collapse) | `447:5622` | Default · Hover |

---

## Frontend components to use

Every component this epic touches, in its layer per [plans/FRONTEND.md](../FRONTEND.md): **elements → components → composed → pages**. shadcn primitives land in `elements/` (the `ui` alias) and are restyled to our tokens — stock shadcn colours never ship.

**Status:** `Done` = exists, used as-is · `Create` = build it · `Adjust` = exists, needs the stated change.

### 1. Elements (`resources/js/elements/`)

| Element | Status | Figma | Used by | Notes |
|---------|--------|-------|---------|-------|
| `DropdownMenu` | Done | `Dropdown item` `324:25180` | `ThemeSwitcher` | Adopt Radix if submenus/typeahead are ever needed |
| `SidebarItem` | Done | `Navigation` `355:9764` | nav links, switcher trigger | Shared row style |
| `Button` | Create | `CTA Button` `279:2791` | card actions, sidebar collapse | Default / hover / disabled |
| `Card` | Create | `Card` `367:11252` | `AgentCard` | Border + surface, no shadow |
| `Badge` | Create | `Tool Tag` `171:1262` | `ToolTag`, tier pill | Chip + pill weights |
| `Tooltip` | Create | `Tooltip` `248:5638` | `ToolTagList` | Radix tooltip |
| `Skeleton` | Create | — | `AgentGrid` | Shimmer on `--color-muted` |

### 2. Components (`resources/js/components/`)

| Component | Status | Figma | Responsibility |
|-----------|--------|-------|----------------|
| `AgentCard` | Create | `Card` `367:11252` | One agent: name, `provider / model` (+ tier pill), tool chips, `Info` + `Open Playground` actions. Hover = Variant2. Unavailable agents render disabled with the error in a tooltip |
| `ToolTag` | Create | `Tool Tag` `171:1262` | One tool chip with a leading type icon (`tool` / `provider_tool` / `agent` / `mcp`) |
| `ToolTagList` | Create | card body + `Tooltip` `248:5638` | Chips up to the fit limit, remainder collapsed into `+N`, hover reveals all |
| `SidebarAgentList` | Create | `Agents` `187:2620` | Sidebar agent names, active state, click → playground |
| `EmptyState` | Create | — | Icon + title + body + optional action; used for "no agents found" (names the scanned paths) |
| `ThemeSwitcher` | Done | *(no Figma component yet)* | Light / Dark / System in the Workspace menu — see [Theming decision](#theming-decision) |
| `PageHeader` | **Adjust** | Discovery header | Add an optional count next to the title (`Agents (18)`) |

### 3. Composed (`resources/js/composed/`)

| Component | Status | Figma | Responsibility |
|-----------|--------|-------|----------------|
| `AppShell` | **Adjust** | `Left Sidebar` `324:14596` | Replace the placeholder Agents list with `SidebarAgentList`, show the real agent count in the footer, persist collapse state |
| `AgentGrid` | Create | Discovery screen | Responsive grid + loading skeletons + empty state |

### 4. Pages (`resources/js/pages/`)

| Page | Status | Responsibility |
|------|--------|----------------|
| `Discovery` | **Adjust** | Replace the placeholder body: call `useAgents()`, render `PageHeader` + `AgentGrid`, handle loading / error / empty |

### Data layer

| File | Status | Responsibility |
|------|--------|----------------|
| `lib/api.ts` | **Adjust** | Add typed `getAgents()` on top of the existing `api()` helper |
| `types/agent.ts` | Create | `Agent`, `AgentTool`, `AgentCapabilities`, `ToolType` — mirror the API payload exactly |
| `hooks/useAgents.ts` | Create | Fetch + loading/error state (plain `useState`/`useEffect`; no data-fetching library until one is justified) |

### Styling

| File | Status | Change |
|------|--------|--------|
| `styles/app.css` | **Adjust** | Replace the placeholder palette with tokens ported from Figma for both `:root` (light) and `.dark` — surfaces, borders, text tiers, accent, success/error, radii |

Components reference `--color-*` tokens only; never literals.

---

## Configuration

Config entries this epic reads (from [PRD](../../PRD.md#configuration) / [GOAL](../../GOAL.md#configuration)) — all already present in `config/synapse.php`:

| Key | Default | Use here |
|-----|---------|----------|
| `synapse.discovery.paths` | `[app_path('Ai/Agents'), app_path('Agents')]` | Directories scanned for agent classes — the first is where the SDK's `make:agent` generates them |
| `synapse.discovery.ignore` | `[]` | FQCNs to hide from the dashboard |
| `synapse.ui.path` | `synapse` | Route prefix; the SPA reads it from `window.Synapse.path` |
| `synapse.ui.middleware` | `['web']` | Applied to the API route |
| `synapse.enabled` | env-derived | Routes don't register when false (production opt-in) |

Read from the host app's config (never written by Synapse):

| Key | Use here |
|-----|----------|
| `ai.default` | Fallback provider when an agent declares none |
| `ai.providers.*` | Resolving a provider instance for cheapest/smartest tier names |

**No new config is introduced by this epic.** If discovery needs a knob later (e.g. scan depth), add it to the PRD first.

---

## Technical approach

Derived from [PRD Feature 1](../../PRD.md#feature-1-agent-discovery) and verified against `laravel/ai` v0.9.1 in `references/laravel/ai`.

### 1. Scanning (`src/Discovery/AgentDiscovery.php`)

- Symfony Finder over `synapse.discovery.paths` → `*.php`, recursive.
- Map file → FQCN via Composer's PSR-4 map (`ClassLoader::getPrefixesPsr4()`) rather than string-munging paths; fall back to token parsing (`T_NAMESPACE` + `T_CLASS`) when no prefix matches. This is what makes non-standard app namespaces work.
- Keep a class when `ReflectionClass` says: not abstract, not an interface/trait/enum, and `implementsInterface(Laravel\Ai\Contracts\Agent::class)`.
- Drop anything listed in `synapse.discovery.ignore`.
- **Cache per request** — bind as a singleton in `register()`. No persistent cache: classes change constantly in dev, and a stale dashboard is worse than a rescan.

### 2. Instantiation

```php
try {
    $agent = app($class);          // container — NOT Agent::make()
} catch (\Throwable $e) {
    // available=false + message; never break the page (AC 7)
}
```

`make()` lives on the `Promptable` **trait**, not the `Agent` contract — a framework-authored agent implementing only the contract won't have it. The container also injects constructor dependencies, which `make()` wouldn't.

### 3. Provider / model resolution

Mirrors `Promptable::getProvidersAndModels()` — **methods take precedence over attributes**:

```php
$provider = method_exists($agent, 'provider')
    ? $agent->provider()
    : (new ReflectionClass($agent))->getAttributes(ProviderAttribute::class)[0]?->newInstance()->value;

$model = method_exists($agent, 'model')
    ? $agent->model()
    : (new ReflectionClass($agent))->getAttributes(ModelAttribute::class)[0]?->newInstance()->value;

$provider ??= config('ai.default');
```

When no model is declared, mirror `Promptable::getDefaultModelFor()` and report the **tier** rather than guessing a model name:

- `#[UseSmartestModel]` → tier `smartest`
- `#[UseCheapestModel]` → tier `cheapest`
- neither → tier `default`

The concrete model is only resolved by the SDK at invocation time (`$provider->smartestTextModel()` etc.), so the card shows the tier pill. Resolving the actual name is optional and must not fail the payload if the provider isn't configured.

### 4. Tool classification (`src/Discovery/ToolClassifier.php`)

`tools()` returns `array<Tool|ProviderTool>` and may also contain `Agent` instances or MCP references. Mirror the gateway's `resolveTool()` match **exactly**, in this order:

```php
match (true) {
    $tool instanceof Agent          => ['type' => 'agent',         'name' => (new AgentTool($tool))->name()],
    $tool instanceof Tool           => ['type' => 'tool',          'name' => ToolNameResolver::resolve($tool)],
    McpTool::supports($tool)        => ['type' => 'mcp',           'name' => (new McpTool($tool))->name()],
    McpServerTool::supports($tool)  => ['type' => 'mcp',           'name' => (new McpServerTool($tool))->name()],
    $tool instanceof ProviderTool   => ['type' => 'provider_tool', 'name' => class_basename($tool)],
    default                         => ['type' => 'unknown',       'name' => class_basename($tool)],
};
```

**Critical:** `ProviderTool` implements only `HasProviderOptions` — it has **no** `name()`, `description()`, or `schema()`. Calling those fatals, which is exactly the bug the PRD calls out. Use `class_basename()` for its label.

`ToolNameResolver::resolve()` (SDK) returns `$tool->name()` when callable, else `class_basename($tool)` — use it rather than reimplementing. `McpTool::supports()` / `McpServerTool::supports()` are safe to call when `laravel/mcp` isn't installed (`instanceof` against a missing class is simply false).

Full descriptions and schemas are **Epic 2** — this epic only needs `{name, type}` per tool.

### 5. Capabilities

Interface checks only; included in the payload for internal use, **not rendered as badges** (PRD decision):
`Conversational`, `RemembersConversations`, `HasTools`, `HasStructuredOutput`, `HasMiddleware`, `CanActAsTool`.

`RemembersConversations` extends `Conversational`, so an agent using the trait reports both.

### 6. Slug resolution (`src/Discovery/AgentSlug.php`)

`App\Agents\SupportAgent` ⇄ `app.agents.support-agent` — `\` → `.`, each segment kebab-cased. Resolution is a **lookup against discovered agents**, never a reverse-transform into a class name that then gets instantiated (that would be a class-injection hole). Unknown slug → 404.

### 7. Endpoint

`GET /synapse/api/agents` → `AgentsController` → `AgentDiscovery::all()` → `AgentResource`-shaped array. Inside the existing route group, so the `viewSynapse` gate already applies.

---

## API

```http
GET /synapse/api/agents
```
```jsonc
[
  {
    "slug": "workbench.app.agents.support-agent",
    "name": "SupportAgent",
    "class": "Workbench\\App\\Agents\\SupportAgent",
    "provider": "openai",
    "model": "gpt-5.6-luna",
    "model_tier": "default",          // default | cheapest | smartest
    "tools": [
      { "name": "SearchProductsTool", "type": "tool" },
      { "name": "WebSearch", "type": "provider_tool" }
    ],
    "capabilities": {
      "conversational": true,
      "remembers_conversations": false,
      "has_tools": true,
      "has_structured_output": false,
      "has_middleware": false,
      "can_act_as_tool": false
    },
    "available": true,
    "error": null
  }
]
```

---

## Acceptance criteria

1. A class in `app/Agents/` implementing `Agent` appears after a refresh — no registration, no cache clear.
2. A non-`Agent` class, an abstract agent, or one listed in `discovery.ignore` does not appear.
3. Additional `discovery.paths` are scanned; agents in non-`App\` namespaces (e.g. `Workbench\App\`) resolve correctly.
4. Each card shows class short name, `provider / model`, and a chip per tool.
5. Provider/model follows SDK precedence: `provider()`/`model()` methods **before** `#[Provider]`/`#[Model]`; falls back to `config('ai.default')`; declares tier (`cheapest`/`smartest`) when no explicit model.
6. Tools are classified without fatals — in particular an agent declaring `WebSearch` (a `ProviderTool`) renders as a chip and never triggers `description()`/`schema()`.
7. An agent that cannot be instantiated is reported `available: false` with its error; the page still renders every other agent.
8. Clicking a card navigates to `/synapse/playground/{slug}`; the sidebar Agents list does the same.
9. Zero agents → empty state naming the scanned paths.
10. Sidebar footer shows the real agent count; collapse state persists across reloads.
11. Renders correctly in **both** themes; the sidebar theme switcher offers Light / Dark / System, applies immediately, persists across reloads, and `System` follows the OS live.
12. `GET /api/agents` returns the documented shape; 403 without the gate outside `local`.

---

## Code map

| Area | Path |
|------|------|
| Discovery service | `src/Discovery/AgentDiscovery.php` |
| Metadata extraction | `src/Discovery/AgentMetadata.php` |
| Tool classification | `src/Discovery/ToolClassifier.php` |
| Slug helper | `src/Discovery/AgentSlug.php` |
| Controller | `src/Http/Controllers/AgentsController.php` |
| Route | `routes/web.php` — replace the `/api/agents` stub |
| Binding | `SynapseServiceProvider::register()` — singleton |
| Frontend | see [Frontend components to use](#frontend-components-to-use) |

---

## Theming decision

The scaffold already ships light + dark token sets and system detection. Figma now has **both** component themes, so this epic ports the real tokens.

The scaffold already ships light + dark token sets. Figma now has **both** component themes, so this epic ports the real tokens.

**The theme switcher ships in this epic**, in the sidebar **Workspace menu beside Discovery and History**: Light / Dark / System, persisted in `localStorage`, with `System` following the OS live. The blade layout applies the stored-or-OS choice inline before first paint so there's no flash.

It has **no dedicated Figma component yet**, so it's built from existing Figma styles — the `Navigation` (`355:9764`) row for the trigger and `Dropdown item` (`324:25180`) for the menu — which keeps it consistent today and makes it a drop-in swap when the designer publishes one. **Ask the designer for a proper switcher component** for the sidebar menu.

Already applied (scaffold): `elements/DropdownMenu`, `elements/SidebarItem`, `components/ThemeSwitcher`, wired into `composed/AppShell`; PRD and GOAL describe the switcher.

---

## Tests

**Feature** — `tests/Feature/Discovery/`
- finds agents in configured paths · ignores non-agents and abstracts · honors `discovery.ignore` · scans multiple paths · resolves non-`App\` namespaces
- provider/model: method over attribute · attribute when no method · `ai.default` fallback · `cheapest`/`smartest` tier reporting
- tools: user tool named via `ToolNameResolver` · `ProviderTool` classified without calling `description()`/`schema()` · sub-agent → `agent` · unknown entry → `unknown`
- capabilities reflect implemented interfaces
- unconstructable agent → `available: false` with message, others unaffected
- slug round-trip; unknown slug → 404
- `GET /api/agents` shape; 200 authorized / 403 denied

**Browser** — `tests/Browser/`
- cards render for the workbench agents · `+N` tooltip reveals remaining tools · card click routes to the playground · empty state renders when no agents · both themes render with no JS errors
- theme switcher: selecting Dark adds the `dark` class, the choice survives a reload, and Light removes it

**Workbench fixtures to add:** a non-agent class, an abstract agent, an agent with 6+ tools (`+N`), and an agent with an unresolvable constructor dependency (AC 7). The four existing sample agents already cover conversational/stateless/provider-tool/structured.

---

## Risks

| Risk | Mitigation |
|------|-----------|
| FQCN derivation breaks on non-standard PSR-4 setups | Use Composer's PSR-4 map first; token-parse as fallback; workbench uses `Workbench\App\` so this is covered by tests |
| Instantiating agents executes user constructors | Container-resolve inside try/catch; report unavailable (AC 7) |
| `ProviderTool` has no `name()`/`description()` | Classification order + `class_basename()`; explicit test (AC 6) |
| Porting both themes slows the epic | Tokens only — components consume `--color-*`, so the second theme is a variable set, not a component pass |
| Scanning large `app/` trees each request | Restrict to configured paths; singleton per request; revisit only if measured |

---

## Definition of done

- All 12 ACs verified
- `composer check` green; feature + browser tests added
- Both themes verified; `dist/` rebuilt and committed
- PRD/GOAL updated if a decision changed (theming switcher deferral — done in this epic)
