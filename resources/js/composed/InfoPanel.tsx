import { Info, X } from 'lucide-react';
import { Button } from '@/elements/Button';
import { Skeleton } from '@/elements/Skeleton';
import { Tabs, type TabOption } from '@/elements/Tabs';
import { ConfigTab } from '@/components/ConfigTab';
import { PromptTab } from '@/components/PromptTab';
import { ToolsTab } from '@/components/ToolsTab';
import { UnresolvableHint } from '@/components/UnresolvableHint';
import type { AgentDetail } from '@/types/agent';

export type InfoTab = 'config' | 'prompt' | 'tools';

const tabs: TabOption<InfoTab>[] = [
    { value: 'config', label: 'Config' },
    { value: 'prompt', label: 'Prompt' },
    { value: 'tools', label: 'Tools' },
];

export function InfoPanel({
    agent,
    loading,
    error,
    tab,
    onTabChange,
    onClose,
}: {
    agent: AgentDetail | null;
    loading: boolean;
    error: string | null;
    tab: InfoTab;
    onTabChange: (tab: InfoTab) => void;
    onClose: () => void;
}) {
    return (
        <aside
            data-testid="info-panel"
            className="flex w-80 shrink-0 flex-col border-l border-border bg-sidebar"
        >
            <div className="flex h-14 items-center justify-between border-b border-border px-4">
                <span className="flex items-center gap-2 font-medium">
                    <Info className="h-4 w-4" />
                    Info
                </span>
                <Button variant="ghost" size="icon" onClick={onClose} aria-label="Close info panel">
                    <X className="h-4 w-4" />
                </Button>
            </div>

            <div className="flex flex-1 flex-col gap-4 overflow-y-auto p-4">
                {loading && (
                    <>
                        <Skeleton className="h-9 w-full" />
                        <Skeleton className="h-40 w-full rounded-xl" />
                        <Skeleton className="h-32 w-full rounded-xl" />
                    </>
                )}

                {!loading && error && <p className="text-sm text-destructive">{error}</p>}

                {!loading && agent && !agent.available && (
                    <UnresolvableHint
                        kind={agent.error_kind}
                        dependencies={agent.unresolvable}
                        error={agent.error}
                    />
                )}

                {!loading && agent && agent.available && (
                    <>
                        <Tabs options={tabs} value={tab} onChange={onTabChange} />
                        {tab === 'config' && <ConfigTab agent={agent} />}
                        {tab === 'prompt' && <PromptTab agent={agent} />}
                        {tab === 'tools' && <ToolsTab agent={agent} />}
                    </>
                )}
            </div>
        </aside>
    );
}
