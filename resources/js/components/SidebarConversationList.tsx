import { Link } from 'react-router-dom';
import { TriangleAlert } from 'lucide-react';
import { Skeleton } from '@/elements/Skeleton';
import type { ConversationSummary } from '@/types/conversation';

/**
 * Recent conversations in the sidebar — Figma `Navigation` rows.
 *
 * Each row carries its agent, the tool-call count, and a warning glyph when
 * something in the conversation failed: the point of a recents list on a
 * debugging tool is getting back to the run that went wrong.
 */
export function SidebarConversationList({
    conversations,
    loading,
}: {
    conversations: ConversationSummary[];
    loading: boolean;
}) {
    if (loading) {
        return (
            <div className="space-y-2 px-2">
                {[0, 1, 2].map((row) => (
                    <Skeleton key={row} className="h-8 w-full" />
                ))}
            </div>
        );
    }

    if (conversations.length === 0) {
        return <p className="px-2 text-sm text-subtle-foreground">No conversations yet.</p>;
    }

    return (
        <ul data-testid="sidebar-conversations" className="space-y-1">
            {conversations.map((conversation) => (
                <li key={conversation.id}>
                    <Link
                        to={`/playground/${conversation.agent_slug}?c=${conversation.id}`}
                        className="block rounded-lg px-2 py-1.5 transition-colors hover:bg-muted"
                    >
                        <span className="flex items-baseline justify-between gap-2">
                            <span className="truncate text-xs tracking-wide text-muted-foreground uppercase">
                                {conversation.agent_name}
                            </span>
                            <span className="shrink-0 text-[10px] text-subtle-foreground">
                                {conversation.tool_calls} calls
                            </span>
                        </span>

                        <span className="flex items-center gap-1.5">
                            <span className="min-w-0 flex-1 truncate text-sm">
                                {conversation.title}
                            </span>
                            {conversation.status === 'error' && (
                                <TriangleAlert
                                    aria-label="This conversation contains an error"
                                    className="h-3.5 w-3.5 shrink-0 text-destructive"
                                />
                            )}
                        </span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}
