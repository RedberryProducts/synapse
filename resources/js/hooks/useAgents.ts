import { useEffect, useState } from 'react';
import { getAgents } from '@/lib/api';
import type { Agent } from '@/types/agent';

interface UseAgents {
    agents: Agent[];
    loading: boolean;
    error: string | null;
}

/**
 * Loads the discovered agents. Plain fetch-on-mount — no data-fetching library
 * until one is justified.
 */
export function useAgents(): UseAgents {
    const [agents, setAgents] = useState<Agent[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const controller = new AbortController();

        getAgents(controller.signal)
            .then((data) => {
                setAgents(data);
                setError(null);
            })
            .catch((cause: unknown) => {
                if (!controller.signal.aborted) {
                    setError(cause instanceof Error ? cause.message : 'Failed to load agents.');
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, []);

    return { agents, loading, error };
}
