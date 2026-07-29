import { useEffect, useRef } from 'react';
import { cn } from '@/lib/utils';

/*
| Basic element — auto-growing textarea, matching the Figma `Chat Input` field
| across its Default / Empty / Filled states: it grows with the content up to
| `maxHeight`, then scrolls.
*/

export function Textarea({
    value,
    maxHeight = 200,
    className,
    ...props
}: React.TextareaHTMLAttributes<HTMLTextAreaElement> & { value: string; maxHeight?: number }) {
    const ref = useRef<HTMLTextAreaElement>(null);

    useEffect(() => {
        const element = ref.current;

        if (!element) {
            return;
        }

        // An empty field is always one row. Measuring it is not just wasteful,
        // it is where this went wrong: on first mount the measurement came back
        // at the cap, leaving an empty composer 200px tall on the very first
        // screen anyone sees.
        if (value === '') {
            element.style.height = 'auto';

            return;
        }

        // Measure on the next frame so layout has settled, and reset the height
        // first or scrollHeight can only ever grow.
        const frame = requestAnimationFrame(() => {
            element.style.height = 'auto';
            element.style.height = `${Math.min(element.scrollHeight, maxHeight)}px`;
        });

        return () => cancelAnimationFrame(frame);
    }, [value, maxHeight]);

    return (
        <textarea
            ref={ref}
            value={value}
            rows={1}
            className={cn(
                'w-full resize-none bg-transparent text-sm leading-relaxed outline-none',
                'placeholder:text-subtle-foreground',
                className,
            )}
            {...props}
        />
    );
}
