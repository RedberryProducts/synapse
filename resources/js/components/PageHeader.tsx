export function PageHeader({
    title,
    count,
    subtitle,
}: {
    title: string;
    count?: number;
    subtitle?: string;
}) {
    return (
        <div>
            <h1 className="text-2xl font-semibold tracking-tight">
                {title}
                {count !== undefined && (
                    <span className="ml-2 text-subtle-foreground">({count})</span>
                )}
            </h1>
            {subtitle && <p className="mt-1 text-sm text-muted-foreground">{subtitle}</p>}
        </div>
    );
}
