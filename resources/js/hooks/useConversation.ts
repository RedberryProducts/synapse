import { useCallback, useEffect, useRef, useState } from 'react';
import { deleteConversation, getConversation } from '@/lib/api';
import { streamChat } from '@/lib/stream';
import type {
    AssistantEntry,
    ChatEntry,
    ConversationMessage,
    Usage,
} from '@/types/chat';

/**
 * Owns one playground thread: its entries, the in-flight turn, and the
 * conversation id the URL is synced to.
 *
 * A conversation only exists once the first message is sent — the server
 * announces its id on the stream, which the page then puts in the URL so a
 * refresh lands back on the same thread.
 */
export function useConversation(slug: string | undefined, conversationId: string | null) {
    const [entries, setEntries] = useState<ChatEntry[]>([]);
    const [sending, setSending] = useState(false);
    const [loading, setLoading] = useState(false);
    const abort = useRef<AbortController | null>(null);

    // Load an existing thread when the URL names one (refresh, deep link).
    useEffect(() => {
        if (!conversationId) {
            return;
        }

        const controller = new AbortController();
        setLoading(true);

        getConversation(conversationId, controller.signal)
            .then((conversation) => setEntries(conversation.messages.map(toEntry)))
            .catch(() => {
                // A deleted or unknown id just leaves an empty playground.
            })
            .finally(() => setLoading(false));

        return () => controller.abort();
    }, [conversationId]);

    useEffect(() => () => abort.current?.abort(), []);

    const send = useCallback(
        async (message: string, onConversation: (id: string) => void) => {
            if (!slug || sending || message.trim() === '') {
                return;
            }

            const controller = new AbortController();
            abort.current = controller;

            setSending(true);

            const turnId = `turn-${Date.now()}`;

            setEntries((current) => [
                ...current,
                { kind: 'user', id: `${turnId}-user`, content: message },
                emptyAssistant(turnId),
            ]);

            try {
                await streamChat(
                    slug,
                    { message, conversation_id: conversationId },
                    {
                        onConversation: (id) => onConversation(id),

                        onTextDelta: (blockId, delta) =>
                            setEntries((current) =>
                                updateAssistant(current, turnId, (entry) => ({
                                    ...entry,
                                    blocks: {
                                        ...entry.blocks,
                                        [blockId]: (entry.blocks[blockId] ?? '') + delta,
                                    },
                                    order: entry.order.includes(blockId)
                                        ? entry.order
                                        : [...entry.order, blockId],
                                })),
                            ),

                        onStructured: (data) =>
                            setEntries((current) =>
                                updateAssistant(current, turnId, (entry) => ({
                                    ...entry,
                                    structured: data,
                                })),
                            ),

                        onNotice: (notice) =>
                            setEntries((current) =>
                                insertBeforeAssistant(current, turnId, {
                                    kind: 'notice',
                                    id: `${turnId}-notice-${current.length}`,
                                    message: notice,
                                }),
                            ),

                        onError: (error) =>
                            setEntries((current) =>
                                insertBeforeAssistant(current, turnId, {
                                    kind: 'error',
                                    id: error.messageId || `${turnId}-error`,
                                    message: error.message,
                                    exceptionClass: error.exceptionClass,
                                    stackTrace: error.stackTrace,
                                    recoverable: error.recoverable,
                                }),
                            ),

                        onFinish: (assistantMessageId, usage, durationMs) =>
                            setEntries((current) =>
                                settleAssistant(current, turnId, assistantMessageId, usage, durationMs),
                            ),
                    },
                    controller.signal,
                );
            } catch (error) {
                // Anything the agent itself throws already arrived as an error
                // part; this is the transport failing (network, aborted, 5xx).
                if (!controller.signal.aborted) {
                    setEntries((current) =>
                        insertBeforeAssistant(current, turnId, {
                            kind: 'error',
                            id: `${turnId}-transport`,
                            message: error instanceof Error ? error.message : 'The request failed.',
                            exceptionClass: null,
                            stackTrace: null,
                            recoverable: false,
                        }),
                    );
                }
            } finally {
                setEntries((current) => stopStreaming(current, turnId));
                setSending(false);
                abort.current = null;
            }
        },
        [slug, sending, conversationId],
    );

    const reset = useCallback(() => {
        abort.current?.abort();
        setEntries([]);
    }, []);

    const clear = useCallback(async () => {
        abort.current?.abort();

        if (conversationId) {
            await deleteConversation(conversationId).catch(() => {
                // Already gone is the same outcome as just deleted.
            });
        }

        setEntries([]);
    }, [conversationId]);

    return { entries, sending, loading, send, reset, clear, totals: totalsOf(entries) };
}

