import { ArrowUpDown, Bot, CircleCheck, Search, Wrench } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/elements/DropdownMenu';
import { DateRangePicker } from '@/elements/DateRangePicker';
import { Input } from '@/elements/Input';
import { FilterMenu } from './FilterMenu';
import type { ConversationFilters, FilterOptions } from '@/types/conversation';

/**
 * The History filter bar — search, Agents / Status / Tools, date range, sort.
 *
 * Every change resets to page 1: staying on page 4 of a narrower result set
 * shows an empty table and looks like a bug.
 */
export function HistoryFilters({
    filters,
    options,
    onChange,
}: {
    filters: ConversationFilters;
    options: FilterOptions;
    onChange: (filters: ConversationFilters) => void;
}) {
    const update = (patch: Partial<ConversationFilters>) =>
        onChange({ ...filters, ...patch, page: 1 });

    return (
        <div className="flex flex-wrap items-center gap-3">
            <div className="min-w-56 flex-1">
                <Input
                    icon={Search}
                    type="search"
                    value={filters.search}
                    placeholder="Search"
                    aria-label="Search conversations"
                    data-testid="history-search"
                    onChange={(event) => update({ search: event.target.value })}
                />
            </div>

            <FilterMenu
                label="Agents"
                icon={Bot}
                testId="filter-agents"
                selected={filters.agents}
                onChange={(agents) => update({ agents })}
                options={options.agents.map((agent) => ({
                    value: agent.slug,
                    label: agent.name,
                }))}
            />

            <FilterMenu
                label="Status"
                icon={CircleCheck}
                testId="filter-status"
                selected={filters.status ? [filters.status] : []}
                // Single-valued on the server, so the last tick wins rather than
                // accumulating into a contradiction.
                onChange={(selected) =>
                    update({
                        status: (selected[selected.length - 1] as ConversationFilters['status']) ?? null,
                    })
                }
                options={[
                    { value: 'success', label: 'Success' },
                    { value: 'error', label: 'Error' },
                ]}
            />

            <FilterMenu
                label="Tools"
                icon={Wrench}
                testId="filter-tools"
                selected={filters.tools}
                onChange={(tools) => update({ tools })}
                options={options.tools.map((tool) => ({ value: tool, label: tool }))}
            />

            <DateRangePicker
                from={filters.from}
                to={filters.to}
                onChange={({ from, to }) => update({ from, to })}
            />

            <DropdownMenu>
                <DropdownMenuTrigger
                    data-testid="sort-by"
                    className="inline-flex h-9 items-center gap-2 rounded-lg px-2 text-sm text-muted-foreground transition-colors hover:text-foreground"
                >
                    <ArrowUpDown className="h-4 w-4" />
                    Sort By: {filters.sort === 'newest' ? 'Newest First' : 'Oldest First'}
                </DropdownMenuTrigger>

                <DropdownMenuContent align="end" side="bottom" className="w-44">
                    <DropdownMenuItem
                        active={filters.sort === 'newest'}
                        onSelect={() => update({ sort: 'newest' })}
                    >
                        Newest First
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        active={filters.sort === 'oldest'}
                        onSelect={() => update({ sort: 'oldest' })}
                    >
                        Oldest First
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}
