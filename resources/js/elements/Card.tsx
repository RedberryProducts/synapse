import { cn } from '@/lib/utils';

/*
| Basic element — card surface. Border + surface, no shadow, matching the
| Figma `Card` component.
*/

export function Card({
    interactive = false,
    className,
    ...props
}: React.HTMLAttributes<HTMLDivElement> & { interactive?: boolean }) {
    return (
        <div
            className={cn(
                'flex flex-col rounded-xl border border-border bg-card',
                interactive && 'cursor-pointer transition-colors hover:border-subtle-foreground',
                className,
            )}
            {...props}
        />
    );
}

export function CardBody({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('flex flex-1 flex-col gap-3 p-5', className)} {...props} />;
}

export function CardFooter({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            className={cn(
                'flex items-center justify-between gap-3 border-t border-border px-5 py-3',
                className,
            )}
            {...props}
        />
    );
}
