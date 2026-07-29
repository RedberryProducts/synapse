import { Markdown } from '@/elements/Markdown';
import { MessageMeta } from './MessageMeta';
import type { AssistantEntry } from '@/types/chat';

/**
 * An agent turn: markdown body, no bubble, full width — the Figma design gives
 * the answer the whole column and reserves the bubble for the question.
 *
 * One entry is one generation step. A run that calls a tool mid-answer produces
 * several, and the thread keeps them apart so the tool card sits where it
 * actually happened.
 */
export function AssistantMessage({ entry }: { entry: AssistantEntry }) {
    return (
        <div data-testid="message-assistant" className="text-sm">
            <Markdown>{entry.text}</Markdown>

            {entry.streaming && (
                <span
                    aria-label="Generating"
                    className="ml-0.5 inline-block h-4 w-[2px] animate-pulse bg-foreground align-text-bottom"
                />
            )}

            {entry.structured && (
                <pre
                    data-testid="structured-output"
                    className="mt-3 overflow-x-auto rounded-lg border border-border bg-muted p-3 text-xs"
                >
                    {JSON.stringify(entry.structured, null, 2)}
                </pre>
            )}

            {!entry.streaming && entry.usage && (
                <MessageMeta usage={entry.usage} durationMs={entry.durationMs} />
            )}
        </div>
    );
}
