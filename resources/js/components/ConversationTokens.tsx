import { CopyCheck, Database, SquareGanttChart } from 'lucide-react';

/**
 * Conversation-wide token counts, sitting under the agent name exactly as the
 * Figma conversation screen places them.
 */
export function ConversationTokens({
    prompt,
    completion,
}: {
    prompt: number;
    completion: number;
}) {
    return (
        <div
            data-testid="conversation-tokens"
            className="flex flex-wrap items-center gap-x-8 gap-y-1 text-sm text-muted-foreground"
        >
            <Stat icon={Database} label="Total" value={prompt + completion} />
            <Stat icon={SquareGanttChart} label="Prompt" value={prompt} />
            <Stat icon={CopyCheck} label="Completion" value={completion} />
        </div>
    );
}

function Stat({
    icon: Icon,
    label,
    value,
}: {
    icon: typeof Database;
    label: string;
    value: number;
}) {
    return (
        <span className="inline-flex items-center gap-1.5" title={`${label} ${value} tokens`}>
            <Icon className="h-4 w-4" />
            {label} {abbreviate(value)}
        </span>
    );
}

/** 5,700 reads as `5.7k` — the design keeps this row glanceable, not exact. */
function abbreviate(value: number): string {
    if (value < 1000) {
        return String(value);
    }

    return `${(value / 1000).toFixed(1).replace(/\.0$/, '')}k`;
}
