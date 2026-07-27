# Synapse — Designer Feedback

Feedback from the PRD ↔ design sync review (see [PRD.md](PRD.md), "Design Sync" section).
File: [Synapse on Figma](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse)

The designs are approved as the visual source of truth. The items below are the only gaps — missing states/screens the PRD requires, plus two small fixes.

**Status (verified in Figma):** ✅ = accepted by designers · **DELIVERED** = shipped in the file and verified.

| # | Item | Status |
|---|------|--------|
| 1 | Attachments UI | **DELIVERED** — `Chat Input` (File attached / Drop File), `Attach file` menu, `File chip` (Image/Audio/Document), `Plus` button |
| 2 | Reasoning "Thinking…" pane | **DELIVERED (in progress)** — [`✦ Thinking…` state](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=531-5178&m=dev) exists but is not yet promoted to a component. Build against it; adjust if the designer refines it |
| 3 | Provider-tool card variant | **DELIVERED** — `Inline Tool Call cards` Variant3/Variant4 (`anthropic / web_search`) |
| 4 | Pending tool state | **DELIVERED** — `Status Badges` → `Pending` |
| 5 | Light theme | **DELIVERED** — full parallel `Components_Light` section |
| 6 | History subtitle copy | **DELIVERED** — "View your previous conversations and continue where you left off" |
| 7 | Remove Settings nav item | **DELIVERED** — sidebar Workspace nav is Discovery + History only |

**All items are addressed.** The reasoning pane exists as a work-in-progress `✦ Thinking…` state rather than a published component — treat it as done for planning, build to it, and update the component if the designer refines it later. The streaming indicator is designed; the expanded reasoning content (collapsible, with reasoning-token count per the PRD) extends it using the existing collapsible/card language.

---

## 1. Attachments UI (Chat Playground) ✅

**Where:** [Playground — empty state](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=307-2834&m=dev), [Playground — conversation](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=324-12263&m=dev)

The playground supports file attachments (images, documents, audio), but the composer has no attachment affordance. Needed:

- Attach button (file picker) in the composer + drag-and-drop target state over the chat area
- Attachment chips inside the composer before sending (name + type icon + remove)
- Attachment rendering on the sent user message bubble: image thumbnails, file chips for documents/audio — also shown in conversation replay

## 2. Reasoning ("Thinking…") pane (Chat Playground) ✅

**Where:** [Playground — conversation](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=324-11632&m=dev)

Models with extended thinking (Anthropic, OpenAI o-series, DeepSeek) stream reasoning blocks before the answer. Needed: a collapsible "Thinking…" pane rendered inline above the assistant response, visually distinct from the final answer (muted/secondary styling), collapsed by default, with reasoning token count shown alongside the prompt/completion counts.

## 3. Provider-tool card variant (Chat Playground) ✅

**Where:** [Components — tool cards](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=187-2364&m=dev)

Providers run built-in tools server-side (web search, web fetch, file search, code interpreter). These need a tool card variant visually distinct from user-defined tools — e.g. a ⚡ icon and label like "Provider tool: anthropic.web_search" — with statuses `in_progress` / `completed` / `failed`. Payload is a JSON blob (same JSON viewer as existing cards).

## 4. Pending / in-flight tool card state (Chat Playground) ✅

**Where:** [Components — tool cards](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=187-2364&m=dev)

Tool cards currently have `success` and `error` badge variants only. Tool cards appear in the stream **while the tool is still running** — needed: a `pending` variant (spinner/progress indicator in place of the status badge, no result section yet) that transitions to success/error.

## 5. Light theme ✅

The full design system exists in dark theme only, while the product ships a light / dark / system scheme toggler (Horizon-style). Needed: light variants of the screens — or, at minimum, a light color-token set (backgrounds, surfaces, borders, text tiers, the red accent on light surfaces, success/error colors) we can apply to the existing components.

## 6. History screen subtitle copy ✅

**Where:** [History](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=355-8263&m=dev)

The subtitle is copy-pasted from the Discovery screen: *"Auto-scanned from app/Agents/ on every request. Click a card to open the chat playground."* — wrong for this screen. Needs History-appropriate copy (e.g. describing past conversations / click a row to reopen).

## 7. Remove the Settings nav item (Sidebar) ✅

**Where:** all screens with the sidebar, e.g. [Discovery](https://www.figma.com/design/3aOnDdOpoAvf9Kd7YP0C8P/Synapse?node-id=324-2362&m=dev)

Synapse has no runtime settings UI — all configuration is file-based (`config/synapse.php`). Please remove `Settings` from the Workspace nav so we don't ship a dead link.
