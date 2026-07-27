import { PageHeader } from '@/components/PageHeader';
import { AgentGrid } from '@/composed/AgentGrid';
import { useAgents } from '@/hooks/useAgents';

export default function Discovery() {
    const { agents, loading, error } = useAgents();

    return (
        <div className="p-8">
            <PageHeader
                title="Agents"
                count={loading ? undefined : agents.length}
                subtitle="Auto-scanned from your configured agent paths on every request. Click a card to open the chat playground."
            />

            <div className="mt-8">
                <AgentGrid agents={agents} loading={loading} error={error} />
            </div>
        </div>
    );
}
