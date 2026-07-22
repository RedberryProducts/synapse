import { useState } from 'react';
import { NavLink, Outlet } from 'react-router-dom';
import { Compass, History, PanelLeft } from 'lucide-react';
import { config } from '@/lib/config';
import { cn } from '@/lib/utils';
import { ThemeToggle } from './ThemeToggle';

const nav = [
    { to: '/', label: 'Discovery', icon: Compass, end: true },
    { to: '/history', label: 'History', icon: History, end: false },
];

export function AppShell() {
    const [collapsed, setCollapsed] = useState(false);

    return (
        <div className="flex h-screen w-full overflow-hidden bg-background text-foreground">
            <aside
                className={cn(
                    'flex shrink-0 flex-col border-r border-border bg-sidebar transition-[width] duration-200',
                    collapsed ? 'w-16' : 'w-72',
                )}
            >
                {/* Header */}
                <div className="flex h-14 items-center justify-between px-4">
                    {!collapsed && <span className="text-lg font-semibold">Synapse</span>}
                    <button
                        type="button"
                        onClick={() => setCollapsed((c) => !c)}
                        className="inline-flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        title={collapsed ? 'Expand' : 'Collapse'}
                    >
                        <PanelLeft className="h-4 w-4" />
                    </button>
                </div>

                {/* Recent conversations + agents (populated once the API lands) */}
                {!collapsed && (
                    <div className="flex-1 space-y-6 overflow-y-auto px-3 py-2">
                        <Section title="Recent Conversations">
                            <Empty>No conversations yet.</Empty>
                        </Section>
                        <Section title="Agents">
                            <Empty>No agents discovered.</Empty>
                        </Section>
                    </div>
                )}
                {collapsed && <div className="flex-1" />}

                {/* Workspace nav */}
                <nav className="space-y-1 border-t border-border px-3 py-3">
                    {!collapsed && (
                        <p className="px-2 pb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            Workspace
                        </p>
                    )}
                    {nav.map(({ to, label, icon: Icon, end }) => (
                        <NavLink
                            key={to}
                            to={to}
                            end={end}
                            className={({ isActive }) =>
                                cn(
                                    'flex items-center gap-3 rounded-md px-2 py-2 text-sm transition-colors',
                                    isActive
                                        ? 'bg-muted text-foreground'
                                        : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                )
                            }
                        >
                            <Icon className="h-4 w-4 shrink-0" />
                            {!collapsed && <span>{label}</span>}
                        </NavLink>
                    ))}
                </nav>

                {/* Footer */}
                <div className="flex items-center justify-between border-t border-border px-4 py-3">
                    {!collapsed && (
                        <span className="text-xs text-muted-foreground">v{config.version}</span>
                    )}
                    <ThemeToggle />
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
            <p className="px-2 pb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                {title}
            </p>
            {children}
        </div>
    );
}

function Empty({ children }: { children: React.ReactNode }) {
    return <p className="px-2 text-sm text-muted-foreground/70">{children}</p>;
}
