import { Link } from 'react-router-dom';
import { MoreVertical, Pencil, SquareArrowOutUpRight, Trash2, TriangleAlert } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/elements/DropdownMenu';
import { Skeleton } from '@/elements/Skeleton';
import type { ConversationSummary } from '@/types/conversation';

/**
 * Recent conversations in the sidebar — Figma `Navigation` rows.
 *
 * Each row carries its agent, the tool-call count, and a warning glyph when
 * something in the conversation failed: the point of a recents list on a
 * debugging tool is getting back to the run that went wrong.
 *
 * The row is a link and the menu is a sibling, not a child — a button nested
 * inside an anchor is invalid HTML and browsers disagree about which one a click
 * belongs to. The menu takes the space the call count occupies, revealed on
 * hover or keyboard focus so the resting row stays quiet.
 */
export function SidebarConversationList({
    conversations,
    loading,
    onOpen,
    onRename,
    onDelete,
}: {
    conversations: ConversationSummary[];
    loading: boolean;
    onOpen: (conversation: ConversationSummary) => void;
    onRename: (conversation: ConversationSummary) => void;
    onDelete: (conversation: ConversationSummary) => void;
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
                <li key={conversation.id} className="group relative">
                    <Link
                        to={`/playground/${conversation.agent_slug}?c=${conversation.id}`}
                        className="block rounded-lg px-2 py-1.5 transition-colors hover:bg-muted"
                    >
                        <span className="flex items-baseline justify-between gap-2">
                            <span className="truncate text-xs tracking-wide text-muted-foreground uppercase">
                                {conversation.agent_name}
                            </span>
                            <span className="shrink-0 text-[10px] text-subtle-foreground transition-opacity group-hover:opacity-0 group-focus-within:opacity-0">
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

                    <span className="absolute top-1 right-1 opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100">
                        <DropdownMenu>
                            <DropdownMenuTrigger
                                // Distinct from the History row menu's "Actions
                                // for X": both are on screen together, and an
                                // accessible name that appears twice is
                                // ambiguous for a screen reader as well as for
                                // a test.
                                aria-label={`Recent conversation actions for ${conversation.title}`}
                                className="inline-flex h-6 w-6 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-border hover:text-foreground"
                            >
                                <MoreVertical className="h-3.5 w-3.5" />
                            </DropdownMenuTrigger>

                            <DropdownMenuContent align="end" side="bottom">
                                <DropdownMenuItem onSelect={() => onOpen(conversation)}>
                                    <SquareArrowOutUpRight className="h-3.5 w-3.5" />
                                    Open
                                </DropdownMenuItem>
                                <DropdownMenuItem onSelect={() => onRename(conversation)}>
                                    <Pencil className="h-3.5 w-3.5" />
                                    Rename
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    onSelect={() => onDelete(conversation)}
                                    className="text-destructive"
                                >
                                    <Trash2 className="h-3.5 w-3.5" />
                                    Delete
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </span>
                </li>
            ))}
        </ul>
    );
}
