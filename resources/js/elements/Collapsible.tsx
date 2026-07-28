import { useState } from 'react';
import { ChevronDown } from 'lucide-react';
import { cn } from '@/lib/utils';

/*
| Basic element — collapsible section with a chevron trigger, matching the
| expand/collapse behaviour of the Figma `Inline Tool Call cards`.
*/

export function Collapsible({
    label,
    defaultOpen = false,
    className,
    children,
}: {
    label: string;
    defaultOpen?: boolean;
    className?: string;
    children: React.ReactNode;
}) {
    const [open, setOpen] = useState(defaultOpen);

    return (
        <div className={className}>
            <button
                type="button"
                onClick={() => setOpen(!open)}
                aria-expanded={open}
                className="inline-flex items-center gap-1 text-xs text-subtle-foreground transition-colors hover:text-foreground"
            >
                <ChevronDown
                    className={cn('h-3.5 w-3.5 transition-transform', open && 'rotate-180')}
                />
                {label}
            </button>

            {open && <div className="mt-2">{children}</div>}
        </div>
    );
}
