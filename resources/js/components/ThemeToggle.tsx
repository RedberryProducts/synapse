import { useState } from 'react';
import { Monitor, Moon, Sun } from 'lucide-react';
import { getStoredTheme, setTheme, type Theme } from '@/lib/theme';
import { cn } from '@/lib/utils';

const order: Theme[] = ['system', 'light', 'dark'];
const icons: Record<Theme, typeof Sun> = { system: Monitor, light: Sun, dark: Moon };

export function ThemeToggle({ className }: { className?: string }) {
    const [theme, setThemeState] = useState<Theme>(getStoredTheme());
    const Icon = icons[theme];

    const cycle = () => {
        const next = order[(order.indexOf(theme) + 1) % order.length];
        setTheme(next);
        setThemeState(next);
    };

    return (
        <button
            type="button"
            onClick={cycle}
            title={`Theme: ${theme}`}
            className={cn(
                'inline-flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground',
                className,
            )}
        >
            <Icon className="h-4 w-4" />
        </button>
    );
}
