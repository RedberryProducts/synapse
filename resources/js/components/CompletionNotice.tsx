import { Info } from 'lucide-react';
import type { ConversationMessage, FinishReason } from '@/types/chat';

const explanations: Partial<Record<FinishReason, string>> = {
    length: 'Output token limit reached.',
    content_filter: 'Stopped by content filtering.',
    cancelled: 'Generation was cancelled.',
    error: 'SDK reported an error completion.',
    unknown: 'Completion reason unavailable.',
};

export function CompletionNotice({ reason, status }: {
    reason: FinishReason | null;
    status: ConversationMessage['status'] | null;
}) {
    const explanation = reason === null ? null : explanations[reason];
    if (status !== 'completed' || !explanation) return null;

    return (
        <p role="status" data-testid="completion-notice" className="mt-3 flex items-start gap-2 text-xs text-muted-foreground">
            <Info aria-hidden="true" className="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <span>{explanation}</span>
        </p>
    );
}
