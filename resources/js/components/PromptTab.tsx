import { Copy } from '@/elements/Copy';
import { Markdown } from '@/elements/Markdown';
import type { AgentDetail } from '@/types/agent';

/** Info panel → Prompt: the agent's instructions, rendered as markdown. */
export function PromptTab({ agent }: { agent: AgentDetail }) {
    const instructions = agent.instructions?.trim();

    if (!instructions) {
        return (
            <p className="rounded-xl border border-dashed border-border p-6 text-center text-sm text-subtle-foreground">
                This agent has no instructions.
            </p>
        );
    }

    return (
        <div className="rounded-xl border border-border bg-card">
            <div className="flex items-center justify-between border-b border-border px-4 py-2">
                <span className="text-xs font-medium tracking-wide text-subtle-foreground uppercase">
                    Instructions
                </span>
                <Copy value={instructions} />
            </div>
            <div className="p-4 text-sm text-muted-foreground">
                <Markdown>{instructions}</Markdown>
            </div>
        </div>
    );
}
