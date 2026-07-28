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

    // Height has to be reset before measuring, or scrollHeight only ever grows.
    useEffect(() => {
        const element = ref.current;

        if (!element) {
            return;
        }

        element.style.height = 'auto';
        element.style.height = `${Math.min(element.scrollHeight, maxHeight)}px`;
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
