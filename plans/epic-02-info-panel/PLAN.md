# Epic 2 — Agent Info Panel

**Goal:** open any agent's Info panel and see its full configuration exactly as the SDK will use it at invocation time — provider/model, generation settings, system prompt, and every registered tool with its parameters.

Delivers PRD [Feature 4](../../PRD.md#feature-4-agent-info-panel) · GOAL [Agent info panel](../../GOAL.md#agent-info-panel). Read-only, no invocation.

**Depends on:** Epic 1 (discovery service, slug resolution, tool classification) · **Blocks:** nothing hard, but de-risks Epic 4 by proving full tool classification (schemas, provider options, MCP) before tool cards need it.

---

## Design

- **Panel:** [Info Panel](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=248-5998&m=dev) `248:5998` — local copy: [`screenshots/info-panel-dark.png`](screenshots/info-panel-dark.png)
- **In context:** [Playground + Info panel](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=307-2834&m=dev)

| Figma component | Node | Variants |
|-----------------|------|----------|
| `Info Panel` | `248:5998` | Default (Config) · Variant2 (Prompt) · Variant3 (Tools) · **Collapsed** (`355:5244`) |
| `Tabs` | `187:1382` | Config · Prompt · Tools |
| `Tool Tag` | `171:1262` | used in the Capabilities section |
| `Copy` | `408:5907` | Default · Hover · Copied — for the prompt |

**What the design shows**
- **Config** — `PROVIDER` (Provider, Model, Class) · `GENERATION` (Temperature, Max_Tokens, Max_Steps, Timeout) · `CAPABILITIES` (tool chips) · `MIDDLEWARE (n)` (class names)
- **Prompt** — the full instructions, scrollable
- **Tools** — per tool: an uppercase name header, then one row per parameter: label + type badge (`Keyword` · `String`)
- **Collapsed** — a small ⓘ button that reopens the panel

---

## Decisions

Confirmed before planning:

1. **The panel lives in the playground shell.** Route `/playground/{slug}` hosts it; the chat area stays a placeholder until Epic 3. Matches the design, and Epic 1's cards already link there (`Info` → `?info=1`).
2. **Tools tab = Figma rows + description.** Keep the compact `name · type-badge` row, add the parameter description as a subtle second line when defined, and mark required parameters. Debugging value without redesigning the panel.
3. **Structured output renders in the Tools tab** as an `Output schema` section reusing the same parameter rows — no fourth tab.
4. **Sub-agent tools link to that sub-agent's Info panel** (`/playground/{slug}?info=1`) — you're inspecting, so stay in inspection mode.

---

## Scope

**In**
- `GET /synapse/api/agents/{agent}` returning full detail
- Generation options via `TextGenerationOptions::forAgent()`; timeout, `#[Strict]`, provider options
- Full tool detail per kind: user tools (description + parameter schema), provider tools (class + provider options), sub-agents (linked), MCP tools
- Structured-output schema for `HasStructuredOutput` agents
- Middleware class list
- Info panel UI: 3 tabs, collapsed state, open/close, deep-link via `?info=1`
- Playground shell page (layout only — header with agent name/provider, empty chat area, panel on the right)

**Out**
- Anything that invokes the agent (Epic 3)
- Chat composer, messages, streaming (Epic 3)
- Editing any value — the panel is strictly read-only
- Resolving the concrete model behind a `cheapest`/`smartest` tier (only known at invocation)

---

## Frontend components to use

Layers per [plans/FRONTEND.md](../FRONTEND.md). **Status:** `Done` = exists, used as-is · `Create` = build it · `Adjust` = exists, needs the stated change.

### 1. Elements (`resources/js/elements/`)

| Element | Status | Figma | Used by | Notes |
|---------|--------|-------|---------|-------|
| `Badge` | Done | `Tool Tag` `171:1262` | type badges, capability chips | Reuse `chip` / `pill` variants |
| `Button` | Done | `CTA Button` `279:2791` | close / reopen panel | — |
| `Card` | Done | `Card` `367:11252` | panel sections | Section surface |
| `Tooltip` | Done | `Tooltip` `248:5638` | truncated class names | — |
| `Skeleton` | Done | — | panel loading | — |
| `Tabs` | **Create** | `Tabs` `187:1382` | `InfoPanel` | Controlled tabs; keyboard arrows, `role="tablist"` |
| `Copy` | **Create** | `Copy` `408:5907` | prompt, class names | Default → hover → copied (2s), `navigator.clipboard` |
| `Markdown` | **Create** | Prompt tab | `PromptTab` | `react-markdown` wrapper with our token styles |

### 2. Components (`resources/js/components/`)

| Component | Status | Figma | Responsibility |
|-----------|--------|-------|----------------|
| `ToolTag` | Done | `Tool Tag` `171:1262` | Capability chips in Config |
| `InfoRow` | **Create** | Config rows | Label + value pill (`Temperature` · `0.4`); `null` values render as a muted `—` |
| `InfoSection` | **Create** | Config sections | Uppercase heading + rows (`PROVIDER`, `GENERATION`, `CAPABILITIES`, `MIDDLEWARE (n)`) |
| `SchemaParameterRow` | **Create** | Tools rows | Parameter name + type badge + `required` marker + optional description line |
| `ToolDetail` | **Create** | Tools tab | One tool: name header, description, parameter rows. Kind-specific bodies: provider tool → options; sub-agent → link; MCP → badge |
| `ConfigTab` | **Create** | `Info Panel` Default | Provider / Generation / Capabilities / Middleware sections |
| `PromptTab` | **Create** | `Info Panel` Variant2 | Markdown instructions, scrollable, copy button; empty state when blank |
| `ToolsTab` | **Create** | `Info Panel` Variant3 | Tool list + `Output schema` section; empty state when no tools |
| `AgentCard` | **Adjust** | `Card` `367:11252` | Point `Info` at `/playground/{slug}?info=1` (already does — verify) |

### 3. Composed (`resources/js/composed/`)

| Component | Status | Figma | Responsibility |
|-----------|--------|-------|----------------|
| `InfoPanel` | **Create** | `Info Panel` `248:5998` | Header (ⓘ Info + close), tabs, active tab body, loading/error states |
| `PlaygroundShell` | **Create** | Playground screens | Page frame: agent header (name + provider), empty chat area placeholder, right-hand `InfoPanel`, collapsed ⓘ toggle |

### 4. Pages (`resources/js/pages/`)

| Page | Status | Responsibility |
|------|--------|----------------|
| `Playground` | **Adjust** | Read `:agent` + `?info=1`, call `useAgent(slug)`, render `PlaygroundShell`; handle unknown slug (404 → empty state) |

### Data layer

| File | Status | Responsibility |
|------|--------|----------------|
| `types/agent.ts` | **Adjust** | Add `AgentDetail`, `ToolDetail`, `SchemaProperty`, `GenerationOptions` |
| `lib/api.ts` | **Adjust** | Add `getAgent(slug)` |
| `hooks/useAgent.ts` | **Create** | Fetch one agent's detail; loading / error / not-found |
| `hooks/usePanelState.ts` | **Create** | Panel open/closed + active tab, synced to `?info=` in the URL so the state is shareable |

### Styling

| File | Status | Change |
|------|--------|--------|
| `styles/app.css` | **Adjust** | Only if the prompt's markdown needs typographic rules; otherwise unchanged |

---

## Configuration

No new config. Reads the same keys as Epic 1:

| Key | Use here |
|-----|----------|
| `synapse.discovery.paths` / `.ignore` | Resolving `{agent}` through the discovery service |
| `synapse.ui.path` / `.middleware` / `.enabled` | Route registration and gating |

Host-app config read but never written: `ai.default` (provider fallback), `ai.providers.*` (provider name passed to `providerOptions()`).

---

## Technical approach

Verified against `laravel/ai` v0.9.1 and `illuminate/json-schema` in `references/` and `vendor/`.

### 1. Endpoint

`GET /synapse/api/agents/{agent}` → `AgentsController@show` → `AgentDiscovery::find($slug)` (404 when missing) → `AgentDetail` builder → JSON. Unavailable agents return their Epic 1 error payload with `detail: null` rather than 404 — the panel then shows the same actionable hint the card does.

### 2. Generation options — never hand-rolled

```php
$options = TextGenerationOptions::forAgent($agent);   // public static
$options->maxSteps; $options->maxTokens; $options->temperature; $options->topP; $options->toolChoice;
```

`toolChoice` is a `ToolChoice` with `->mode` (`auto` / `none` / `required` / `tool`) and `->toolName`. Render mode, plus the forced tool name when `mode === 'tool'`.

**Timeout** is *not* on `TextGenerationOptions` (`Promptable::getTimeout()` is protected), so resolve it the same way the SDK does: `timeout()` method → `#[Timeout]` attribute → default `60`.

**Strict:** `Strict::isAppliedTo($agent)` — a public static on the attribute; don't reflect manually.

**Provider options:** `$agent instanceof HasProviderOptions ? $agent->providerOptions($provider) : []`, passing the resolved provider name from Epic 1's metadata.

### 3. Tool detail — the SDK's own serialization

The key find: the SDK wraps tool schemas in its **public `ObjectSchema`** class before sending them to providers, and that is what produces `required`:

```php
$schema = $tool->schema(new JsonSchemaTypeFactory);         // array<string, Type>
$json = filled($schema) ? (new ObjectSchema($schema))->toSchema() : [];
// → ['type' => 'object', 'properties' => [...], 'required' => ['query'], 'additionalProperties' => false]
```

**Do not call `Type::toArray()` per property** — `Serializer` deliberately strips `required` (it's in its `$ignore` list) because JSON Schema puts `required` on the parent object, and `Serializer::isRequired()` is `protected`. Going through `ObjectSchema` is both correct and the same code path the gateways use, so our panel shows exactly what the provider receives.

Per tool kind (reusing Epic 1's `ToolClassifier` order):

| Kind | Name | Description | Parameters | Extra |
|------|------|-------------|-----------|-------|
| `tool` | `ToolNameResolver::resolve()` | `(string) $tool->description()` | via `ObjectSchema` | — |
| `mcp` | `McpTool`/`McpServerTool` `name()` | `description()` | via `ObjectSchema` | `MCP` badge |
| `agent` | `(new AgentTool($sub))->name()` | `AgentTool::description()` (auto-generated when the sub-agent isn't `CanActAsTool`) | — | slug for linking |
| `provider_tool` | `class_basename()` | — | — | `providerOptions($provider)`; **never** call `description()`/`schema()` |

Sub-agent slugs come from `AgentSlug::make($sub::class)`, matched against discovery so the link only renders when that agent is actually discoverable.

### 4. Structured output

```php
$agent instanceof HasStructuredOutput
    ? (new ObjectSchema($agent->schema(new JsonSchemaTypeFactory)))->toSchema()
    : null;
```
Same shape as tool parameters, so `SchemaParameterRow` renders both.

### 5. Middleware

`$agent instanceof HasMiddleware ? $agent->middleware() : []` — entries may be class strings or instances; normalize to class names for display.

### 6. Robustness

Every extraction runs inside the existing per-agent try/catch discipline: a tool whose `schema()` throws degrades to "schema unavailable" on that tool rather than blanking the panel. Same philosophy as Epic 1's unavailable card.

---

## API

```http
GET /synapse/api/agents/{agent}
```
```jsonc
{
  "slug": "app.ai.agents.support-agent",
  "name": "SupportAgent",
  "class": "App\\Ai\\Agents\\SupportAgent",
  "provider": "openai",
  "model": "gpt-5.6-luna",
  "model_tier": "default",
  "capabilities": { "conversational": true, "...": false },
  "available": true,
  "error": null,
  "error_kind": null,
  "unresolvable": [],

  "instructions": "You are a friendly customer support agent…",
  "generation": {
    "temperature": 0.4,
    "max_tokens": 4096,
    "max_steps": 6,
    "top_p": null,
    "timeout": 60,
    "strict": false,
    "tool_choice": { "mode": "auto", "tool": null }
  },
  "provider_options": {},
  "middleware": ["App\\Ai\\Middleware\\PiiRedactor"],
  "tools": [
    {
      "name": "SearchProductsTool",
      "type": "tool",
      "description": "Search the product catalog for matching items.",
      "parameters": [
        { "name": "query", "type": "string", "description": "Search query text", "required": true },
        { "name": "max_results", "type": "integer", "description": "Maximum results to return", "required": false }
      ],
      "provider_options": null,
      "agent_slug": null,
      "schema_error": null
    }
  ],
  "output_schema": null
}
```

---

## Acceptance criteria

1. Clicking **Info** on an agent card opens the panel for that agent; the URL carries `?info=1` so the state survives a reload and can be shared.
2. **Config** shows provider, model (or tier pill), FQCN, temperature, max tokens, max steps, top-p, tool choice, timeout, strict, and any provider options — values absent on the agent render as `—`, never as `0` or blank.
3. Every Config value matches what the SDK would use: generation options come from `TextGenerationOptions::forAgent()`, timeout follows method → attribute → `60`.
4. **Capabilities** renders the agent's tool chips (per design), and **Middleware (n)** lists middleware class names; both sections hide when empty.
5. **Prompt** renders `instructions()` as markdown, scrolls independently, and can be copied. Empty instructions show an empty state.
6. **Tools** lists every tool with its name, description, and parameters as `name · type` rows, required parameters marked and descriptions shown when defined.
7. A **provider tool** (e.g. `WebSearch`) renders with its provider-tool badge and options, and **never** triggers `description()`/`schema()`.
8. A **sub-agent tool** is labelled and links to that sub-agent's Info panel; a **MCP tool** is badged `MCP`.
9. A **structured-output** agent shows an `Output schema` section in the Tools tab; agents without one don't.
10. An agent with **no tools** shows an empty state in the Tools tab rather than a blank panel.
11. The panel **closes** to the collapsed ⓘ button and reopens from it.
12. An **unavailable** agent (Epic 1's `BrokenAgent`) opens the panel and shows the same actionable fix hint instead of a broken layout.
13. Unknown slug → the page shows a not-found state, not a crash.
14. Renders correctly in **both** themes; `GET /api/agents/{agent}` is gated (403 outside `local` without `viewSynapse`).

---

## Code map

| Area | Path |
|------|------|
| Detail builder | `src/Discovery/AgentDetail.php` |
| Tool detail + schema | `src/Discovery/ToolDetail.php` (extends Epic 1's classifier with descriptions/schemas) |
| Generation options | `src/Discovery/GenerationOptions.php` |
| Controller | `src/Http/Controllers/AgentsController.php` — add `show()` |
| Route | `routes/web.php` — replace the `/api/agents/{agent}` stub |
| Frontend | see [Frontend components to use](#frontend-components-to-use) |

---

## Tests

**Feature** — `tests/Feature/InfoPanel/`
- generation options mirror `TextGenerationOptions::forAgent()` (temperature/max tokens/max steps/top-p/tool choice)
- timeout resolves method → attribute → default `60`; `#[Strict]` detected
- instructions returned verbatim (including `Stringable` instructions)
- user tool: description + parameters with correct types, **`required` present** (the `ObjectSchema` path)
- provider tool: options returned, no `description()`/`schema()` call (fixture asserts no fatal)
- sub-agent tool: linked slug resolves to a discovered agent
- structured-output agent: `output_schema` populated; others `null`
- middleware class names listed
- a tool whose `schema()` throws → `schema_error` set, rest of the payload intact
- unavailable agent → detail null + Epic 1 error fields preserved
- unknown slug → 404; endpoint gated (200 / 403)

**Browser** — `tests/Browser/InfoPanelTest.php`
- Info from a card opens the panel with Config active; deep link `?info=1` opens it directly
- switching tabs shows prompt text, then the tools list
- provider-tool badge visible on `ResearchAgent`; `+N`-style parameter rows visible on `SupportAgent`
- `ExtractorAgent` shows the output schema section
- close → collapsed ⓘ → reopen
- both themes render with no JS errors

**Workbench fixtures to add:** an agent with `#[Strict]`, `#[TopP]`, `#[ToolChoice]`, middleware, and provider options (one class can carry all of them), plus a tool with a deliberately throwing `schema()`.

---

## Risks

| Risk | Mitigation |
|------|-----------|
| Reading `required` from `Type::toArray()` silently loses it | Use `ObjectSchema::toSchema()` — the SDK's own path; asserted by a test |
| Calling `description()`/`schema()` on a `ProviderTool` fatals | Kind is decided by Epic 1's classifier before any accessor call; AC 7 + a test lock it in |
| `middleware()` may return instances or strings | Normalize to class names |
| Long instructions or huge schemas overflow the panel | Independent scroll per tab; the design already scrolls the prompt |
| Panel state in the URL conflicts with Epic 3's chat routing | `?info=1` is a query param, not a path segment — Epic 3 owns the path and is unaffected |

---

## Definition of done

- All 14 ACs verified
- `composer check` green; feature + browser tests added
- Both themes verified; `dist/` rebuilt and committed
- PRD/GOAL updated if a decision changed — expected: PRD Feature 4's "parameter table" wording aligned with the shipped row layout, and the structured-output placement recorded
