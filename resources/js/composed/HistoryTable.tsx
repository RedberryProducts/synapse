import { Inbox, SearchX } from 'lucide-react';
import { EmptyState } from '@/components/EmptyState';
import { HistoryRow } from '@/components/HistoryRow';
import { Pagination } from '@/elements/Pagination';
import { Skeleton } from '@/elements/Skeleton';
import { Table, TableBody, TableHead, TableHeader, TableRow } from '@/elements/Table';
import type { ConversationPage, ConversationSummary } from '@/types/conversation';

const columns = ['Agent', 'Message', 'Status', 'Tokens', 'Date & Time', ''];

/**
 * The History table: header, rows, pagination, and the two empty cases.
 *
 * "Nothing yet" and "nothing matches" are different problems and get different
 * copy — one means start working, the other means loosen your filters. Showing
 * the same message for both is how an empty table becomes confusing.
 */
export function HistoryTable({
    conversations,
    meta,
    loading,
    filtered,
    onPage,
    onOpen,
    onRename,
    onDelete,
    onClearFilters,
}: {
    conversations: ConversationSummary[];
    meta: ConversationPage['meta'];
    loading: boolean;
    filtered: boolean;
    onPage: (page: number) => void;
    onOpen: (conversation: ConversationSummary) => void;
    onRename: (conversation: ConversationSummary) => void;
    onDelete: (conversation: ConversationSummary) => void;
    onClearFilters: () => void;
}) {
    if (loading && conversations.length === 0) {
        return (
            <div className="space-y-2">
                {[0, 1, 2, 3, 4].map((row) => (
                    <Skeleton key={row} className="h-12 w-full" />
                ))}
            </div>
        );
    }

    if (conversations.length === 0) {
        return filtered ? (
            <div data-testid="history-no-matches">
                <EmptyState icon={SearchX} title="No conversations match these filters">
                    <p>
                        Nothing here fits what you're looking for.{' '}
                        <button
                            type="button"
                            onClick={onClearFilters}
                            className="text-accent underline underline-offset-2"
                        >
                            Clear the filters
                        </button>{' '}
                        to see everything again.
                    </p>
                </EmptyState>
            </div>
        ) : (
            <div data-testid="history-empty">
                <EmptyState icon={Inbox} title="No conversations yet">
                    <p>
                        Open an agent from Discovery and send it a message — every
                        conversation you have shows up here.
                    </p>
                </EmptyState>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <Table data-testid="history-table">
                <TableHead>
                    <TableRow>
                        {columns.map((column, index) => (
                            <TableHeader key={column || `actions-${index}`}>{column}</TableHeader>
                        ))}
                    </TableRow>
                </TableHead>

                <TableBody>
                    {conversations.map((conversation) => (
                        <HistoryRow
                            key={conversation.id}
                            conversation={conversation}
                            onOpen={() => onOpen(conversation)}
                            onRename={() => onRename(conversation)}
                            onDelete={() => onDelete(conversation)}
                        />
                    ))}
                </TableBody>
            </Table>

            <Pagination page={meta.current_page} lastPage={meta.last_page} onChange={onPage} />
        </div>
    );
}
