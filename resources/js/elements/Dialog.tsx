import { useEffect, useRef } from 'react';

/*
| Basic element — modal dialog, matching the Figma `Modal/Rename` and
| `Modal/Delete` layout (title, description, body, actions).
|
| Built on the native `<dialog>`: focus trapping, `Escape`, and the backdrop come
| from the platform rather than from us re-implementing them.
*/

export function Dialog({
    open,
    onClose,
    title,
    description,
    children,
    footer,
    testId,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description?: string;
    children?: React.ReactNode;
    footer: React.ReactNode;
    testId?: string;
}) {
    const ref = useRef<HTMLDialogElement>(null);

    useEffect(() => {
        const element = ref.current;

        if (!element) {
            return;
        }

        if (open && !element.open) {
            element.showModal();
        }

        if (!open && element.open) {
            element.close();
        }
    }, [open]);

    if (!open) {
        return null;
    }

    return (
        <dialog
            ref={ref}
            data-testid={testId}
            // `close` also fires for Escape and the platform's own dismissals,
            // so the caller's state stays in step however the dialog was shut.
            onClose={onClose}
            onClick={(event) => {
                if (event.target === ref.current) {
                    onClose();
                }
            }}
            className="w-[28rem] max-w-[calc(100vw-2rem)] rounded-xl border border-border bg-card p-6 text-foreground backdrop:bg-black/60"
        >
            <h2 className="text-lg font-semibold">{title}</h2>

            {description && <p className="mt-1 text-sm text-muted-foreground">{description}</p>}

            {children && <div className="mt-5">{children}</div>}

            <div className="mt-6 flex gap-3">{footer}</div>
        </dialog>
    );
}
