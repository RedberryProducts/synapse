import { basePath } from './config';
import type { ChatError, StreamHandlers, StreamPart, Usage } from '@/types/chat';

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
    body: { message: string; conversation_id?: string | null },
    handlers: StreamHandlers,
    signal?: AbortSignal,
): Promise<void> {
    const response = await fetch(`${basePath}/api/chat/${encodeURIComponent(slug)}/send`, {
        method: 'POST',
        signal,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'text/event-stream',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify(body),
    });

    if (!response.ok || !response.body) {
        throw new Error(`Synapse chat request failed: ${response.status}`);
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

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

            if (payload === '' || payload === '[DONE]') {
                continue;
            }

            dispatch(JSON.parse(payload) as StreamPart, handlers);
        }
    }
}

function dispatch(part: StreamPart, handlers: StreamHandlers): void {
    switch (part.type) {
        case 'data-synapse-start':
            handlers.onConversation?.(
                part.data?.conversationId as string,
                part.data?.userMessageId as string,
            );
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

        case 'data-structured-output':
            handlers.onStructured?.(part.data ?? {});
            break;

        case 'data-synapse-end':
            handlers.onFinish?.(
                (part.data?.assistantMessageId as string | null) ?? null,
                (part.data?.usage as Usage | null) ?? null,
                (part.data?.durationMs as number | null) ?? null,
            );
            break;

        default:
            // start / text-start / text-end / reasoning-* / tool-* / finish —
            // consumed by later epics.
            handlers.onPart?.(part);
    }
}

function toChatError(part: StreamPart): ChatError {
    return {
        messageId: (part.data?.messageId as string) ?? '',
        message: part.errorText ?? 'The agent failed.',
        exceptionClass: (part.data?.exceptionClass as string | null) ?? null,
        stackTrace: (part.data?.stackTrace as string | null) ?? null,
        recoverable: Boolean(part.data?.recoverable),
    };
}
