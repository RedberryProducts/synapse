import { NavLink } from 'react-router-dom';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

/*
| Basic element — sidebar row.
|
| Styled from Figma `Navigation` (355:9764). Used for both router links and
| non-navigating rows (e.g. the theme switcher trigger) so every item in the
| Workspace menu shares one visual definition.
*/

const base =
    'flex w-full items-center gap-3 rounded-md px-2 py-2 text-sm transition-colors';
const idle = 'text-muted-foreground hover:bg-muted hover:text-foreground';
const active = 'bg-muted text-foreground';

export function sidebarItemClass(isActive = false): string {
    return cn(base, isActive ? active : idle);
}

export function SidebarNavLink({
    to,
    icon: Icon,
    label,
    collapsed = false,
    end = false,
}: {
    to: string;
    icon: LucideIcon;
    label: string;
    collapsed?: boolean;
    end?: boolean;
}) {
    return (
        <NavLink to={to} end={end} className={({ isActive }) => sidebarItemClass(isActive)}>
            <Icon className="h-4 w-4 shrink-0" />
            {!collapsed && <span>{label}</span>}
        </NavLink>
    );
}
