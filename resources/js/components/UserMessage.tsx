/**
 * A message the developer sent — right-aligned bubble on a muted surface,
 * matching the Figma conversation screen. No avatar: there is only ever one
 * person in a playground.
 */
export function UserMessage({ content }: { content: string }) {
    return (
        <div className="flex justify-end">
            <div
                data-testid="message-user"
                className="max-w-[80%] rounded-xl bg-muted px-4 py-3 text-sm leading-relaxed whitespace-pre-wrap"
            >
                {content}
            </div>
        </div>
    );
}
