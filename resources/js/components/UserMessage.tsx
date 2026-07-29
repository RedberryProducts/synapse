import { MessageAttachments } from './MessageAttachments';
import type { MessageAttachment } from '@/types/chat';

/**
 * A message the developer sent — right-aligned bubble on a muted surface,
 * matching the Figma conversation screen. No avatar: there is only ever one
 * person in a playground.
 */
export function UserMessage({
    content,
    attachments = [],
}: {
    content: string;
    attachments?: MessageAttachment[];
}) {
    return (
        <div className="flex flex-col items-end">
            {content !== '' && (
                <div
                    data-testid="message-user"
                    className="max-w-[80%] rounded-xl bg-muted px-4 py-3 text-sm leading-relaxed whitespace-pre-wrap"
                >
                    {content}
                </div>
            )}

            <MessageAttachments attachments={attachments} />
        </div>
    );
}
