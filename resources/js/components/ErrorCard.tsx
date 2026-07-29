import { AlertTriangle } from 'lucide-react';
import { Collapsible } from '@/elements/Collapsible';
import { Copy } from '@/elements/Copy';
import { JsonView } from '@/elements/JsonView';
import { cn } from '@/lib/utils';
import type { ErrorEntry } from '@/types/chat';

/**
 * An error that occurred while running the agent, rendered where it happened.
 *
 * Every failure reaches this card — provider errors, timeouts, exceptions from
 * the developer's own tool code, mid-stream errors. Synapse never shows a blank
 * screen or a raw 500, because a debugging tool that hides the failure is worse
 * than no tool at all.
 *
 * No Figma component exists for this; the PRD lists it under "implement without
 * dedicated designs", so it follows the tool card's failed-result treatment.
 */
export function ErrorCard({ entry }: { entry: ErrorEntry }) {
    return (
        <div
            data-testid="error-card"
            className={cn(
                'rounded-xl border p-4',
                entry.recoverable
                    ? 'border-border bg-muted'
                    : 'border-destructive/40 bg-destructive/5',
            )}
        >
            <div className="flex items-start gap-2">
                <AlertTriangle
                    className={cn(
                        'mt-0.5 h-4 w-4 shrink-0',
                        entry.recoverable ? 'text-subtle-foreground' : 'text-destructive',
                    )}
                />

                <div className="min-w-0 flex-1">
                    {entry.exceptionClass && (
                        <p className="font-mono text-xs break-all text-destructive">
                            {entry.exceptionClass}
                        </p>
                    )}

                    <p className="mt-1 text-sm break-words">{entry.message}</p>

                    {/*
                        The provider's own explanation. An HTTP failure's message
                        is only ever "returned status code 400" — this is the
                        part that says what it actually objected to, so it sits
                        open rather than behind a toggle.
                    */}
                    {entry.responseBody && (
                        <JsonView
                            label={`Provider response${entry.responseStatus ? ` (${entry.responseStatus})` : ''}`}
                            tone="error"
                            value={entry.responseBody}
                            testId="error-response"
                            className="mt-3"
                        />
                    )}

                    {entry.stackTrace && (
                        <Collapsible label="Stack trace" className="mt-3">
                            <pre
                                data-testid="stack-trace"
                                className="max-h-64 overflow-auto rounded-lg bg-muted p-3 font-mono text-[11px] leading-relaxed"
                            >
                                {entry.stackTrace}
                            </pre>
                        </Collapsible>
                    )}
                </div>

                <Copy value={[entry.exceptionClass, entry.message, entry.stackTrace].filter(Boolean).join('\n\n')} />
            </div>
        </div>
    );
}
