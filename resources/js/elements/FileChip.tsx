import { AudioLines, FileText, Image, X } from 'lucide-react';
import { cn } from '@/lib/utils';

/*
| Basic element — file chip, matching the three Figma `File chip` variants.
|
| The three kinds are the three the SDK models — image, audio, document. There
| is deliberately no fourth: anything else is a document, which is what the
| provider will be asked to make sense of.
*/

export type FileKind = 'image' | 'audio' | 'document';

const icons: Record<FileKind, typeof FileText> = {
    image: Image,
    audio: AudioLines,
    document: FileText,
};

export function FileChip({
    name,
    kind = 'document',
    href,
    onRemove,
    className,
}: {
    name: string;
    kind?: FileKind;
    href?: string;
    onRemove?: () => void;
    className?: string;
}) {
    const Icon = icons[kind];

    const body = (
        <>
            <Icon className="h-3.5 w-3.5 shrink-0 text-subtle-foreground" />
            <span className="truncate">{name}</span>
        </>
    );

    return (
        <span
            data-testid="file-chip"
            className={cn(
                'inline-flex max-w-56 items-center gap-1.5 rounded-md border border-border bg-muted px-2 py-1 text-xs',
                className,
            )}
        >
            {href ? (
                <a
                    href={href}
                    target="_blank"
                    rel="noreferrer"
                    className="flex min-w-0 items-center gap-1.5 hover:underline"
                >
                    {body}
                </a>
            ) : (
                body
            )}

            {onRemove && (
                <button
                    type="button"
                    onClick={onRemove}
                    aria-label={`Remove ${name}`}
                    className="shrink-0 text-subtle-foreground transition-colors hover:text-foreground"
                >
                    <X className="h-3 w-3" />
                </button>
            )}
        </span>
    );
}

/** The SDK's file types, mapped to what the chip should look like. */
export function fileKind(type: string): FileKind {
    if (type.includes('image')) {
        return 'image';
    }

    return type.includes('audio') ? 'audio' : 'document';
}
