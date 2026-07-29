import { FileChip, fileKind } from '@/elements/FileChip';
import type { MessageAttachment } from '@/types/chat';

/**
 * The files a message carried.
 *
 * Images get a thumbnail — the point of attaching one is usually to look at it
 * alongside the answer — and everything else gets a chip that opens the file.
 */
export function MessageAttachments({ attachments }: { attachments: MessageAttachment[] }) {
    if (attachments.length === 0) {
        return null;
    }

    const images = attachments.filter((attachment) => fileKind(attachment.type) === 'image');
    const rest = attachments.filter((attachment) => fileKind(attachment.type) !== 'image');

    return (
        <div data-testid="message-attachments" className="mt-2 flex flex-col items-end gap-2">
            {images.map((attachment) => (
                <a key={attachment.url} href={attachment.url} target="_blank" rel="noreferrer">
                    <img
                        src={attachment.url}
                        alt={attachment.name}
                        className="max-h-48 max-w-full rounded-lg border border-border"
                    />
                </a>
            ))}

            {rest.length > 0 && (
                <div className="flex flex-wrap justify-end gap-2">
                    {rest.map((attachment) => (
                        <FileChip
                            key={attachment.url}
                            name={attachment.name}
                            kind={fileKind(attachment.type)}
                            href={attachment.url}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}
