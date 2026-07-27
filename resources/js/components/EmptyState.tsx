import type { LucideIcon } from 'lucide-react';

/** Reusable empty/placeholder state. */
export function EmptyState({
    icon: Icon,
    title,
    children,
}: {
    icon?: LucideIcon;
    title: string;
    children?: React.ReactNode;
}) {
    return (
        <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border px-6 py-16 text-center">
            {Icon && <Icon className="h-6 w-6 text-subtle-foreground" />}
            <p className="font-medium">{title}</p>
            {children && (
                <div className="max-w-md text-sm text-muted-foreground">{children}</div>
            )}
        </div>
    );
}
