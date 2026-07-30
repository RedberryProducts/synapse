import { useEffect, useState } from 'react';
import { Outlet } from 'react-router-dom';
import { Compass, History, PanelLeft } from 'lucide-react';
import { Button } from '@/elements/Button';
import { SidebarNavLink } from '@/elements/SidebarItem';
import { SidebarAgentList } from '@/components/SidebarAgentList';
import { SidebarConversationList } from '@/components/SidebarConversationList';
import { ThemeSwitcher } from '@/components/ThemeSwitcher';
import { useAgents } from '@/hooks/useAgents';
import { useRecentConversations } from '@/hooks/useRecentConversations';
import { config } from '@/lib/config';
import { cn } from '@/lib/utils';

const COLLAPSED_KEY = 'synapse-sidebar-collapsed';

const nav = [
    { to: '/', label: 'Discovery', icon: Compass, end: true },
    { to: '/history', label: 'History', icon: History, end: false },
];

export function AppShell() {
    const [collapsed, setCollapsed] = useState(
        () => localStorage.getItem(COLLAPSED_KEY) === '1',
    );
    const { agents, loading } = useAgents();
    const { conversations, loading: loadingConversations } = useRecentConversations();

    useEffect(() => {
        localStorage.setItem(COLLAPSED_KEY, collapsed ? '1' : '0');
    }, [collapsed]);

    return (
        <div className="flex h-screen w-full overflow-hidden bg-background text-foreground">
            <aside
                className={cn(
                    'flex shrink-0 flex-col border-r border-border bg-sidebar transition-[width] duration-200',
                    collapsed ? 'w-16' : 'w-72',
                )}
            >
                <div className="flex h-14 items-center justify-between px-4">
                    {!collapsed && <span className="text-lg font-semibold">Synapse</span>}
                    <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => setCollapsed((value) => !value)}
                        title={collapsed ? 'Expand' : 'Collapse'}
                        aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                    >
                        <PanelLeft className="h-4 w-4" />
                    </Button>
                </div>

                {!collapsed ? (
                    <div className="flex-1 space-y-6 overflow-y-auto px-3 py-2">
                        <Section title="Recent Conversations">
                            <SidebarConversationList
                                conversations={conversations}
                                loading={loadingConversations}
                            />
                        </Section>
                        <Section title="Agents">
                            <SidebarAgentList agents={agents} loading={loading} />
                        </Section>
                    </div>
                ) : (
                    <div className="flex-1" />
                )}

                <nav className="space-y-1 border-t border-border px-3 py-3">
                    {!collapsed && (
                        <p className="px-2 pb-1 text-xs font-medium tracking-wide text-subtle-foreground uppercase">
                            Workspace
                        </p>
                    )}
                    {nav.map(({ to, label, icon, end }) => (
                        <SidebarNavLink
                            key={to}
                            to={to}
                            end={end}
                            icon={icon}
                            label={label}
                            collapsed={collapsed}
                        />
                    ))}
                    <ThemeSwitcher collapsed={collapsed} />
                </nav>

                <div className="flex items-center justify-between border-t border-border px-4 py-3">
                    {!collapsed && (
                        <span className="text-xs text-subtle-foreground">
                            v{config.version}
                            {!loading && ` · ${agents.length} agent${agents.length === 1 ? '' : 's'}`}
                        </span>
                    )}
                </div>
            </aside>

            <main className="flex-1 overflow-y-auto">
                <Outlet />
            </main>
        </div>
    );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <div>
            <p className="px-2 pb-2 text-xs font-medium tracking-wide text-subtle-foreground uppercase">
                {title}
            </p>
            {children}
        </div>
    );
}
