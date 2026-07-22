export function PageHeader({ title, subtitle }: { title: string; subtitle?: string }) {
    return (
        <div>
            <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
            {subtitle && <p className="mt-1 text-sm text-muted-foreground">{subtitle}</p>}
        </div>
    );
}
