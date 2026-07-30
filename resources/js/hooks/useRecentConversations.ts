import { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { getConversations } from '@/lib/api';
import type { ConversationSummary } from '@/types/conversation';

const LIMIT = 5;

/**
 * The sidebar's short list of recent conversations.
 *
 * Re-fetched on navigation rather than kept in a shared store: writes happen in
 * the playground and the list lives in the shell, and moving between the two is
 * exactly when the list needs to be right. One request per navigation is a
 * cheaper price than a store for a five-row list.
 */
export function useRecentConversations() {
    const [conversations, setConversations] = useState<ConversationSummary[]>([]);
    const [loading, setLoading] = useState(true);
    const location = useLocation();

    useEffect(() => {
        const controller = new AbortController();

        getConversations({ page: 1 }, controller.signal)
            .then((page) => setConversations(page.data.slice(0, LIMIT)))
            .catch(() => {
                // An empty sidebar is a fine failure mode; the page still works.
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, [location.pathname, location.search]);

    return { conversations, loading };
}
