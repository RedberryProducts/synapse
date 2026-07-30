import { cn } from '@/lib/utils';

/*
| Basic element — text input, matching the Figma `Input` states (default, empty,
| filled). An optional leading icon covers the search field.
*/

export function Input({
    icon: Icon,
    className,
    ...props
}: React.InputHTMLAttributes<HTMLInputElement> & { icon?: React.ElementType }) {
    return (
        <div className="relative flex items-center">
            {Icon && (
                <Icon className="pointer-events-none absolute left-3 h-4 w-4 text-subtle-foreground" />
            )}

            <input
                className={cn(
                    'h-9 w-full rounded-lg border border-input bg-transparent text-sm outline-none',
                    'placeholder:text-subtle-foreground focus-visible:border-subtle-foreground',
                    Icon ? 'pl-9 pr-3' : 'px-3',
                    className,
                )}
                {...props}
            />
        </div>
    );
}
