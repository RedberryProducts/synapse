import { useCallback, useEffect, useRef, useState } from 'react';
import { getConversations } from '@/lib/api';
import { onConversationsChanged } from '@/lib/conversationsChanged';
import type {
    ConversationFilters,
    ConversationPage,
    ConversationSummary,
    FilterOptions,
} from '@/types/conversation';

const SEARCH_DEBOUNCE_MS = 250;

/**
 * The History list for a given set of filters.
 *
 * Search is debounced because it fires per keystroke; every other filter applies
 * at once, since changing one is a deliberate act and waiting would just feel
 * broken.
 */
export function useConversations(filters: ConversationFilters) {
    const [conversations, setConversations] = useState<ConversationSummary[]>([]);
    const [options, setOptions] = useState<FilterOptions>({ agents: [], tools: [] });
    const [meta, setMeta] = useState<ConversationPage['meta']>({
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: 0,
    });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    // Bumped to force a re-fetch after a write, without changing the filters.
    const [revision, setRevision] = useState(0);
    const first = useRef(true);

    const refresh = useCallback(() => setRevision((value) => value + 1), []);

    // A rename or delete can now come from the sidebar as well as from this
    // table, and neither one changes the route. Without this the History page
    // would go on showing a row the user just deleted somewhere else.
    useEffect(() => onConversationsChanged(refresh), [refresh]);

    useEffect(() => {
        const controller = new AbortController();
        // The first render should paint immediately; later keystrokes wait.
        const delay = first.current ? 0 : filters.search ? SEARCH_DEBOUNCE_MS : 0;

        first.current = false;
        setLoading(true);

        const timer = setTimeout(() => {
            getConversations(filters, controller.signal)
                .then((page) => {
                    setConversations(page.data);
                    setMeta(page.meta);
                    setOptions(page.filters);
                    setError(null);
                })
                .catch((reason: unknown) => {
                    if (!controller.signal.aborted) {
                        setError(reason instanceof Error ? reason.message : 'Could not load history.');
                    }
                })
                .finally(() => {
                    if (!controller.signal.aborted) {
                        setLoading(false);
                    }
                });
        }, delay);

        return () => {
            controller.abort();
            clearTimeout(timer);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        filters.search,
        filters.status,
        filters.from,
        filters.to,
        filters.sort,
        filters.page,
        filters.agents.join(','),
        filters.tools.join(','),
        revision,
    ]);

    return { conversations, options, meta, loading, error, refresh };
}
