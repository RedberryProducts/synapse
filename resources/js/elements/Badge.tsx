import { cn } from '@/lib/utils';

/*
| Basic element — badge. `chip` matches the Figma `Tool Tag`; `pill` is the
| lighter treatment used for the model tier.
*/

type Variant = 'chip' | 'pill';

const variants: Record<Variant, string> = {
    chip: 'border border-border bg-muted text-muted-foreground',
    pill: 'border border-border text-subtle-foreground',
};

export function Badge({
    variant = 'chip',
    className,
    ...props
}: React.HTMLAttributes<HTMLSpanElement> & { variant?: Variant }) {
    return (
        <span
            className={cn(
                'inline-flex max-w-full items-center gap-1.5 rounded-md px-2 py-1 text-xs whitespace-nowrap',
                variants[variant],
                className,
            )}
            {...props}
        />
    );
}
