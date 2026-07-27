import { useState } from 'react';
import { Check, ChevronDown, Monitor, Moon, Sun } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/elements/DropdownMenu';
import { sidebarItemClass } from '@/elements/SidebarItem';
import { getStoredTheme, setTheme, type Theme } from '@/lib/theme';
import { cn } from '@/lib/utils';

/*
| Theme switcher — sits in the Workspace menu beside Discovery / History.
|
| Not yet a dedicated Figma component: composed from the existing `Navigation`
| (355:9764) row style and `Dropdown item` (324:25180) menu style so it matches
| the sidebar today and is easy to swap when the designer publishes one.
*/

const options: { value: Theme; label: string; icon: typeof Sun }[] = [
    { value: 'light', label: 'Light', icon: Sun },
    { value: 'dark', label: 'Dark', icon: Moon },
    { value: 'system', label: 'System', icon: Monitor },
];

export function ThemeSwitcher({ collapsed = false }: { collapsed?: boolean }) {
    const [theme, setThemeState] = useState<Theme>(getStoredTheme());
    const current = options.find((option) => option.value === theme) ?? options[2];
    const CurrentIcon = current.icon;

    const select = (value: Theme) => {
        setTheme(value);
        setThemeState(value);
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                className={cn(sidebarItemClass(), 'justify-between')}
                title={`Theme: ${current.label}`}
            >
                <span className="flex items-center gap-3">
                    <CurrentIcon className="h-4 w-4 shrink-0" />
                    {!collapsed && <span>{current.label}</span>}
                </span>
                {!collapsed && <ChevronDown className="h-3.5 w-3.5 opacity-60" />}
            </DropdownMenuTrigger>

            <DropdownMenuContent className="w-40">
                {options.map(({ value, label, icon: Icon }) => (
                    <DropdownMenuItem
                        key={value}
                        active={value === theme}
                        onSelect={() => select(value)}
                    >
                        <Icon className="h-4 w-4 shrink-0" />
                        <span className="flex-1">{label}</span>
                        {value === theme && <Check className="h-3.5 w-3.5" />}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
