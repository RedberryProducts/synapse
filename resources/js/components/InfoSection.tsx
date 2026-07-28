import { cn } from '@/lib/utils';

/** An uppercase-titled section in the Info panel's Config tab. */
export function InfoSection({
    title,
    count,
    children,
    className,
}: {
    title: string;
    count?: number;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <section className={cn('rounded-xl border border-border bg-card p-4', className)}>
            <h3 className="mb-3 text-xs font-medium tracking-wide text-subtle-foreground uppercase">
                {title}
                {count !== undefined && ` (${count})`}
            </h3>
            <div className="flex flex-col gap-2.5">{children}</div>
        </section>
    );
}

/** A label + value row. Missing values render as a muted dash, never blank. */
export function InfoRow({
    label,
    value,
    mono = false,
    accent = false,
}: {
    label: string;
    value: React.ReactNode;
    mono?: boolean;
    accent?: boolean;
}) {
    const empty = value === null || value === undefined || value === '';

    return (
        <div className="flex items-start justify-between gap-3 text-sm">
            <span className="shrink-0 text-muted-foreground">{label}</span>
            {empty ? (
                <span className="text-subtle-foreground">—</span>
            ) : (
                <span
                    className={cn(
                        'min-w-0 rounded-md bg-muted px-2 py-0.5 text-right break-words',
                        mono && 'font-mono text-xs',
                        accent ? 'text-accent' : 'text-foreground',
                    )}
                >
                    {value}
                </span>
            )}
        </div>
    );
}
