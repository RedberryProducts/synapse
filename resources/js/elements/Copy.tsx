import { useState } from 'react';
import { Check, Copy as CopyIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

/** Basic element — copy-to-clipboard button (Figma `Copy`: default / hover / copied). */
export function Copy({ value, className }: { value: string; className?: string }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            // Clipboard access can be denied; failing silently is fine here.
        }
    };

    return (
        <button
            type="button"
            onClick={copy}
            title={copied ? 'Copied' : 'Copy'}
            aria-label={copied ? 'Copied' : 'Copy'}
            className={cn(
                'inline-flex h-7 w-7 items-center justify-center rounded-md transition-colors',
                copied
                    ? 'text-success'
                    : 'text-subtle-foreground hover:bg-muted hover:text-foreground',
                className,
            )}
        >
            {copied ? <Check className="h-3.5 w-3.5" /> : <CopyIcon className="h-3.5 w-3.5" />}
        </button>
    );
}
