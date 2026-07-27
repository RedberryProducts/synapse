import { Bot } from 'lucide-react';
import { AgentCard } from '@/components/AgentCard';
import { EmptyState } from '@/components/EmptyState';
import { Skeleton } from '@/elements/Skeleton';
import type { Agent } from '@/types/agent';

const grid = 'grid gap-5 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4';

export function AgentGrid({
    agents,
    loading,
    error,
    paths,
}: {
    agents: Agent[];
    loading: boolean;
    error: string | null;
    paths?: string[];
}) {
    if (loading) {
        return (
            <div className={grid}>
                {Array.from({ length: 4 }).map((_, index) => (
                    <Skeleton key={index} className="h-52 rounded-xl" />
                ))}
            </div>
        );
    }

    if (error) {
        return (
            <EmptyState title="Could not load agents">
                <p className="text-destructive">{error}</p>
            </EmptyState>
        );
    }

    if (agents.length === 0) {
        return (
            <EmptyState icon={Bot} title="No agents found">
                <p>
                    Create a class implementing <code>Laravel\Ai\Contracts\Agent</code>
                    {paths?.length ? ` in ${paths.join(', ')}` : ' in app/Agents/'} and refresh.
                </p>
            </EmptyState>
        );
    }

    return (
        <div className={grid}>
            {agents.map((agent) => (
                <AgentCard key={agent.slug} agent={agent} />
            ))}
        </div>
    );
}
