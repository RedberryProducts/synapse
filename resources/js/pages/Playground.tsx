import { useCallback, useEffect, useState } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import { Bot } from 'lucide-react';
import { EmptyState } from '@/components/EmptyState';
import { PlaygroundShell } from '@/composed/PlaygroundShell';
import { useAgent } from '@/hooks/useAgent';
import { useConversation } from '@/hooks/useConversation';
import { usePanelState } from '@/hooks/usePanelState';
import { recall, remember } from '@/lib/lastConversation';

export default function Playground() {
    const { agent: slug } = useParams();
    const [params, setParams] = useSearchParams();
    const { agent, loading, notFound, error } = useAgent(slug);
    const { open, tab, openPanel, closePanel, setTab } = usePanelState();

    // A per-send override, held only for as long as you are on this page. The
    // agent's own configuration is what the playground opens on, so an
    // experiment can never be mistaken for the agent's real setting later.
    const [model, setModel] = useState<string | null>(null);

    useEffect(() => setModel(null), [slug]);

    const conversationId = params.get('c');

    // Come back to an agent and you land where you left off — in the thread you
    // were reading, or on a blank page if that is where you deliberately were.
    // Per-browser state, so nothing reappears on a machine you have never used.
    useEffect(() => {
        if (!slug || conversationId !== null) {
            return;
        }

        const remembered = recall(slug);

        if (remembered !== null) {
            setParams((current) => {
                const next = new URLSearchParams(current);
                next.set('c', remembered);

                return next;
            }, { replace: true });
        }
        // Only on arrival: once you are here, the URL is the source of truth.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [slug]);

    useEffect(() => {
        if (slug) {
            remember(slug, conversationId);
        }
    }, [slug, conversationId]);

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

    // An agent that no longer exists but a conversation that does: the thread is
    // Synapse's own record and stays readable. Only the not-found-with-nothing-
    // to-show case is a dead end.
    if (notFound && conversationId === null) {
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
            agentMissing={notFound}
            loading={loading}
            error={error}
            entries={entries}
            sending={sending}
            totals={totals}
            model={model}
            onModelChange={setModel}
            onSend={(message, files) => void send(message, setConversation, { files, model })}
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
