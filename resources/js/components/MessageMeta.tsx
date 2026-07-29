import type { MessageMetaData, Usage } from '@/types/chat';

/**
 * Per-response token counts, as the Figma conversation screen shows them:
 * `Prompt: 142   Completion: 89   Total: 231`.
 *
 * Reasoning tokens join the row when a model produced any — they are billed and
 * invisible, which is exactly the combination worth surfacing. Cache reads and
 * writes stay on hover, since they only matter when you are tuning for them.
 */
export function MessageMeta({
    usage,
    durationMs,
    meta,
    agentModel,
}: {
    usage: Usage;
    durationMs: number | null;
    meta?: MessageMetaData | null;
    /** The agent's configured model, when it differs from what ran. */
    agentModel?: string | null;
}) {
    const total = usage.prompt_tokens + usage.completion_tokens;

    const cache = [
        usage.cache_read_input_tokens > 0 && `Cache read: ${usage.cache_read_input_tokens}`,
        usage.cache_write_input_tokens > 0 && `Cache write: ${usage.cache_write_input_tokens}`,
    ].filter(Boolean) as string[];

    // Naming the model only when it was overridden keeps the row quiet in the
    // common case, while making it impossible to mistake an experiment for the
    // agent's own configuration.
    const overridden = meta?.model && agentModel && meta.model !== agentModel;

    return (
        <div
            data-testid="message-meta"
            title={cache.length > 0 ? cache.join(' · ') : undefined}
            className="mt-3 flex flex-wrap gap-4 text-xs text-subtle-foreground"
        >
            <span>Prompt: {usage.prompt_tokens}</span>
            <span>Completion: {usage.completion_tokens}</span>
            {usage.reasoning_tokens > 0 && <span>Reasoning: {usage.reasoning_tokens}</span>}
            <span>Total: {total}</span>
            {durationMs !== null && <span>{durationMs}ms</span>}
            {overridden && <span>on {meta?.model}</span>}
        </div>
    );
}
