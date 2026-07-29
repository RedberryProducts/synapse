import { useCallback, useEffect, useRef, useState } from 'react';
import { deleteConversation, getConversation } from '@/lib/api';
import { streamChat } from '@/lib/stream';
import type {
    AssistantEntry,
    ChatEntry,
    Conversation,
    ConversationMessage,
    ConversationToolInvocation,
    ProviderToolPart,
    ToolEntry,
    ToolStatus,
    Usage,
} from '@/types/chat';

/**
 * Owns one playground thread: its entries, the in-flight turn, and the
 * conversation id the URL is synced to.
 *
 * Entries are **appended in arrival order and never reordered**. A multi-step
 * run interleaves narration and tool calls — text, a call, more text — and that
 * sequence is the thing a developer is trying to understand. Grouping all the
 * cards after the text would be tidier and would misrepresent what happened.
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
            .then((conversation) => setEntries(replay(conversation)))
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
            ]);

            try {
                await streamChat(
                    slug,
                    { message, conversation_id: conversationId },
                    {
                        onConversation: (id) => onConversation(id),

                        // One entry per generation step, so text that arrives
                        // after a tool call renders after its card.
                        onTextDelta: (blockId, delta) =>
                            setEntries((current) => appendText(current, turnId, blockId, delta)),

                        onStructured: (data) =>
                            setEntries((current) =>
                                updateLastAssistant(current, turnId, (entry) => ({
                                    ...entry,
                                    structured: data,
                                })),
                            ),

                        // Tool cards appear the moment the call is announced and
                        // resolve in place, so a slow tool reads as "running"
                        // rather than as nothing happening at all.
                        onToolInput: (toolCallId, name, input) =>
                            setEntries((current) => [
                                ...current,
                                {
                                    kind: 'tool',
                                    id: toolCallId,
                                    type: 'tool',
                                    name,
                                    provider: null,
                                    arguments: input,
                                    result: null,
                                    status: 'pending',
                                    providerStatus: null,
                                    error: null,
                                    durationMs: null,
                                },
                            ]),

                        onToolOutput: (toolCallId, output) =>
                            setEntries((current) =>
                                updateTool(current, toolCallId, (entry) => ({
                                    ...entry,
                                    status: 'success',
                                    result: output,
                                })),
                            ),

                        onToolError: (toolCallId, errorText) =>
                            setEntries((current) =>
                                updateTool(current, toolCallId, (entry) => ({
                                    ...entry,
                                    status: 'error',
                                    error: errorText,
                                })),
                            ),

                        onProviderTool: (event) =>
                            setEntries((current) => applyProviderTool(current, event)),

                        onNotice: (notice) =>
                            setEntries((current) => [
                                ...current,
                                {
                                    kind: 'notice',
                                    id: `${turnId}-notice-${current.length}`,
                                    message: notice,
                                },
                            ]),

                        onError: (error) =>
                            setEntries((current) => [
                                ...current,
                                {
                                    kind: 'error',
                                    id: error.messageId || `${turnId}-error-${current.length}`,
                                    message: error.message,
                                    exceptionClass: error.exceptionClass,
                                    stackTrace: error.stackTrace,
                                    recoverable: error.recoverable,
                                },
                            ]),

                        onFinish: (assistantMessageId, usage, durationMs) =>
                            setEntries((current) =>
                                settleTurn(current, turnId, assistantMessageId, usage, durationMs),
                            ),
                    },
                    controller.signal,
                );
            } catch (error) {
                // Anything the agent itself throws already arrived as an error
                // part; this is the transport failing (network, aborted, 5xx).
                if (!controller.signal.aborted) {
                    setEntries((current) => [
                        ...current,
                        {
                            kind: 'error',
                            id: `${turnId}-transport`,
                            message: error instanceof Error ? error.message : 'The request failed.',
                            exceptionClass: null,
                            stackTrace: null,
                            recoverable: false,
                        },
                    ]);
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

/* ── Assistant text ───────────────────────────────────────────────────────── */

/**
 * Append a text delta, opening a new assistant entry when the step changes.
 *
 * The SDK gives each generation step its own message id, so a new id means the
 * model started talking again after doing something else.
 */
function appendText(
    entries: ChatEntry[],
    turnId: string,
    blockId: string,
    delta: string,
): ChatEntry[] {
    const id = `${turnId}:${blockId}`;
    const existing = entries.some((entry) => entry.kind === 'assistant' && entry.id === id);

    if (existing) {
        return entries.map((entry) =>
            entry.kind === 'assistant' && entry.id === id
                ? { ...entry, text: entry.text + delta }
                : entry,
        );
    }

    return [
        ...entries,
        {
            kind: 'assistant',
            id,
            turnId,
            text: delta,
            streaming: true,
            usage: null,
            durationMs: null,
            meta: null,
            structured: null,
        },
    ];
}

function updateLastAssistant(
    entries: ChatEntry[],
    turnId: string,
    update: (entry: AssistantEntry) => AssistantEntry,
): ChatEntry[] {
    const target = lastAssistantId(entries, turnId);

    if (target === null) {
        return entries;
    }

    return entries.map((entry) =>
        entry.kind === 'assistant' && entry.id === target ? update(entry) : entry,
    );
}

