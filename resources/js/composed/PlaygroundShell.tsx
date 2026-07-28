import { Info } from 'lucide-react';
import { Button } from '@/elements/Button';
import { InfoPanel, type InfoTab } from './InfoPanel';
import type { AgentDetail } from '@/types/agent';

/**
 * The playground page frame: agent header, chat area, and the Info panel.
 *
 * The chat area is a placeholder until Epic 3 — this epic delivers the panel.
 */
export function PlaygroundShell({
    agent,
    loading,
    error,
    panelOpen,
    tab,
    onTabChange,
    onOpenPanel,
    onClosePanel,
}: {
    agent: AgentDetail | null;
    loading: boolean;
    error: string | null;
    panelOpen: boolean;
    tab: InfoTab;
    onTabChange: (tab: InfoTab) => void;
    onOpenPanel: () => void;
    onClosePanel: () => void;
}) {
    return (
        <div className="flex h-full">
            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex h-14 items-center justify-between gap-4 border-b border-border px-8">
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
                </header>

                <div className="flex flex-1 items-center justify-center p-8">
                    <p className="text-sm text-subtle-foreground">
                        The chat playground arrives in the next release.
                    </p>
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
