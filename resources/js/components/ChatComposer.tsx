import { useState } from 'react';
import { ArrowRight } from 'lucide-react';
import { Button } from '@/elements/Button';
import { Textarea } from '@/elements/Textarea';

/**
 * The message composer — Figma `Chat Input`, Default / Empty / Filled.
 *
 * The design also shows an attach button and a model selector; both belong to
 * Epic 5, and neither is rendered here. Shipping them disabled would suggest
 * capability the build does not have yet.
 */
export function ChatComposer({
    onSend,
    disabled = false,
    autoFocus = false,
}: {
    onSend: (message: string) => void;
    disabled?: boolean;
    autoFocus?: boolean;
}) {
    const [message, setMessage] = useState('');

    const submit = () => {
        if (disabled || message.trim() === '') {
            return;
        }

        onSend(message);
        setMessage('');
    };

    return (
        <div
            data-testid="chat-composer"
            className="flex flex-col gap-3 rounded-xl border border-border bg-card px-4 py-3"
        >
            <Textarea
                value={message}
                autoFocus={autoFocus}
                placeholder="Type your message here..."
                aria-label="Message"
                data-testid="composer-input"
                onChange={(event) => setMessage(event.target.value)}
                onKeyDown={(event) => {
                    // Enter sends; Shift+Enter is a newline — the convention
                    // every chat surface a developer already uses follows.
                    if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault();
                        submit();
                    }
                }}
            />

            <div className="flex justify-end">
                <Button
                    size="sm"
                    onClick={submit}
                    disabled={disabled || message.trim() === ''}
                    aria-label="Send message"
                >
                    Send
                    <ArrowRight className="h-3.5 w-3.5" />
                </Button>
            </div>
        </div>
    );
}
