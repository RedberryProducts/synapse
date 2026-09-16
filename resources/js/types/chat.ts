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
    /** Kept because the SDK's response drops both — see AgentInvoker. */
    reasoning?: string | null;
    structured?: Record<string, unknown> | null;
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
    /** The provider this run actually resolved to; re-sent on a failover. */
    onProvider?: (provider: string) => void;
    onStructured?: (data: Record<string, unknown>) => void;
    onReasoningStart?: () => void;
    onReasoningDelta?: (delta: string) => void;
    onReasoningEnd?: () => void;
    onToolInput?: (toolCallId: string, name: string, input: unknown) => void;
    onToolOutput?: (toolCallId: string, output: unknown) => void;
    onToolError?: (toolCallId: string, errorText: string) => void;
    onProviderTool?: (event: ProviderToolPart) => void;
    onFinish?: (assistantMessageId: string | null, usage: Usage | null, durationMs: number | null) => void;
    /** Anything a later epic adds; ignored rather than treated as an error. */
    onPart?: (part: StreamPart) => void;
}

/**
 * The raw provider-tool event, forwarded whole because the SDK's Vercel
 * serializer drops it entirely.
 */
export interface ProviderToolPart {
    item_id: string;
    type: string;
    status: string;
    data: Record<string, unknown>;
}

export interface ChatError {
    messageId: string;
    message: string;
    exceptionClass: string | null;
    stackTrace: string | null;
    /** HTTP status and body when the failure was a provider request. */
    responseStatus: number | null;
    responseBody: string | null;
    recoverable: boolean;
}

/* ── Thread entries ───────────────────────────────────────────────────────── */

/** A file a message carried, as the browser can reach it. */
export interface MessageAttachment {
    /** The SDK's own type: `stored-image` / `stored-audio` / `stored-document`. */
    type: string;
    name: string;
    url: string;
}

export interface UserEntry {
    kind: 'user';
    id: string;
    content: string;
    attachments: MessageAttachment[];
}

export interface AssistantEntry {
    kind: 'assistant';
    id: string;
    /**
     * The turn this belongs to. One send can produce several assistant entries —
     * a multi-step run narrates, calls a tool, then narrates again — and the
     * thread renders them in that order rather than merging them.
     */
    turnId: string;
    text: string;
    pending: boolean;
    /** Extended-thinking transcript, empty when the model did none. */
    reasoning: string;
    reasoningStreaming: boolean;
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
    responseStatus: number | null;
    responseBody: string | null;
    recoverable: boolean;
}

export interface NoticeEntry {
    kind: 'notice';
    id: string;
    message: string;
}

export type ToolStatus = 'pending' | 'success' | 'error';

export interface ToolEntry {
    kind: 'tool';
    id: string;
    /** `tool` = the developer's own code; `provider_tool` = run inside the provider. */
    type: 'tool' | 'provider_tool';
    name: string;
    /** Provider tools only — the label reads `provider / name`. */
    provider: string | null;
    arguments: unknown;
    result: unknown;
    status: ToolStatus;
    /** The provider's own status word, before Synapse normalized it. */
    providerStatus: string | null;
    error: string | null;
    durationMs: number | null;
}

export type ChatEntry = UserEntry | AssistantEntry | ErrorEntry | NoticeEntry | ToolEntry;

/* ── Replay payload (GET /api/conversations/{id}) ──────────────────────────── */

export interface ConversationMessage {
    id: string;
    role: 'user' | 'assistant' | 'error';
    content: string | null;
    attachments: MessageAttachment[];
    usage: Usage | null;
    duration_ms: number | null;
    meta: MessageMetaData | null;
    metadata: Record<string, unknown> | null;
    created_at: string | null;
}

export interface ConversationToolInvocation {
    id: string;
    message_id: string | null;
    tool_call_id: string;
    type: 'tool' | 'provider_tool';
    name: string;
    arguments: unknown;
    result: unknown;
    status: ToolStatus;
    provider_status: string | null;
    error: string | null;
    duration_ms: number | null;
    started_at: string | null;
    finished_at: string | null;
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
    tool_invocations: ConversationToolInvocation[];
}
