import { createContext, useContext, useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

/*
| Basic element — dropdown menu.
|
| Styled from Figma `Dropdown item` (324:25180) and `More` (329:3275).
| Swap the internals for Radix `@radix-ui/react-dropdown-menu` when we need
| submenus/typeahead; the API below is intentionally the same shape.
*/

type DropdownContextValue = {
    open: boolean;
    setOpen: (open: boolean) => void;
};

const DropdownContext = createContext<DropdownContextValue | null>(null);

function useDropdown(): DropdownContextValue {
    const context = useContext(DropdownContext);

    if (!context) {
        throw new Error('Dropdown parts must be used inside <DropdownMenu>.');
    }

    return context;
}

export function DropdownMenu({ children }: { children: React.ReactNode }) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onPointerDown = (event: MouseEvent) => {
            if (!ref.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    return (
        <DropdownContext.Provider value={{ open, setOpen }}>
            <div ref={ref} className="relative">
                {children}
            </div>
        </DropdownContext.Provider>
    );
}

export function DropdownMenuTrigger({
    children,
    className,
    ...props
}: React.ButtonHTMLAttributes<HTMLButtonElement>) {
    const { open, setOpen } = useDropdown();

    return (
        <button
            type="button"
            aria-haspopup="menu"
            aria-expanded={open}
            onClick={() => setOpen(!open)}
            className={className}
            {...props}
        >
            {children}
        </button>
    );
}

export function DropdownMenuContent({
    children,
    align = 'start',
    side = 'top',
    className,
}: {
    children: React.ReactNode;
    align?: 'start' | 'end';
    side?: 'top' | 'bottom';
    className?: string;
}) {
    const { open } = useDropdown();

    if (!open) {
        return null;
    }

    return (
        <div
            role="menu"
            className={cn(
                'absolute z-50 min-w-40 rounded-lg border border-border bg-card p-1 shadow-lg',
                side === 'top' ? 'bottom-full mb-1' : 'top-full mt-1',
                align === 'end' ? 'right-0' : 'left-0',
                className,
            )}
        >
            {children}
        </div>
    );
}

export function DropdownMenuItem({
    children,
    onSelect,
    active = false,
    className,
}: {
    children: React.ReactNode;
    onSelect?: () => void;
    active?: boolean;
    className?: string;
}) {
    const { setOpen } = useDropdown();

    return (
        <button
            type="button"
            role="menuitem"
            onClick={() => {
                onSelect?.();
                setOpen(false);
            }}
            className={cn(
                'flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm transition-colors',
                active
                    ? 'bg-muted text-foreground'
                    : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                className,
            )}
        >
            {children}
        </button>
    );
}
