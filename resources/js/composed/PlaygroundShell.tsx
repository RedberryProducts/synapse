import { Info, TriangleAlert } from 'lucide-react';
import { ChatComposer } from '@/components/ChatComposer';
import { ConversationMenu } from '@/components/ConversationMenu';
import { ConversationTokens } from '@/components/ConversationTokens';
import { StatelessNotice } from '@/components/StatelessNotice';
import { StreamingNotice } from '@/components/StreamingNotice';
import { UnresolvableHint } from '@/components/UnresolvableHint';
import { Badge } from '@/elements/Badge';
import { Button } from '@/elements/Button';
import { config } from '@/lib/config';
import { ChatThread } from './ChatThread';
import { InfoPanel, type InfoTab } from './InfoPanel';
import type { AgentDetail } from '@/types/agent';
import type { ApprovalDecision, ChatEntry, PendingTurnApproval } from '@/types/chat';

/**
 * The playground page frame: agent header, chat thread, composer, Info panel.
 */
export function PlaygroundShell({
    agent,
    agentMissing = false,
    loading,
    error,
    entries,
    approval,
    approvalError,
    decisions,
    onDecision,
    onContinueApproval,
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
    /** The class is gone; the conversation is still readable. */
    agentMissing?: boolean;
    loading: boolean;
    error: string | null;
    entries: ChatEntry[];
    approval: PendingTurnApproval | null;
    approvalError: string | null;
    decisions: Record<string, ApprovalDecision>;
    onDecision: (id: string, decision: ApprovalDecision) => void;
    onContinueApproval: () => void;
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
                                    {/*
                                        An agent with no explicit model resolves
                                        one from its tier attribute. Showing the
                                        tier matches the Discovery card and beats
                                        showing nothing at all.
                                    */}
                                    {agent.model
                                        ? ` / ${agent.model}`
                                        : agent.model_tier !== 'default' && ` / ${agent.model_tier}`}
                                </span>
                            )}
                        </div>

                        {started && (
                            <ConversationTokens
                                prompt={totals.prompt}
                                completion={totals.completion}
                            />
                        )}

                        {agentMissing && (
                            <Badge
                                data-testid="agent-missing-notice"
                                variant="pill"
                                className="text-destructive"
                            >
                                <TriangleAlert className="h-3 w-3" />
                                This agent no longer exists — the conversation is read-only
                            </Badge>
                        )}

                        {agent?.available && !agent.capabilities.conversational && <StatelessNotice />}

                        {/*
                            A property of the runtime rather than of the agent,
                            so it shows on every playground — but only here,
                            where someone is about to watch a reply arrive.
                        */}
                        {!config.streaming && <StreamingNotice />}
                    </div>

                    <div className="flex shrink-0 items-center gap-1">
                        <ConversationMenu
                            onNew={onNewConversation}
                            onClear={onClearConversation}
                            canClear={started && !sending && approval?.state !== 'running'}
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

                {agent?.available === false && (
                    <div className="px-8 pt-4">
                        <UnresolvableHint
                            kind={agent.error_kind}
                            dependencies={agent.unresolvable}
                            error={agent.error}
                        />
                        <p className="mt-2 text-xs text-subtle-foreground">
                            Fix the error above, then refresh. Saved conversations remain readable.
                        </p>
                    </div>
                )}

                {agent?.available !== false || started ? (
                    <ChatThread entries={entries} agentModel={agent?.model} approvalFor={(entry) => {
                        if (!approval || entry.kind !== 'tool' || entry.turnId !== approval.messageId) return undefined;
                        const request = approval.requests.find((request) => request.id === entry.toolCallId);
                        return request ? {
                            reason: request.reason,
                            decision: decisions[request.id],
                            disabled: sending || approval.state === 'running' || agentMissing || !agent?.available,
                            onDecide: (decision) => onDecision(request.id, decision),
                        } : undefined;
                    }} />
                ) : (
                    <div className="flex-1" />
                )}

                {agent?.available !== false && (
                    <div className="px-8 pt-2 pb-6">
                        <div className="mx-auto max-w-3xl">
                            {approval && (
                                <div data-testid="approval-summary" className="mb-3 flex flex-col gap-2 text-sm text-muted-foreground" role="status">
                                    <p>{approval.state === 'running'
                                        ? 'Approval decisions submitted. The run may still be processing. Reload to check its status; do not repeat the action.'
                                        : 'Review the tool calls above to continue, or start a new conversation.'}</p>
                                    {approval.requests.length > 1 && approval.state === 'pending' && (
                                        <Button size="sm" disabled={sending || agentMissing || !approval.requests.every((request) => decisions[request.id])} onClick={onContinueApproval}>
                                            Continue with decisions
                                        </Button>
                                    )}
                                </div>
                            )}
                            {approvalError && <p role="alert" className="mb-3 text-sm text-destructive">{approvalError}</p>}
                            <ChatComposer
                                onSend={onSend}
                                models={agent?.models ?? []}
                                model={model}
                                onModelChange={onModelChange}
                                disabled={sending || agentMissing || Boolean(approval)}
                                autoFocus={!started}
                            />
                        </div>
                    </div>
                )}
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
