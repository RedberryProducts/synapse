import { NavLink } from 'react-router-dom';
import { sidebarItemClass } from '@/elements/SidebarItem';
import { cn } from '@/lib/utils';
import type { Agent } from '@/types/agent';

/** The Agents quick-list in the sidebar (Figma `Agents`). */
export function SidebarAgentList({ agents, loading }: { agents: Agent[]; loading: boolean }) {
    if (loading) {
        return <p className="px-2 text-sm text-subtle-foreground">Loading…</p>;
    }

    if (agents.length === 0) {
        return <p className="px-2 text-sm text-subtle-foreground">No agents discovered.</p>;
    }

    return (
        <div data-testid="sidebar-agents" className="flex flex-col gap-0.5">
            {agents.map((agent) => (
                <NavLink
                    key={agent.slug}
                    to={`/playground/${agent.slug}`}
                    className={({ isActive }) =>
                        cn(
                            sidebarItemClass(isActive),
                            'min-w-0 py-1.5 text-xs tracking-wide uppercase',
                        )
                    }
                >
                    <span
                        className="min-w-0 flex-1 truncate"
                        title={agent.name}
                        data-testid="sidebar-agent-name"
                    >
                        {agent.name}
                    </span>
                </NavLink>
            ))}
        </div>
    );
}
