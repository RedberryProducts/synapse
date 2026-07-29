import { useState } from 'react';
import { ChevronDown, Timer, Wrench, Zap } from 'lucide-react';
import { JsonView } from '@/elements/JsonView';
import { cn } from '@/lib/utils';
import { ToolStatusBadge } from './ToolStatusBadge';
import type { ToolEntry } from '@/types/chat';

/**
 * One tool call, inline in the thread — Figma `Inline Tool Call cards`.
 *
 * Collapsed by default so a chain of calls stays readable; the whole header is
 * the toggle. A finished call shows its duration, an unfinished or failed one
 * shows a status badge.
 *
 * Provider-native tools (web search, file search, code interpreter) get the
 * lightning variant: they ran inside the provider rather than in the developer's
 * own code, and that distinction matters when you're working out who to blame.
 */
export function ToolCard({ entry }: { entry: ToolEntry }) {
    const [open, setOpen] = useState(false);

    const isProviderTool = entry.type === 'provider_tool';
    const Icon = isProviderTool ? Zap : Wrench;
    const finished = entry.status !== 'pending';

    return (
        <div
            data-testid="tool-card"
            data-tool-status={entry.status}
            className="rounded-xl border border-border bg-card"
        >
            <button
                type="button"
                onClick={() => setOpen(!open)}
                aria-expanded={open}
                aria-label={`${entry.name} tool call`}
                className="flex w-full items-center gap-3 px-4 py-3 text-left"
            >
                <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />

                <span className="flex min-w-0 items-center gap-1.5 text-sm">
                    {isProviderTool && entry.provider && (
                        <>
                            <span className="text-muted-foreground">{entry.provider}</span>
                            <span className="text-subtle-foreground">/</span>
                        </>
                    )}
                    <span className="truncate font-medium">{entry.name}</span>
                </span>

                <span className="ml-auto flex shrink-0 items-center gap-3">
                    {finished && entry.status === 'success' && entry.durationMs !== null && (
                        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                            <Timer className="h-3.5 w-3.5" />
                            {entry.durationMs}ms
                        </span>
                    )}

                    {(entry.status !== 'success' || entry.durationMs === null) && (
                        <ToolStatusBadge
                            status={entry.status}
                            providerStatus={entry.providerStatus}
                        />
                    )}

                    <ChevronDown
                        className={cn(
                            'h-4 w-4 text-subtle-foreground transition-transform',
                            open && 'rotate-180',
                        )}
                    />
                </span>
            </button>

            {open && (
                <div className="flex flex-col gap-4 px-4 pt-1 pb-4">
                    <JsonView label="Arguments" value={entry.arguments} testId="tool-arguments" />

                    {entry.status === 'error' ? (
                        <JsonView
                            label="Result"
                            tone="error"
                            value={entry.error}
                            testId="tool-result"
                        />
                    ) : entry.status === 'pending' ? (
                        <p className="text-xs text-subtle-foreground">
                            Still running{entry.providerStatus && ` — provider reports “${entry.providerStatus}”`}.
                        </p>
                    ) : (
                        <JsonView label="Result" value={entry.result} testId="tool-result" />
                    )}
                </div>
            )}
        </div>
    );
}
