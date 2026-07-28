import { useCallback } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import { Bot } from 'lucide-react';
import { EmptyState } from '@/components/EmptyState';
import { PlaygroundShell } from '@/composed/PlaygroundShell';
import { useAgent } from '@/hooks/useAgent';
import { useConversation } from '@/hooks/useConversation';
import { usePanelState } from '@/hooks/usePanelState';

export default function Playground() {
    const { agent: slug } = useParams();
    const [params, setParams] = useSearchParams();
    const { agent, loading, notFound, error } = useAgent(slug);
    const { open, tab, openPanel, closePanel, setTab } = usePanelState();

    // A fresh playground starts empty; the conversation id only enters the URL
    // once the server announces it, which is what makes a refresh land back on
    // the same thread instead of resurrecting an older one.
    const conversationId = params.get('c');

    const { entries, sending, send, reset, clear, totals } = useConversation(slug, conversationId);

    const setConversation = useCallback(
        (id: string | null) => {
            setParams(
                (current) => {
                    const next = new URLSearchParams(current);

                    if (id) {
                        next.set('c', id);
                    } else {
                        next.delete('c');
                    }

                    return next;
                },
                { replace: true },
            );
        },
        [setParams],
    );

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
            entries={entries}
            sending={sending}
            totals={totals}
            onSend={(message) => void send(message, setConversation)}
            onNewConversation={() => {
                reset();
                setConversation(null);
            }}
            onClearConversation={() => void clear().then(() => setConversation(null))}
            panelOpen={open}
            tab={tab}
            onTabChange={setTab}
            onOpenPanel={openPanel}
            onClosePanel={closePanel}
        />
    );
}
