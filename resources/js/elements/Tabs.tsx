import { cn } from '@/lib/utils';

/*
| Basic element — tabs (Figma `Tabs` 187:1382).
|
| Controlled: the parent owns the active value. Arrow keys move between tabs,
| matching the WAI-ARIA tabs pattern.
*/

export interface TabOption<T extends string> {
    value: T;
    label: string;
}

export function Tabs<T extends string>({
    options,
    value,
    onChange,
    className,
}: {
    options: TabOption<T>[];
    value: T;
    onChange: (value: T) => void;
    className?: string;
}) {
    const move = (delta: number) => {
        const index = options.findIndex((option) => option.value === value);
        const next = options[(index + delta + options.length) % options.length];

        onChange(next.value);
    };

    return (
        <div
            role="tablist"
            className={cn('flex gap-1 rounded-lg bg-muted p-1', className)}
            onKeyDown={(event) => {
                if (event.key === 'ArrowRight') {
                    event.preventDefault();
                    move(1);
                }
                if (event.key === 'ArrowLeft') {
                    event.preventDefault();
                    move(-1);
                }
            }}
        >
            {options.map((option) => {
                const active = option.value === value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        tabIndex={active ? 0 : -1}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'flex-1 rounded-md px-3 py-1.5 text-sm transition-colors',
                            active
                                ? 'bg-card text-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}
