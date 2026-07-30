import { ChevronDown } from 'lucide-react';
import { Checkbox } from '@/elements/Checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/elements/DropdownMenu';
import { cn } from '@/lib/utils';

/**
 * A multi-select filter with a count badge — the Figma filter-bar control, used
 * for Agents, Status and Tools.
 *
 * Stays open while you tick boxes: picking three agents shouldn't cost three
 * trips to the menu.
 */
export function FilterMenu({
    label,
    icon: Icon,
    options,
    selected,
    onChange,
    testId,
}: {
    label: string;
    icon: React.ElementType;
    options: { value: string; label: string }[];
    selected: string[];
    onChange: (selected: string[]) => void;
    testId?: string;
}) {
    const toggle = (value: string, checked: boolean) =>
        onChange(checked ? [...selected, value] : selected.filter((item) => item !== value));

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                data-testid={testId}
                className={cn(
                    'inline-flex h-9 items-center gap-2 rounded-lg border px-3 text-sm transition-colors',
                    selected.length > 0
                        ? 'border-subtle-foreground text-foreground'
                        : 'border-border text-muted-foreground hover:text-foreground',
                )}
            >
                <Icon className="h-4 w-4" />
                {label}
                {selected.length > 0 && (
                    <span
                        aria-label={`${selected.length} selected`}
                        className="inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] text-primary-foreground"
                    >
                        {selected.length}
                    </span>
                )}
                <ChevronDown className="h-3 w-3 opacity-60" />
            </DropdownMenuTrigger>

            <DropdownMenuContent side="bottom" className="max-h-72 w-56 overflow-y-auto p-1">
                {options.length === 0 ? (
                    <p className="px-2 py-1.5 text-xs text-subtle-foreground">Nothing to filter by yet.</p>
                ) : (
                    options.map((option) => (
                        <Checkbox
                            key={option.value}
                            label={option.label}
                            checked={selected.includes(option.value)}
                            onChange={(checked) => toggle(option.value, checked)}
                        />
                    ))
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
