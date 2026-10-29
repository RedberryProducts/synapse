import { useCallback, useEffect, useRef, useState } from 'react';
import { deleteConversation, getConversation } from '@/lib/api';
import { mergeSources } from '@/lib/sources';
import { streamChat } from '@/lib/stream';
import type {
    CitationSource,
    ApprovalSubmission,
    PendingTurnApproval,
    AssistantEntry,
    ChatEntry,
    Conversation,
    CompletedTurn,
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
    const [approval, setApproval] = useState<PendingTurnApproval | null>(null);
    const [approvalError, setApprovalError] = useState<string | null>(null);
    const [entries, setEntries] = useState<ChatEntry[]>([]);
    const [sending, setSending] = useState(false);
    const [loading, setLoading] = useState(false);
    const abort = useRef<AbortController | null>(null);

    /**
     * The conversation currently on screen.
     *
     * Set both when a thread is fetched and when the server announces the id of
     * one we are streaming, so the load effect below can tell "I need this" from
     * "I already have this".
     */
    const loaded = useRef<string | null>(null);

    /** The provider the current run resolved to, announced by the stream. */
    const provider = useRef<string | null>(null);

    // Switching agents starts over. Without this the previous agent's thread
    // stays on screen under the new agent's name — the worst thing a tool built
    // for attribution can do.
    useEffect(() => {
        abort.current?.abort();
        setEntries([]);
        setApproval(null);
        setApprovalError(null);
        loaded.current = null;
        provider.current = null;
    }, [slug]);

    // Load an existing thread when the URL names one (refresh, deep link).
    useEffect(() => {
        if (!conversationId) {
            setEntries([]);
            setApproval(null);
            setApprovalError(null);
            loaded.current = null;

            return;
        }

        // The id we just streamed into is already rendered, live and complete.
        // Refetching it here would replace the in-flight thread with whatever
        // happens to be stored mid-run — dropping tool cards, structured output
        // and the running token counts.
        if (loaded.current === conversationId) {
            return;
        }

        const controller = new AbortController();
        setLoading(true);

        getConversation(conversationId, controller.signal)
            .then((conversation) => {
                loaded.current = conversation.id;
                setEntries(replay(conversation));
                setApproval(pendingApproval(conversation));
            })
            .catch(() => {
                // A deleted or unknown id just leaves an empty playground.
            })
            .finally(() => setLoading(false));

        return () => controller.abort();
    }, [conversationId]);

    useEffect(() => () => abort.current?.abort(), []);

    const send = useCallback(
        async (
            message: string,
            onConversation: (id: string) => void,
            options: { files?: File[]; model?: string | null; approval?: ApprovalSubmission } = {},
        ) => {
            const files = options.files ?? [];

            if (!slug || loading || sending || abort.current || (!options.approval && (approval || (message.trim() === '' && files.length === 0)))) {
                return;
            }

            const controller = new AbortController();
            abort.current = controller;

            setSending(true);
            setApprovalError(null);

            const turnId = options.approval?.messageId ?? `turn-${Date.now()}`;

            if (options.approval) {
                setApproval((current) => current ? { ...current, state: 'running' } : null);
                setEntries((current) => current.map((entry) =>
                    entry.kind === 'tool' && entry.turnId === turnId && entry.toolCallId && options.approval?.decisions[entry.toolCallId] === 'approve'
                        ? { ...entry, status: 'pending' } : entry,
                ));
            } else {
                setEntries((current) => [
                    ...current,
                    {
                        kind: 'user',
                        id: `${turnId}-user`,
                        content: message,
                        // Shown from the local file until the turn is replayed from
                        // the server, which is when a real URL exists.
                        attachments: files.map((file) => ({
                            type: file.type,
                            name: file.name,
                            url: URL.createObjectURL(file),
                        })),
                    },
                    pendingAssistant(turnId),
                ]);
            }

            try {
                await streamChat(
                    slug,
                    { message, conversation_id: conversationId, model: options.model, files, approval: options.approval },
                    {
                        onConversation: (id) => {
                            // Claim it before the URL changes, so the load
                            // effect knows this thread is already on screen.
                            loaded.current = id;
                            onConversation(id);
                        },

                        // One entry per generation step, so text that arrives
                        // after a tool call renders after its card.
                        onSource: (source) => setEntries((current) => addSources(current, turnId, [source])),

                        onTextDelta: (blockId, delta) =>
                            setEntries((current) => appendText(current, turnId, blockId, delta)),

                        onStructured: (data) =>
                            setEntries((current) =>
                                updateLastAssistant(
                                    openAssistant(current, turnId, `${turnId}:structured`),
                                    turnId,
                                    (entry) => ({
                                        ...entry,
                                        structured: data,
                                    }),
                                ),
                            ),

                        // Tool cards appear the moment the call is announced and
                        // resolve in place, so a slow tool reads as "running"
                        // rather than as nothing happening at all.
                        onToolInput: (toolCallId, name, input) =>
                            setEntries((current) => [
                                ...dropPendingAssistant(current, turnId),
                                {
                                    kind: 'tool',
                                    id: `${turnId}:${toolCallId}`,
                                    turnId, toolCallId,
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
                                updateTool(dropPendingAssistant(current, turnId), toolEntryId(current, turnId, toolCallId), (entry) => ({
                                    ...entry,
                                    status: 'success',
                                    result: output,
                                })),
                            ),

                        onToolError: (toolCallId, errorText) =>
                            setEntries((current) =>
                                updateTool(dropPendingAssistant(current, turnId), toolEntryId(current, turnId, toolCallId), (entry) => ({
                                    ...entry,
                                    status: 'error',
                                    error: errorText,
                                })),
                            ),

                        onToolApproval: (toolCallId) => setEntries((current) =>
                            updateTool(current, toolEntryId(current, turnId, toolCallId), (entry) => ({ ...entry, status: 'paused' }))),

                        onToolDenied: (toolCallId) => setEntries((current) =>
                            updateTool(current, toolEntryId(current, turnId, toolCallId), (entry) => ({ ...entry, status: 'denied' }))),

                        // Held in a ref rather than state: nothing renders it on
                        // its own, and it is read while folding a later event.
                        // Announced before the run's first provider-tool event,
                        // and again on a failover — so the retry's cards name
                        // the provider that actually served them. Cards already
                        // on screen keep naming the attempt they belong to,
                        // which is what a failover notice above them says
                        // happened.
                        onProvider: (name) => {
                            provider.current = name;
                        },

                        onProviderTool: (event) =>
                            setEntries((current) =>
                                applyProviderTool(
                                    dropPendingAssistant(current, turnId),
                                    event,
                                    event.provider ?? provider.current,
                                    turnId,
                                ),
                            ),

                        // Reasoning arrives before the answer, so the entry it
                        // belongs to may not exist yet — open one for it.
                        onReasoningStart: () =>
                            setEntries((current) => openReasoning(current, turnId)),

                        onReasoningDelta: (delta) =>
                            setEntries((current) =>
                                updateLastAssistant(openReasoning(current, turnId), turnId, (entry) => ({
                                    ...entry,
                                    reasoning: entry.reasoning + delta,
                                })),
                            ),

                        onReasoningEnd: () =>
                            setEntries((current) =>
                                updateLastAssistant(current, turnId, (entry) => ({
                                    ...entry,
                                    reasoningStreaming: false,
                                })),
                            ),

                        onNotice: (notice) =>
                            setEntries((current) => [
                                ...dropPendingAssistant(current, turnId),
                                {
                                    kind: 'notice',
                                    id: `${turnId}-notice-${current.length}`,
                                    message: notice,
                                },
                            ]),

                        onError: (error) =>
                            setEntries((current) => [
                                ...failPendingTools(dropPendingAssistant(current, turnId), error.message),
                                {
                                    kind: 'error',
                                    id: error.messageId || `${turnId}-error-${current.length}`,
                                    message: error.message,
                                    exceptionClass: error.exceptionClass,
                                    stackTrace: error.stackTrace,
                                    responseStatus: error.responseStatus,
                                    responseBody: error.responseBody,
                                    recoverable: error.recoverable,
                                },
                            ]),

                        onFinish: (assistantMessageId, usage, durationMs, turn) => {
                            setApproval(turn?.message.approval ? { ...turn.message.approval, messageId: turn.message.id } : null);
                            setEntries((current) =>
                                turn ? completeTurn(current, turnId, turn, Boolean(options.approval)) : settleTurn(current, turnId, assistantMessageId, usage, durationMs),
                            );
                        },
                    },
                    controller.signal,
                );
            } catch (error) {
                // Anything the agent itself throws already arrived as an error
                // part; this is the transport failing (network, aborted, 5xx).
                if (!controller.signal.aborted && options.approval && conversationId) {
                    // The server may already have claimed or completed the run.
                    // Reload its state instead of offering a blind retry.
                    setApprovalError(error instanceof Error ? error.message : 'The approval request failed.');
                    const saved = await getConversation(conversationId, controller.signal).catch(() => null);
                    if (saved) {
                        setEntries(replay(saved));
                        setApproval(pendingApproval(saved));
                    }
                } else if (!controller.signal.aborted) {
                    setEntries((current) => [
                        ...dropPendingAssistant(current, turnId),
                        {
                            kind: 'error',
                            id: `${turnId}-transport`,
                            message: error instanceof Error ? error.message : 'The request failed.',
                            exceptionClass: null,
                            stackTrace: null,
                            responseStatus: null,
                            responseBody: null,
                            recoverable: false,
                        },
                    ]);
                }
            } finally {
                setEntries((current) => stopStreaming(current, turnId));
                setEntries((current) => failPendingTools(current, 'The run ended before this tool returned.'));
                setSending(false);
                abort.current = null;
            }
        },
        [slug, loading, sending, conversationId, approval],
    );

    const reset = useCallback(() => {
        abort.current?.abort();
        setEntries([]);
        setApproval(null);
        setApprovalError(null);
    }, []);

    const clear = useCallback(async () => {
        abort.current?.abort();

        if (conversationId) {
            await deleteConversation(conversationId).catch(() => {
                // Already gone is the same outcome as just deleted.
            });
        }

        setEntries([]);
        setApproval(null);
        setApprovalError(null);
    }, [conversationId]);

    return { entries, approval, approvalError, sending, loading, send, reset, clear, totals: totalsOf(entries) };
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
    const pendingId = pendingAssistantId(turnId);
    const existing = entries.some((entry) => entry.kind === 'assistant' && entry.id === id);

    if (existing) {
        return entries.map((entry) =>
            entry.kind === 'assistant' && entry.id === id
                ? { ...entry, text: entry.text + delta }
                : entry,
        );
    }

    if (entries.some((entry) => entry.kind === 'assistant' && entry.id === pendingId)) {
        return entries.map((entry) =>
            entry.kind === 'assistant' && entry.id === pendingId
                ? { ...entry, id, pending: false, text: delta }
                : entry,
        );
    }

    return addSources([...entries, { ...emptyAssistant(turnId, id), text: delta }], turnId);
}

/**
 * Ensure the turn has an assistant entry to attach reasoning to.
 *
 * A reasoning model thinks before it speaks, so the first thing that arrives
 * for the turn may be a reasoning delta rather than any text.
 */
function openReasoning(entries: ChatEntry[], turnId: string): ChatEntry[] {
    if (lastAssistantId(entries, turnId) !== null) {
        return updateLastAssistant(entries, turnId, (entry) => ({
            ...entry,
            pending: false,
            reasoningStreaming: true,
        }));
    }

    return openAssistant(entries, turnId, `${turnId}:reasoning`, { reasoningStreaming: true });
}

function openAssistant(
    entries: ChatEntry[],
    turnId: string,
    id: string,
    overrides: Partial<AssistantEntry> = {},
): ChatEntry[] {
    const pendingId = pendingAssistantId(turnId);

    return addSources(entries.some((entry) => entry.kind === 'assistant' && entry.id === pendingId)
        ? entries.map((entry) =>
              entry.kind === 'assistant' && entry.id === pendingId
                  ? { ...entry, id, pending: false, ...overrides }
                  : entry,
          )
        : [...entries, { ...emptyAssistant(turnId, id), ...overrides }], turnId);
}

function addSources(entries: ChatEntry[], turnId: string, incoming: CitationSource[] = []): ChatEntry[] {
    const sources = mergeSources(...entries.flatMap((entry) =>
        entry.kind === 'assistant' && entry.turnId === turnId ? [entry.sources] : []), incoming);
    if (sources.length === 0) return entries;
    const sourceId = `${turnId}:sources`;
    const target = lastAssistantId(entries, turnId) ?? sourceId;
    const current = entries.some((entry) => entry.id === target)
        ? entries : [...entries, emptyAssistant(turnId, target)];
    return current.flatMap((entry): ChatEntry[] => {
        if (entry.kind !== 'assistant' || entry.turnId !== turnId) return [entry];
        const updated = {
            ...entry,
            // A source footer must not remain a reusable loading placeholder across tool events.
            id: entry.id === pendingAssistantId(turnId) ? sourceId : entry.id,
            pending: false,
            sources: entry.id === target ? sources : [],
        };
        if (updated.id === sourceId && updated.sources.length === 0 && updated.text === ''
            && updated.reasoning === '' && !updated.reasoningStreaming
            && updated.structured === null && updated.usage === null) return [];
        return [updated];
    });
}

function emptyAssistant(turnId: string, id: string): AssistantEntry {
    return {
        kind: 'assistant',
        finishReason: null,
        status: null,
        sources: [],
        id,
        turnId,
        text: '',
        pending: false,
        reasoning: '',
        reasoningStreaming: false,
        streaming: true,
        usage: null,
        durationMs: null,
        meta: null,
        structured: null,
    };
}

function pendingAssistant(turnId: string): AssistantEntry {
    return {
        ...emptyAssistant(turnId, pendingAssistantId(turnId)),
        pending: true,
    };
}

function pendingAssistantId(turnId: string): string {
    return `${turnId}:pending`;
}

function dropPendingAssistant(entries: ChatEntry[], turnId: string): ChatEntry[] {
    return entries.filter(
        (entry) => !(entry.kind === 'assistant' && entry.turnId === turnId && entry.pending),
    );
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
            ? {
                  ...entry,
                  id: messageId ?? entry.id,
                  pending: false,
                  streaming: false,
                  reasoningStreaming: false,
                  usage,
                  durationMs,
              }
            : { ...entry, pending: false, streaming: false, reasoningStreaming: false };
    });
}

