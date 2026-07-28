import { useEffect, useState } from 'react';
import { getAgent } from '@/lib/api';
import type { AgentDetail } from '@/types/agent';

interface UseAgent {
    agent: AgentDetail | null;
    loading: boolean;
    notFound: boolean;
    error: string | null;
}

/** Loads one agent's full detail for the Info panel. */
export function useAgent(slug: string | undefined): UseAgent {
    const [agent, setAgent] = useState<AgentDetail | null>(null);
    const [loading, setLoading] = useState(true);
    const [notFound, setNotFound] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!slug) {
            setLoading(false);
            setNotFound(true);

            return;
        }

        const controller = new AbortController();

        setLoading(true);
        setNotFound(false);
        setError(null);

        getAgent(slug, controller.signal)
            .then((data) => setAgent(data))
            .catch((cause: unknown) => {
                if (controller.signal.aborted) {
                    return;
                }

                const message = cause instanceof Error ? cause.message : 'Failed to load agent.';

                if (message.includes('404')) {
                    setNotFound(true);
                } else {
                    setError(message);
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, [slug]);

    return { agent, loading, notFound, error };
}
