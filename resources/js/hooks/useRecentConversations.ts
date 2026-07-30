import { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { getConversations } from '@/lib/api';
import { onConversationsChanged } from '@/lib/conversationsChanged';
import type { ConversationSummary } from '@/types/conversation';

const LIMIT = 5;

/**
 * The sidebar's short list of recent conversations.
 *
 * Refreshed on navigation and whenever a conversation is written. Navigation
 * alone is not enough: a rename or delete on the History page never changes the
 * route, and the sidebar would go on showing a title that no longer exists.
 */
export function useRecentConversations() {
    const [conversations, setConversations] = useState<ConversationSummary[]>([]);
    const [loading, setLoading] = useState(true);
    const [revision, setRevision] = useState(0);
    const location = useLocation();

    useEffect(() => onConversationsChanged(() => setRevision((value) => value + 1)), []);

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
    }, [location.pathname, location.search, revision]);

    return { conversations, loading };
}
