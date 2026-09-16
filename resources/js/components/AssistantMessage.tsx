import { Markdown } from '@/elements/Markdown';
import { MessageMeta } from './MessageMeta';
import { ReasoningPane } from './ReasoningPane';
import { StructuredOutputCard } from './StructuredOutputCard';
import type { AssistantEntry } from '@/types/chat';

/**
 * An agent turn: markdown body, no bubble, full width — the Figma design gives
 * the answer the whole column and reserves the bubble for the question.
 *
 * One entry is one generation step. A run that calls a tool mid-answer produces
 * several, and the thread keeps them apart so the tool card sits where it
 * actually happened.
 */
export function AssistantMessage({
    entry,
    agentModel,
}: {
    entry: AssistantEntry;
    /** The agent's configured model, so an override can be called out. */
    agentModel?: string | null;
}) {
    return (
        <div data-testid="message-assistant" className="text-sm">
            {entry.pending && (
                <p
                    data-testid="message-assistant-loading"
                    role="status"
                    aria-live="polite"
                    className="text-sm text-muted-foreground"
                >
                    Loading...
                </p>
            )}

            {(entry.reasoning !== '' || entry.reasoningStreaming) && (
                <ReasoningPane text={entry.reasoning} streaming={entry.reasoningStreaming} />
            )}

            {/*
                A structured-output agent's text *is* its JSON, so rendering
                both would print the same payload twice — once unformatted.
                The card below is the same data, readable.
            */}
            {!entry.pending && !entry.structured && <Markdown>{entry.text}</Markdown>}

            {entry.streaming && (
                <span
                    aria-label="Generating"
                    className="ml-0.5 inline-block h-4 w-[2px] animate-pulse bg-foreground align-text-bottom"
                />
            )}

            {entry.structured && <StructuredOutputCard data={entry.structured} />}

            {!entry.streaming && entry.usage && (
                <MessageMeta
                    usage={entry.usage}
                    durationMs={entry.durationMs}
                    meta={entry.meta}
                    agentModel={agentModel}
                />
            )}
        </div>
    );
}
