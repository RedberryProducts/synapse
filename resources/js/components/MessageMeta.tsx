import type { Usage } from '@/types/chat';

/**
 * Per-response token counts, as the Figma conversation screen shows them:
 * `Prompt: 142   Completion: 89   Total: 231`.
 *
 * The extended breakdown (cache reads/writes, reasoning) is stored on every row
 * and surfaced on hover, since it only matters when you are tuning for it.
 */
export function MessageMeta({ usage, durationMs }: { usage: Usage; durationMs: number | null }) {
    const total = usage.prompt_tokens + usage.completion_tokens;

    const extended = [
        usage.reasoning_tokens > 0 && `Reasoning: ${usage.reasoning_tokens}`,
        usage.cache_read_input_tokens > 0 && `Cache read: ${usage.cache_read_input_tokens}`,
        usage.cache_write_input_tokens > 0 && `Cache write: ${usage.cache_write_input_tokens}`,
    ].filter(Boolean) as string[];

    return (
        <div
            data-testid="message-meta"
            title={extended.length > 0 ? extended.join(' · ') : undefined}
            className="mt-3 flex flex-wrap gap-4 text-xs text-subtle-foreground"
        >
            <span>Prompt: {usage.prompt_tokens}</span>
            <span>Completion: {usage.completion_tokens}</span>
            <span>Total: {total}</span>
            {durationMs !== null && <span>{durationMs}ms</span>}
        </div>
    );
}
