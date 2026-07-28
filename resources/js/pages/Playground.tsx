import { useParams } from 'react-router-dom';
import { Bot } from 'lucide-react';
import { EmptyState } from '@/components/EmptyState';
import { PlaygroundShell } from '@/composed/PlaygroundShell';
import { useAgent } from '@/hooks/useAgent';
import { usePanelState } from '@/hooks/usePanelState';

export default function Playground() {
    const { agent: slug } = useParams();
    const { agent, loading, notFound, error } = useAgent(slug);
    const { open, tab, openPanel, closePanel, setTab } = usePanelState();

    if (notFound) {
        return (
            <div className="p-8">
                <EmptyState icon={Bot} title="Agent not found">
                    <p>
                        No discovered agent matches <code>{slug}</code>. It may have been
                        renamed or removed — head back to Discovery.
                    </p>
                </EmptyState>
            </div>
        );
    }

    return (
        <PlaygroundShell
            agent={agent}
            loading={loading}
            error={error}
            panelOpen={open}
            tab={tab}
            onTabChange={setTab}
            onOpenPanel={openPanel}
            onClosePanel={closePanel}
        />
    );
}
