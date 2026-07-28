/**
 * The chat wire format and the shapes the thread is built from.
 *
 * Parts use the Vercel AI SDK UI message protocol — the same names the Laravel
 * AI SDK's own `toVercelProtocolArray()` produces — plus the `data-synapse-*`
 * and `data-provider-tool` parts Synapse adds for what that serialization drops.
 */

export interface Usage {
    prompt_tokens: number;
    completion_tokens: number;
    cache_write_input_tokens: number;
    cache_read_input_tokens: number;
    reasoning_tokens: number;
}

export interface MessageMetaData {
    provider: string | null;
    model: string | null;
}

/* ── Stream parts ─────────────────────────────────────────────────────────── */

export interface StreamPart {
    type: string;
    /** Text parts key their deltas by message id — one per generation step. */
    id?: string;
    messageId?: string;
    delta?: string;
    errorText?: string;
    toolCallId?: string;
    toolName?: string;
    input?: unknown;
    output?: unknown;
    data?: Record<string, unknown>;
}

export interface StreamHandlers {
    onConversation?: (conversationId: string, userMessageId: string) => void;
    onTextDelta?: (id: string, delta: string) => void;
    onError?: (error: ChatError) => void;
    onNotice?: (message: string) => void;
    onStructured?: (data: Record<string, unknown>) => void;
    onFinish?: (assistantMessageId: string | null, usage: Usage | null, durationMs: number | null) => void;
    /** Anything Epics 4–5 add; ignored by the MVP thread. */
    onPart?: (part: StreamPart) => void;
}

export interface ChatError {
    messageId: string;
    message: string;
    exceptionClass: string | null;
    stackTrace: string | null;
    recoverable: boolean;
}

/* ── Thread entries ───────────────────────────────────────────────────────── */

export interface UserEntry {
    kind: 'user';
    id: string;
    content: string;
}

export interface AssistantEntry {
    kind: 'assistant';
    id: string;
    /** Text per generation step, keyed by the part id, joined for display. */
    blocks: Record<string, string>;
    order: string[];
    streaming: boolean;
    usage: Usage | null;
    durationMs: number | null;
    meta: MessageMetaData | null;
    structured: Record<string, unknown> | null;
}

export interface ErrorEntry {
    kind: 'error';
    id: string;
    message: string;
    exceptionClass: string | null;
    stackTrace: string | null;
    recoverable: boolean;
}

export interface NoticeEntry {
    kind: 'notice';
    id: string;
    message: string;
}

export type ChatEntry = UserEntry | AssistantEntry | ErrorEntry | NoticeEntry;

/* ── Replay payload (GET /api/conversations/{id}) ──────────────────────────── */

export interface ConversationMessage {
    id: string;
    role: 'user' | 'assistant' | 'error';
    content: string | null;
    usage: Usage | null;
    duration_ms: number | null;
    meta: MessageMetaData | null;
    metadata: Record<string, unknown> | null;
    created_at: string | null;
}

export interface Conversation {
    id: string;
    agent_class: string;
    agent_slug: string;
    title: string;
    created_at: string | null;
    totals: {
        prompt_tokens: number;
        completion_tokens: number;
        total_tokens: number;
    };
    messages: ConversationMessage[];
}