function lastAssistantId(entries: ChatEntry[], turnId: string): string | null {
    for (let index = entries.length - 1; index >= 0; index--) {
        const entry = entries[index];

        if (entry.kind === 'assistant' && entry.turnId === turnId) {
            return entry.id;
        }
    }

    return null;
}

/**
 * Close out the turn.
 *
 * Usage covers the whole run rather than any one step, so it lands on the last
 * assistant entry — the one the counts sit under in the design.
 */
function settleTurn(
    entries: ChatEntry[],
    turnId: string,
    messageId: string | null,
    usage: Usage | null,
    durationMs: number | null,
): ChatEntry[] {
    const target = lastAssistantId(entries, turnId);

    return entries.map((entry) => {
        if (entry.kind !== 'assistant' || entry.turnId !== turnId) {
            return entry;
        }

        return entry.id === target
            ? { ...entry, id: messageId ?? entry.id, streaming: false, usage, durationMs }
            : { ...entry, streaming: false };
    });
}

function stopStreaming(entries: ChatEntry[], turnId: string): ChatEntry[] {
    return entries.map((entry) =>
        entry.kind === 'assistant' && entry.turnId === turnId
            ? { ...entry, streaming: false }
            : entry,
    );
}

/* ── Tool cards ───────────────────────────────────────────────────────────── */

function updateTool(
    entries: ChatEntry[],
    toolCallId: string,
    update: (entry: ToolEntry) => ToolEntry,
): ChatEntry[] {
    return entries.map((entry) =>
        entry.kind === 'tool' && entry.id === toolCallId ? update(entry) : entry,
    );
}

/**
 * Fold a provider-tool event into its card, creating it on first sight.
 *
 * These never announce themselves the way a user tool does — there is no
 * "input available" part — so the first event of any status opens the card.
 * The status mapping mirrors the server's: anything unrecognised stays pending
 * rather than claiming a result that never came.
 */
function applyProviderTool(entries: ChatEntry[], event: ProviderToolPart): ChatEntry[] {
    const status = normalizeProviderStatus(event.status);
    const known = entries.some((entry) => entry.kind === 'tool' && entry.id === event.item_id);

    if (known) {
        return updateTool(entries, event.item_id, (entry) => ({
            ...entry,
            status,
            providerStatus: event.status,
            result: status === 'pending' ? entry.result : event.data,
        }));
    }

    return [
        ...entries,
        {
            kind: 'tool',
            id: event.item_id,
            type: 'provider_tool',
            name: typeof event.data?.name === 'string' ? event.data.name : event.type,
            provider: null,
            arguments: event.data,
            result: status === 'pending' ? null : event.data,
            status,
            providerStatus: event.status,
            error: null,
            durationMs: null,
        },
    ];
}

const PROVIDER_SUCCESS = ['completed', 'complete', 'result_received', 'done', 'succeeded'];
const PROVIDER_FAILED = ['failed', 'error', 'errored', 'incomplete', 'cancelled', 'canceled'];

function normalizeProviderStatus(status: string): ToolStatus {
    const value = status.toLowerCase();

    if (PROVIDER_SUCCESS.includes(value)) {
        return 'success';
    }

    if (PROVIDER_FAILED.includes(value)) {
        return 'error';
    }

    return 'pending';
}

/* ── Replay ───────────────────────────────────────────────────────────────── */

/**
 * Rebuild a stored conversation as thread entries.
 *
 * Tool cards precede the assistant message they produced, which is the order
 * they happened in: the model called the tool, then answered with the result.
 * Rows with no `message_id` — a run that died before its turn landed — are
 * appended rather than dropped, because a call that happened is worth seeing
 * even when the turn it belonged to never finished.
 */
function replay(conversation: Conversation): ChatEntry[] {
    const byMessage = new Map<string, ToolEntry[]>();
    const orphans: ToolEntry[] = [];

    for (const invocation of conversation.tool_invocations) {
        const entry = toToolEntry(invocation, conversation.messages);

        if (invocation.message_id === null) {
            orphans.push(entry);

            continue;
        }

        byMessage.set(invocation.message_id, [
            ...(byMessage.get(invocation.message_id) ?? []),
            entry,
        ]);
    }

    const entries = conversation.messages.flatMap((message) => [
        ...(byMessage.get(message.id) ?? []),
        toEntry(message),
    ]);

    return [...entries, ...orphans];
}

function toToolEntry(
    invocation: ConversationToolInvocation,
    messages: ConversationMessage[],
): ToolEntry {
    return {
        kind: 'tool',
        id: invocation.id,
        type: invocation.type,
        name: invocation.name,
        // The provider isn't stored on the row — it's a property of the turn.
        provider:
            invocation.type === 'provider_tool'
                ? (messages.find((message) => message.id === invocation.message_id)?.meta
                      ?.provider ?? null)
                : null,
        arguments: invocation.arguments,
        result: invocation.result,
        status: invocation.status,
        providerStatus: invocation.provider_status,
        error: invocation.error,
        durationMs: invocation.duration_ms,
    };
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
        turnId: message.id,
        text: message.content ?? '',
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
