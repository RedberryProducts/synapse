import { useCallback, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { DeleteDialog } from '@/components/DeleteDialog';
import { HistoryFilters } from '@/components/HistoryFilters';
import { PageHeader } from '@/components/PageHeader';
import { RenameDialog } from '@/components/RenameDialog';
import { HistoryTable } from '@/composed/HistoryTable';
import { useConversations } from '@/hooks/useConversations';
import { deleteConversation, renameConversation } from '@/lib/api';
import { forget } from '@/lib/lastConversation';
import {
    activeFilterCount,
    emptyFilters,
    type ConversationFilters,
    type ConversationSummary,
} from '@/types/conversation';

export default function History() {
    const [params, setParams] = useSearchParams();
    const navigate = useNavigate();

    // Filters live in the URL so a reload — or a link sent to a colleague —
    // restores the same view.
    const filters = useMemo(() => fromParams(params), [params]);

    const { conversations, options, meta, loading, error, refresh } = useConversations(filters);

    const [renaming, setRenaming] = useState<ConversationSummary | null>(null);
    const [deleting, setDeleting] = useState<ConversationSummary | null>(null);

    const setFilters = useCallback(
        (next: ConversationFilters) => setParams(toParams(next), { replace: true }),
        [setParams],
    );

    const open = (conversation: ConversationSummary) =>
        navigate(`/playground/${conversation.agent_slug}?c=${conversation.id}`);

    const rename = async (title: string) => {
        if (!renaming) {
            return;
        }

        await renameConversation(renaming.id, title).catch(() => null);

        setRenaming(null);
        refresh();
    };

    const remove = async () => {
        if (!deleting) {
            return;
        }

        await deleteConversation(deleting.id).catch(() => null);

        // A remembered pointer to a deleted conversation would strand you on an
        // empty playground the next time you opened that agent.
        forget(deleting.id);

        setDeleting(null);
        refresh();
    };

    return (
        <div className="space-y-8 p-8">
            <PageHeader
                title="History"
                count={meta.total}
                subtitle="View your previous conversations and continue where you left off"
            />

            <HistoryFilters filters={filters} options={options} onChange={setFilters} />

            {error !== null ? (
                <p className="text-sm text-destructive">{error}</p>
            ) : (
                <HistoryTable
                    conversations={conversations}
                    meta={meta}
                    loading={loading}
                    filtered={activeFilterCount(filters) > 0}
                    onPage={(page) => setFilters({ ...filters, page })}
                    onOpen={open}
                    onRename={setRenaming}
                    onDelete={setDeleting}
                    onClearFilters={() => setFilters(emptyFilters)}
                />
            )}

            <RenameDialog
                open={renaming !== null}
                title={renaming?.title ?? ''}
                onClose={() => setRenaming(null)}
                onSave={(title) => void rename(title)}
            />

            <DeleteDialog
                open={deleting !== null}
                title={deleting?.title ?? ''}
                onClose={() => setDeleting(null)}
                onConfirm={() => void remove()}
            />
        </div>
    );
}

function fromParams(params: URLSearchParams): ConversationFilters {
    return {
        search: params.get('search') ?? '',
        agents: params.getAll('agents'),
        status: (params.get('status') as ConversationFilters['status']) ?? null,
        tools: params.getAll('tools'),
        from: params.get('from'),
        to: params.get('to'),
        sort: params.get('sort') === 'oldest' ? 'oldest' : 'newest',
        page: Number(params.get('page') ?? 1),
    };
}

/** Only what's set — an unused filter has no business in the address bar. */
function toParams(filters: ConversationFilters): URLSearchParams {
    const params = new URLSearchParams();

    if (filters.search) {
        params.set('search', filters.search);
    }

    filters.agents.forEach((agent) => params.append('agents', agent));
    filters.tools.forEach((tool) => params.append('tools', tool));

    if (filters.status) {
        params.set('status', filters.status);
    }

    if (filters.from) {
        params.set('from', filters.from);
    }

    if (filters.to) {
        params.set('to', filters.to);
    }

    if (filters.sort === 'oldest') {
        params.set('sort', 'oldest');
    }

    if (filters.page > 1) {
        params.set('page', String(filters.page));
    }

    return params;
}
