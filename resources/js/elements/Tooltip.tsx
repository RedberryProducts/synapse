import { useState } from 'react';
import { cn } from '@/lib/utils';

/*
| Basic element — tooltip (Figma `Tooltip`).
|
| Hover/focus driven so it works for keyboard users. Swap for Radix Tooltip if
| we ever need collision handling or portalling.
*/

export function Tooltip({
    content,
    children,
    className,
}: {
    content: React.ReactNode;
    children: React.ReactNode;
    className?: string;
}) {
    const [open, setOpen] = useState(false);

    return (
        <span
            className="relative inline-flex"
            onMouseEnter={() => setOpen(true)}
            onMouseLeave={() => setOpen(false)}
            onFocus={() => setOpen(true)}
            onBlur={() => setOpen(false)}
        >
            {children}
            {open && (
                <span
                    role="tooltip"
                    className={cn(
                        'absolute bottom-full left-0 z-50 mb-2 w-max max-w-xs rounded-lg',
                        'border border-border bg-card p-3 text-xs shadow-lg',
                        className,
                    )}
                >
                    {content}
                </span>
            )}
        </span>
    );
}