function stopStreaming(entries: ChatEntry[], turnId: string): ChatEntry[] {
    return entries
        .map((entry) =>
            entry.kind === 'assistant' && entry.turnId === turnId
                ? { ...entry, pending: false, streaming: false, reasoningStreaming: false }
                : entry,
        )
        // Remove empty placeholders, but retain billable tool-only turns.
        .filter(
            (entry) =>
                entry.kind !== 'assistant' ||
                entry.text !== '' ||
                entry.reasoning !== '' ||
                entry.structured !== null ||
                entry.usage !== null ||
                entry.sources.length > 0 ||
                entry.finishReason !== null,
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

/** Mark unfinished cards as failed when the transport or invocation stops. */
function failPendingTools(entries: ChatEntry[], message: string): ChatEntry[] {
    return entries.map((entry) =>
        entry.kind === 'tool' && entry.status === 'pending'
            ? { ...entry, status: 'error' as const, error: entry.error ?? message }
            : entry,
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
function applyProviderTool(
    entries: ChatEntry[],
    event: ProviderToolPart,
    provider: string | null,
    turnId: string,
): ChatEntry[] {
    const id = `${turnId}:${provider}:${event.item_id}`;
    const status = normalizeProviderStatus(event.status);
    const known = entries.some((entry) => entry.kind === 'tool' && entry.id === id);

    if (known) {
        return updateTool(entries, id, (entry) => ({
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
            id,
            type: 'provider_tool',
            name: typeof event.data?.name === 'string' ? event.data.name : event.type,
            provider,
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

function replay(conversation: Conversation): ChatEntry[] {
    const tools = new Map(conversation.tool_invocations.map((tool) => [tool.id, tool]));
    return conversation.messages.flatMap((message) => replayMessage(message, tools));
}

function replayMessage(message: ConversationMessage, tools: Map<string, ConversationToolInvocation>): ChatEntry[] {
    if (message.role !== 'assistant') return [toEntry(message)];
    const entries: ChatEntry[] = [];
    for (const part of message.parts) {
        const id = `${message.id}:${part.id}`;
        if (part.type === 'tool') {
            const tool = tools.get(part.tool_invocation_id);
            if (tool) entries.push({ ...toToolEntry(tool), turnId: message.id });
        } else if (part.type === 'notice') {
            entries.push({ kind: 'notice', id, message: part.message });
        } else {
            const last = entries.at(-1);
            if (part.type === 'text' && last?.kind === 'assistant' && last.text === '') {
                last.text = message.meta?.structured ? '' : part.text;
            } else {
                entries.push({
                    ...emptyAssistant(message.id, id), streaming: false,
                    text: part.type === 'text' && !message.meta?.structured ? part.text : '',
                    reasoning: part.type === 'reasoning' ? part.text : '',
                });
            }
        }
    }
    // A tool-only turn can still have billable usage and needs one metadata row.
    if (!entries.some((entry) => entry.kind === 'assistant') && (message.usage || message.meta?.structured || message.content || message.sources?.length || message.finish_reason)) {
        entries.push({ ...emptyAssistant(message.id, message.id), text: message.meta?.structured ? '' : (message.content ?? ''), streaming: false });
    }
    return updateLastAssistant(entries, message.id, (entry) => ({
        ...entry, sources: mergeSources(message.sources ?? []), usage: message.usage, durationMs: message.duration_ms,
        finishReason: message.finish_reason ?? null, status: message.status,
        meta: message.meta, structured: message.meta?.structured ?? null,
    }));
}

function completeTurn(entries: ChatEntry[], turnId: string, turn: CompletedTurn, resuming: boolean): ChatEntry[] {
    const user = resuming
        ? entries.map((entry) => entry.kind).lastIndexOf('user')
        : entries.findIndex((entry) => entry.id === `${turnId}-user`);
    if (user < 0) return entries;
    const tools = new Map(turn.tool_invocations.map((tool) => [tool.id, tool]));
    return [
        ...entries.slice(0, user + 1),
        ...replayMessage(turn.message, tools),
        ...entries.slice(user + 1).filter((entry) => entry.kind === 'error'),
    ];
}

function toToolEntry(invocation: ConversationToolInvocation): ToolEntry {
    return {
        kind: 'tool', toolCallId: invocation.tool_call_id, id: invocation.id, type: invocation.type, name: invocation.name,
        provider: invocation.provider, arguments: invocation.arguments,
        result: invocation.result, status: invocation.status, providerStatus: invocation.provider_status,
        error: invocation.error, durationMs: invocation.duration_ms,
    };
}

function toEntry(message: ConversationMessage): ChatEntry {
    if (message.role === 'user') {
        return {
            kind: 'user',
            id: message.id,
            content: message.content ?? '',
            attachments: message.attachments ?? [],
        };
    }

    if (message.role === 'error') {
        return {
            kind: 'error',
            id: message.id,
            message: message.content ?? 'The agent failed.',
            exceptionClass: (message.metadata?.exception_class as string | null) ?? null,
            stackTrace: (message.metadata?.stack_trace as string | null) ?? null,
            responseStatus: (message.metadata?.response_status as number | null) ?? null,
            responseBody: (message.metadata?.response_body as string | null) ?? null,
            recoverable: Boolean(message.metadata?.recoverable),
        };
    }

    return {
        kind: 'assistant',
        finishReason: message.finish_reason ?? null,
        status: message.status,
        sources: mergeSources(message.sources ?? []),
        id: message.id,
        turnId: message.id,
        // A structured agent's text *is* its JSON; the card renders the parsed
        // payload instead, so the raw copy would just be noise.
        text: message.meta?.structured ? '' : (message.content ?? ''),
        // Both were kept on `meta` precisely so a replay matches what streamed.
        reasoning: message.meta?.reasoning ?? '',
        pending: false,
        reasoningStreaming: false,
        streaming: false,
        usage: message.usage,
        durationMs: message.duration_ms,
        meta: message.meta,
        structured: message.meta?.structured ?? null,
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
                prompt: totals.prompt + entry.usage.input_tokens,
                completion: totals.completion + entry.usage.output_tokens,
            };
        },
        { prompt: 0, completion: 0 },
    );
}

function pendingApproval(conversation: Conversation): PendingTurnApproval | null {
    const message = conversation.messages.find((message) => Boolean(message.approval));
    return message?.approval ? { ...message.approval, messageId: message.id } : null;
}

function toolEntryId(entries: ChatEntry[], turnId: string, callId: string): string {
    return entries.find((entry) => entry.kind === 'tool' && entry.turnId === turnId && entry.toolCallId === callId)?.id ?? `${turnId}:${callId}`;
}