/* ── Entry helpers ────────────────────────────────────────────────────────── */

function emptyAssistant(turnId: string): AssistantEntry {
    return {
        kind: 'assistant',
        id: turnId,
        blocks: {},
        order: [],
        streaming: true,
        usage: null,
        durationMs: null,
        meta: null,
        structured: null,
    };
}

function updateAssistant(
    entries: ChatEntry[],
    turnId: string,
    update: (entry: AssistantEntry) => AssistantEntry,
): ChatEntry[] {
    return entries.map((entry) =>
        entry.kind === 'assistant' && entry.id === turnId ? update(entry) : entry,
    );
}

function settleAssistant(
    entries: ChatEntry[],
    turnId: string,
    messageId: string | null,
    usage: Usage | null,
    durationMs: number | null,
): ChatEntry[] {
    return updateAssistant(entries, turnId, (entry) => ({
        ...entry,
        id: messageId ?? entry.id,
        streaming: false,
        usage,
        durationMs,
    }));
}

function stopStreaming(entries: ChatEntry[], turnId: string): ChatEntry[] {
    return entries
        .map((entry) =>
            entry.kind === 'assistant' && entry.id === turnId
                ? { ...entry, streaming: false }
                : entry,
        )
        // A turn that produced nothing at all (a failure before any output)
        // would otherwise leave an empty bubble under the error card.
        .filter((entry) => entry.kind !== 'assistant' || entry.order.length > 0);
}

/** Errors and notices belong above the answer they interrupted. */
function insertBeforeAssistant(
    entries: ChatEntry[],
    turnId: string,
    entry: ChatEntry,
): ChatEntry[] {
    const index = entries.findIndex((item) => item.kind === 'assistant' && item.id === turnId);

    if (index === -1) {
        return [...entries, entry];
    }

    return [...entries.slice(0, index), entry, ...entries.slice(index)];
}

function toEntry(message: ConversationMessage): ChatEntry {
    if (message.role === 'user') {
        return { kind: 'user', id: message.id, content: message.content ?? '' };
    }

    if (message.role === 'error') {
        return {
            kind: 'error',
            id: message.id,
            message: message.content ?? 'The agent failed.',
            exceptionClass: (message.metadata?.exception_class as string | null) ?? null,
            stackTrace: (message.metadata?.stack_trace as string | null) ?? null,
            recoverable: Boolean(message.metadata?.recoverable),
        };
    }

    return {
        kind: 'assistant',
        id: message.id,
        blocks: { text: message.content ?? '' },
        order: ['text'],
        streaming: false,
        usage: message.usage,
        durationMs: message.duration_ms,
        meta: message.meta,
        structured: null,
    };
}

/** Conversation totals, summed from the turns currently on screen. */
function totalsOf(entries: ChatEntry[]) {
    return entries.reduce(
        (totals, entry) => {
            if (entry.kind !== 'assistant' || !entry.usage) {
                return totals;
            }

            return {
                prompt: totals.prompt + entry.usage.prompt_tokens,
                completion: totals.completion + entry.usage.completion_tokens,
            };
        },
        { prompt: 0, completion: 0 },
    );
}
