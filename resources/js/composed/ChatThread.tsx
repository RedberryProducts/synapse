import { useEffect, useRef } from 'react';
import { Info } from 'lucide-react';
import { AssistantMessage } from '@/components/AssistantMessage';
import { ChatEmptyState } from '@/components/ChatEmptyState';
import { ErrorCard } from '@/components/ErrorCard';
import { ToolCard } from '@/components/ToolCard';
import { UserMessage } from '@/components/UserMessage';
import type { ChatEntry } from '@/types/chat';

/**
 * The message thread.
 *
 * Follows the stream while it runs, but stops following the moment the reader
 * scrolls up — yanking someone back to the bottom mid-read is the fastest way
 * to make a live view useless.
 */
export function ChatThread({ entries }: { entries: ChatEntry[] }) {
    const container = useRef<HTMLDivElement>(null);
    const pinned = useRef(true);

    useEffect(() => {
        const element = container.current;

        if (element && pinned.current) {
            element.scrollTop = element.scrollHeight;
        }
    }, [entries]);

    if (entries.length === 0) {
        return (
            <div className="flex flex-1 items-center justify-center px-8">
                <ChatEmptyState />
            </div>
        );
    }

    return (
        <div
            ref={container}
            data-testid="chat-thread"
            onScroll={(event) => {
                const { scrollTop, scrollHeight, clientHeight } = event.currentTarget;

                pinned.current = scrollHeight - scrollTop - clientHeight < 40;
            }}
            className="flex-1 overflow-y-auto px-8 py-6"
        >
            <div className="mx-auto flex max-w-3xl flex-col gap-6">
                {entries.map((entry) => (
                    <Entry key={entry.id} entry={entry} />
                ))}
            </div>
        </div>
    );
}

function Entry({ entry }: { entry: ChatEntry }) {
    switch (entry.kind) {
        case 'user':
            return <UserMessage content={entry.content} />;

        case 'assistant':
            return <AssistantMessage entry={entry} />;

        case 'tool':
            return <ToolCard entry={entry} />;

        case 'error':
            return <ErrorCard entry={entry} />;

        case 'notice':
            // A failover is information, not a failure: the run carried on
            // against the next provider and the developer should know.
            return (
                <p
                    data-testid="chat-notice"
                    className="flex items-center gap-2 text-xs text-subtle-foreground"
                >
                    <Info className="h-3.5 w-3.5" />
                    {entry.message}
                </p>
            );
    }
}
