import { Sparkles } from 'lucide-react';
import { Collapsible } from '@/elements/Collapsible';

/**
 * A reasoning model's thinking, above the answer it produced.
 *
 * Figma designs the streaming state only — `✦ Thinking…` in muted text where
 * the answer will appear. The finished state extends it with the existing
 * collapsible language: the thinking is worth keeping, but not worth holding
 * the reader's attention once the answer has arrived.
 */
export function ReasoningPane({ text, streaming }: { text: string; streaming: boolean }) {
    if (streaming) {
        return (
            <p
                data-testid="reasoning"
                className="flex items-center gap-2 text-sm text-subtle-foreground"
            >
                <Sparkles className="h-4 w-4 animate-pulse" />
                Thinking…
            </p>
        );
    }

    if (text === '') {
        return null;
    }

    return (
        <div data-testid="reasoning" className="mb-3">
            <Collapsible label="Thinking">
                <div className="border-l-2 border-border pl-3 text-sm whitespace-pre-wrap text-muted-foreground">
                    {text}
                </div>
            </Collapsible>
        </div>
    );
}
