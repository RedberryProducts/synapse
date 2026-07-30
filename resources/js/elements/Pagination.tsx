import { ChevronLeft, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';

/*
| Basic element — pager, matching the Figma History footer:
| `Previous  1 2 3 …  Next`.
*/

export function Pagination({
    page,
    lastPage,
    onChange,
}: {
    page: number;
    lastPage: number;
    onChange: (page: number) => void;
}) {
    if (lastPage <= 1) {
        return null;
    }

    return (
        <nav
            data-testid="pagination"
            aria-label="Pagination"
            className="flex items-center justify-center gap-1 text-sm"
        >
            <button
                type="button"
                onClick={() => onChange(page - 1)}
                disabled={page <= 1}
                className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-muted-foreground transition-colors hover:text-foreground disabled:pointer-events-none disabled:opacity-40"
            >
                <ChevronLeft className="h-4 w-4" />
                Previous
            </button>

            {pages(page, lastPage).map((entry, index) =>
                entry === null ? (
                    <span key={`gap-${index}`} className="px-1 text-subtle-foreground">
                        …
                    </span>
                ) : (
                    <button
                        key={entry}
                        type="button"
                        onClick={() => onChange(entry)}
                        aria-current={entry === page ? 'page' : undefined}
                        className={cn(
                            'h-7 min-w-7 rounded-md px-2 transition-colors',
                            entry === page
                                ? 'bg-muted text-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {entry}
                    </button>
                ),
            )}

            <button
                type="button"
                onClick={() => onChange(page + 1)}
                disabled={page >= lastPage}
                className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-muted-foreground transition-colors hover:text-foreground disabled:pointer-events-none disabled:opacity-40"
            >
                Next
                <ChevronRight className="h-4 w-4" />
            </button>
        </nav>
    );
}

/**
 * The page numbers to show, with `null` standing for an ellipsis.
 *
 * Always the first and last page plus a window around the current one, so the
 * control's width stays stable however deep the history goes.
 */
function pages(page: number, lastPage: number): (number | null)[] {
    if (lastPage <= 7) {
        return Array.from({ length: lastPage }, (_, index) => index + 1);
    }

    const window = [page - 1, page, page + 1].filter((n) => n > 1 && n < lastPage);
    const result: (number | null)[] = [1];

    if (window[0] !== undefined && window[0] > 2) {
        result.push(null);
    }

    result.push(...window);

    if (window[window.length - 1] !== undefined && window[window.length - 1] < lastPage - 1) {
        result.push(null);
    }

    result.push(lastPage);

    return result;
}
