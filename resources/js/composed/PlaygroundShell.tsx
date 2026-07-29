import { Info } from 'lucide-react';
import { ChatComposer } from '@/components/ChatComposer';
import { ConversationMenu } from '@/components/ConversationMenu';
import { ConversationTokens } from '@/components/ConversationTokens';
import { StatelessNotice } from '@/components/StatelessNotice';
import { Button } from '@/elements/Button';
import { ChatThread } from './ChatThread';
import { InfoPanel, type InfoTab } from './InfoPanel';
import type { AgentDetail } from '@/types/agent';
import type { ChatEntry } from '@/types/chat';

/**
 * The playground page frame: agent header, chat thread, composer, Info panel.
 */
export function PlaygroundShell({
    agent,
    loading,
    error,
    entries,
    sending,
    totals,
    model,
    onModelChange,
    onSend,
    onNewConversation,
    onClearConversation,
    panelOpen,
    tab,
    onTabChange,
    onOpenPanel,
    onClosePanel,
}: {
    agent: AgentDetail | null;
    loading: boolean;
    error: string | null;
    entries: ChatEntry[];
    sending: boolean;
    totals: { prompt: number; completion: number };
    model: string | null;
    onModelChange: (model: string | null) => void;
    onSend: (message: string, files: File[]) => void;
    onNewConversation: () => void;
    onClearConversation: () => void;
    panelOpen: boolean;
    tab: InfoTab;
    onTabChange: (tab: InfoTab) => void;
    onOpenPanel: () => void;
    onClosePanel: () => void;
}) {
    const started = entries.length > 0;

    return (
        <div className="flex h-full">
            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex items-start justify-between gap-4 border-b border-border px-8 py-4">
                    <div className="flex min-w-0 flex-col gap-2">
                        <div className="flex min-w-0 items-baseline gap-2">
                            <h1 className="truncate text-lg font-semibold">
                                {agent?.name ?? (loading ? 'Loading…' : 'Agent')}
                            </h1>
                            {agent?.provider && (
                                <span className="truncate text-xs text-muted-foreground">
                                    {agent.provider}
                                    {agent.model && ` / ${agent.model}`}
                                </span>
                            )}
                        </div>

                        {started && (
                            <ConversationTokens
                                prompt={totals.prompt}
                                completion={totals.completion}
                            />
                        )}

                        {agent && !agent.capabilities.conversational && <StatelessNotice />}
                    </div>

                    <div className="flex shrink-0 items-center gap-1">
                        <ConversationMenu
                            onNew={onNewConversation}
                            onClear={onClearConversation}
                            canClear={started}
                        />

                        {!panelOpen && (
                            <Button
                                variant="ghost"
                                size="icon"
                                onClick={onOpenPanel}
                                title="Agent info"
                                aria-label="Open info panel"
                            >
                                <Info className="h-4 w-4" />
                            </Button>
                        )}
                    </div>
                </header>

                <ChatThread entries={entries} />

                <div className="px-8 pt-2 pb-6">
                    <div className="mx-auto max-w-3xl">
                        <ChatComposer
                            onSend={onSend}
                            models={agent?.models ?? []}
                            model={model}
                            onModelChange={onModelChange}
                            disabled={sending || agent?.available === false}
                            autoFocus={!started}
                        />
                    </div>
                </div>
            </div>

            {panelOpen && (
                <InfoPanel
                    agent={agent}
                    loading={loading}
                    error={error}
                    tab={tab}
                    onTabChange={onTabChange}
                    onClose={onClosePanel}
                />
            )}
        </div>
    );
}
