import { CalendarDays } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from './DropdownMenu';
import { Input } from './Input';
import { cn } from '@/lib/utils';

/*
| Basic element — date range, matching the Figma `Calendar` control's place in
| the filter bar.
|
| Two native date inputs rather than a hand-built two-month calendar grid. The
| browser's own picker is keyboard-accessible, localised, and understood by every
| developer using this dashboard — a bespoke grid would be a lot of code to end
| up slightly worse. If the design later needs the exact calendar visuals, this
| is one element to replace.
*/

export function DateRangePicker({
    from,
    to,
    onChange,
}: {
    from: string | null;
    to: string | null;
    onChange: (range: { from: string | null; to: string | null }) => void;
}) {
    const active = from !== null || to !== null;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                data-testid="filter-dates"
                className={cn(
                    'inline-flex h-9 items-center gap-2 rounded-lg border px-3 text-sm transition-colors',
                    active
                        ? 'border-subtle-foreground text-foreground'
                        : 'border-border text-muted-foreground hover:text-foreground',
                )}
            >
                <CalendarDays className="h-4 w-4" />
                {active ? `${from ?? '…'} – ${to ?? '…'}` : 'Date range'}
            </DropdownMenuTrigger>

            <DropdownMenuContent side="bottom" className="w-64 p-3">
                <div className="flex flex-col gap-3">
                    <label className="flex flex-col gap-1 text-xs text-muted-foreground">
                        From
                        <Input
                            type="date"
                            value={from ?? ''}
                            max={to ?? undefined}
                            onChange={(event) =>
                                onChange({ from: event.target.value || null, to })
                            }
                        />
                    </label>

                    <label className="flex flex-col gap-1 text-xs text-muted-foreground">
                        To
                        <Input
                            type="date"
                            value={to ?? ''}
                            min={from ?? undefined}
                            onChange={(event) =>
                                onChange({ from, to: event.target.value || null })
                            }
                        />
                    </label>

                    {active && (
                        <button
                            type="button"
                            onClick={() => onChange({ from: null, to: null })}
                            className="self-start text-xs text-muted-foreground hover:text-foreground"
                        >
                            Clear dates
                        </button>
                    )}
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
