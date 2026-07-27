import { cn } from '@/lib/utils';

/*
| Basic element — button. Variants map to the `CTA Button` states
| (default / hover / disabled) plus quieter ghost and link treatments.
*/

type Variant = 'primary' | 'ghost' | 'link';
type Size = 'sm' | 'md' | 'icon';

const variants: Record<Variant, string> = {
    primary:
        'bg-primary text-primary-foreground hover:opacity-90 disabled:opacity-40',
    ghost: 'text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-40',
    link: 'text-muted-foreground hover:text-foreground underline-offset-4 disabled:opacity-40',
};

const sizes: Record<Size, string> = {
    sm: 'h-8 px-3 text-xs',
    md: 'h-9 px-4 text-sm',
    icon: 'h-8 w-8',
};

export function Button({
    variant = 'primary',
    size = 'md',
    className,
    ...props
}: React.ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant; size?: Size }) {
    return (
        <button
            type="button"
            className={cn(
                'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition-colors',
                'focus-visible:ring-ring focus-visible:ring-2 focus-visible:outline-none',
                'disabled:pointer-events-none',
                variants[variant],
                sizes[size],
                className,
            )}
            {...props}
        />
    );
}
