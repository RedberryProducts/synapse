import { Sparkles } from 'lucide-react';

/**
 * The hero shown before the first message — the Figma `Playground_Empty` state.
 *
 * Deliberately not `EmptyState` (the dashed box used for "nothing found"):
 * an empty playground is an invitation, not an absence.
 */
export function ChatEmptyState() {
    return (
        <div data-testid="chat-empty" className="flex flex-col items-center gap-6 text-center">
            <Sparkles className="h-12 w-12 text-primary" />

            <h2 className="max-w-md text-3xl leading-tight font-semibold">
                Explore, test, and debug your AI agents
            </h2>
        </div>
    );
}
