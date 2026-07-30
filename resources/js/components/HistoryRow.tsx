import { CircleCheck, MoreVertical, Pencil, SquareArrowOutUpRight, Trash2, TriangleAlert } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/elements/DropdownMenu';
import { TableCell, TableRow } from '@/elements/Table';
import { Tooltip } from '@/elements/Tooltip';
import type { ConversationSummary } from '@/types/conversation';

/**
 * One conversation in the History table.
 *
 * The status glyph carries a tooltip because a bare icon is only obvious once
 * you already know the convention — and the distinction it draws (a failed
 * message, not a failed tool) is worth spelling out.
 */
export function HistoryRow({
    conversation,
    onOpen,
    onRename,
    onDelete,
}: {
    conversation: ConversationSummary;
    onOpen: () => void;
    onRename: () => void;
    onDelete: () => void;
}) {
    const failed = conversation.status === 'error';

    return (
        <TableRow
            interactive
            data-testid="history-row"
            data-agent={conversation.agent_slug}
            onClick={onOpen}
        >
            <TableCell className="whitespace-nowrap">
                <span className="flex items-center gap-1.5">
                    {conversation.agent_name}
                    {!conversation.agent_available && (
                        <Tooltip content="This agent no longer exists. The conversation is read-only.">
                            <span className="text-xs text-subtle-foreground">(removed)</span>
                        </Tooltip>
                    )}
                </span>
            </TableCell>

            <TableCell className="max-w-md">
                <span className="block truncate">{conversation.title}</span>
            </TableCell>

            <TableCell>
                <Tooltip
                    content={
                        failed
                            ? 'A message in this conversation failed'
                            : 'Every message succeeded'
                    }
                >
                    <span data-testid="history-status" data-status={conversation.status}>
                        {failed ? (
                            <TriangleAlert className="h-4 w-4 text-destructive" />
                        ) : (
                            <CircleCheck className="h-4 w-4 text-success" />
                        )}
                    </span>
                </Tooltip>
            </TableCell>

            <TableCell className="whitespace-nowrap text-muted-foreground">
                {abbreviate(conversation.total_tokens)}
            </TableCell>

            <TableCell className="whitespace-nowrap text-muted-foreground">
                {formatDate(conversation.updated_at)}
            </TableCell>

            <TableCell className="w-10">
                {/* The row itself opens the conversation, so the menu must not. */}
                <span onClick={(event) => event.stopPropagation()}>
                    <DropdownMenu>
                        <DropdownMenuTrigger
                            aria-label={`Actions for ${conversation.title}`}
                            className="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        >
                            <MoreVertical className="h-4 w-4" />
                        </DropdownMenuTrigger>

                        <DropdownMenuContent align="end" side="bottom">
                            <DropdownMenuItem onSelect={onOpen}>
                                <SquareArrowOutUpRight className="h-3.5 w-3.5" />
                                Open
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={onRename}>
                                <Pencil className="h-3.5 w-3.5" />
                                Rename
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={onDelete} className="text-destructive">
                                <Trash2 className="h-3.5 w-3.5" />
                                Delete
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </span>
            </TableCell>
        </TableRow>
    );
}

/** 3,500 reads as `3.5k` — the column is for scanning, not accounting. */
function abbreviate(value: number): string {
    if (value < 1000) {
        return String(value);
    }

    return `${(value / 1000).toFixed(1).replace(/\.0$/, '')}k`;
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return `${date.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    })}  ${date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}`;
}
