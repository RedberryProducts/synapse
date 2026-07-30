import { cn } from '@/lib/utils';

/*
| Basic elements — table primitives matching the Figma `Data Table`.
|
| Structure and styling only: no sorting, no data, no selection. The History
| page owns all of that, so these stay reusable for any table that follows.
*/

export function Table({ className, ...props }: React.TableHTMLAttributes<HTMLTableElement>) {
    return (
        <div className="overflow-x-auto rounded-xl border border-border">
            <table className={cn('w-full border-collapse text-sm', className)} {...props} />
        </div>
    );
}

export function TableHead({ className, ...props }: React.HTMLAttributes<HTMLTableSectionElement>) {
    return <thead className={cn('bg-muted/40', className)} {...props} />;
}

export function TableBody(props: React.HTMLAttributes<HTMLTableSectionElement>) {
    return <tbody {...props} />;
}

export function TableRow({
    interactive = false,
    className,
    ...props
}: React.HTMLAttributes<HTMLTableRowElement> & { interactive?: boolean }) {
    return (
        <tr
            className={cn(
                'border-b border-border last:border-b-0',
                interactive && 'cursor-pointer transition-colors hover:bg-muted/40',
                className,
            )}
            {...props}
        />
    );
}

export function TableHeader({ className, ...props }: React.ThHTMLAttributes<HTMLTableCellElement>) {
    return (
        <th
            className={cn(
                'px-4 py-3 text-left text-xs font-medium text-muted-foreground',
                className,
            )}
            {...props}
        />
    );
}

export function TableCell({ className, ...props }: React.TdHTMLAttributes<HTMLTableCellElement>) {
    return <td className={cn('px-4 py-3 align-middle', className)} {...props} />;
}
