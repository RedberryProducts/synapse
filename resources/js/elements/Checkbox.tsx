import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';

/*
| Basic element — checkbox, matching the Figma `Checkbox` used in the filter
| menus. A real `<input>` underneath so keyboard and screen readers work; the
| box is drawn on top.
*/

export function Checkbox({
    checked,
    onChange,
    label,
    className,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    label: string;
    className?: string;
}) {
    return (
        <label
            className={cn(
                'flex cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 text-sm',
                'hover:bg-muted',
                className,
            )}
        >
            <span className="relative flex h-4 w-4 shrink-0 items-center justify-center">
                <input
                    type="checkbox"
                    checked={checked}
                    onChange={(event) => onChange(event.target.checked)}
                    className="peer absolute h-4 w-4 cursor-pointer appearance-none rounded border border-input focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none checked:border-primary checked:bg-primary"
                />
                <Check className="pointer-events-none hidden h-3 w-3 text-primary-foreground peer-checked:block" />
            </span>

            <span className="min-w-0 truncate">{label}</span>
        </label>
    );
}
