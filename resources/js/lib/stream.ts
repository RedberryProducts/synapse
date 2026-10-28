import { basePath } from './config';
import type {
    ApprovalSubmission,
    ChatError,
    CompletedTurn,
    ProviderToolPart,
    StreamHandlers,
    StreamPart,
    Usage,
} from '@/types/chat';

/**
 * Reads Synapse's SSE chat stream.
 *
 * Synapse owns both ends of this protocol — the server emits the Laravel AI
 * SDK's own Vercel part names, and this reads them back — so there is no need
 * to ship `ai` / `@ai-sdk/react` in a bundle that gets inlined into every
 * dashboard page load. Unknown part types are handed to `onPart` and otherwise
 * ignored, so parts added by later epics never break an older reader.
 */

const csrfToken =
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

export async function streamChat(
    slug: string,
    body: {
        message: string;
        conversation_id?: string | null;
        model?: string | null;
        files?: File[];
        approval?: ApprovalSubmission;
    },
    handlers: StreamHandlers,
    signal?: AbortSignal,
): Promise<void> {
    const hasFiles = (body.files?.length ?? 0) > 0;

    const endpoint = body.approval
        ? `messages/${encodeURIComponent(body.approval.messageId)}/approvals`
        : 'send';
    const response = await fetch(`${basePath}/api/chat/${encodeURIComponent(slug)}/${endpoint}`, {
        method: 'POST',
        signal,
        headers: {
            // Content-Type is omitted for multipart so the browser can set the
            // boundary; a text-only turn keeps its JSON body.
            ...(hasFiles ? {} : { 'Content-Type': 'application/json' }),
            Accept: 'text/event-stream',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken,
        },
        body: body.approval ? JSON.stringify(body.approval) : (hasFiles ? toFormData(body) : JSON.stringify(body)),
    });

    if (!response.ok || !response.body) {
        const error = await response.json().catch(() => null);
        throw new Error(error?.message ?? `Synapse chat request failed: ${response.status}`);
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let completed = false;
    let terminated = false;

    for (;;) {
        const { done, value } = await reader.read();

        if (done) {
            break;
        }

        buffer += decoder.decode(value, { stream: true });

        // SSE events are separated by a blank line; the tail is a partial event.
        const events = buffer.split('\n\n');
        buffer = events.pop() ?? '';

        for (const event of events) {
            const payload = event.replace(/^data: /gm, '').trim();

            if (payload === '[DONE]') { terminated = true; continue; }
            if (payload === '') continue;
            const part = JSON.parse(payload) as StreamPart;
            if (part.type === 'data-synapse-end') completed = true;
            dispatch(part, handlers);
        }
    }
    if (!completed || !terminated) throw new Error('The stream ended before the response was saved. Reload the conversation to check its status.');
}

function toFormData(body: {
    message: string;
    conversation_id?: string | null;
    model?: string | null;
    files?: File[];
}): FormData {
    const form = new FormData();

    form.append('message', body.message);

    if (body.conversation_id) {
        form.append('conversation_id', body.conversation_id);
    }

    if (body.model) {
        form.append('model', body.model);
    }

    for (const file of body.files ?? []) {
        form.append('attachments[]', file);
    }

    return form;
}

function dispatch(part: StreamPart, handlers: StreamHandlers): void {
    switch (part.type) {
        case 'data-synapse-start':
            handlers.onConversation?.(
                part.data?.conversationId as string,
                part.data?.userMessageId as string,
            );
            break;

        case 'source-url':
            if (typeof part.url === 'string') handlers.onSource?.({ url: part.url, title: typeof part.title === 'string' ? part.title : null });
            break;

        case 'text-delta':
            handlers.onTextDelta?.(part.id ?? 'text', part.delta ?? '');
            break;

        case 'error':
            handlers.onError?.(toChatError(part));
            break;

        case 'data-synapse-notice':
            handlers.onNotice?.(part.data?.message as string);
            break;

        case 'data-synapse-provider':
            handlers.onProvider?.(part.data?.provider as string);
            break;

        case 'data-structured-output':
            handlers.onStructured?.(part.data ?? {});
            break;

        case 'tool-input-available':
            handlers.onToolInput?.(
                part.toolCallId ?? '',
                part.toolName ?? 'tool',
                part.input,
            );
            break;

        case 'tool-output-available':
            if (!part.preliminary) handlers.onToolOutput?.(part.toolCallId ?? '', part.output);
            break;

        case 'tool-approval-request':
            handlers.onToolApproval?.(part.toolCallId ?? '');
            break;

        case 'tool-output-denied':
            handlers.onToolDenied?.(part.toolCallId ?? '');
            break;

        case 'tool-output-error':
            handlers.onToolError?.(part.toolCallId ?? '', part.errorText ?? 'The tool failed.');
            break;

        case 'data-provider-tool':
            handlers.onProviderTool?.(part.data as unknown as ProviderToolPart);
            break;

        case 'reasoning-start':
            handlers.onReasoningStart?.();
            break;

        case 'reasoning-delta':
            handlers.onReasoningDelta?.(part.delta ?? '');
            break;

        case 'reasoning-end':
            handlers.onReasoningEnd?.();
            break;

        case 'data-synapse-end':
            handlers.onFinish?.(
                (part.data?.assistantMessageId as string | null) ?? null,
                (part.data?.usage as Usage | null) ?? null,
                (part.data?.durationMs as number | null) ?? null,
                (part.data?.turn as CompletedTurn | null) ?? null,
            );
            break;

        default:
            // start / text-start / text-end / reasoning-* / finish — consumed
            // by later epics.
            handlers.onPart?.(part);
    }
}

function toChatError(part: StreamPart): ChatError {
    return {
        messageId: (part.data?.messageId as string) ?? '',
        message: part.errorText ?? 'The agent failed.',
        exceptionClass: (part.data?.exceptionClass as string | null) ?? null,
        stackTrace: (part.data?.stackTrace as string | null) ?? null,
        responseStatus: (part.data?.responseStatus as number | null) ?? null,
        responseBody: (part.data?.responseBody as string | null) ?? null,
        recoverable: Boolean(part.data?.recoverable),
    };
}
