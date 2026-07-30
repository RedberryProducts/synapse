import { basePath } from './config';
import type { Agent, AgentDetail } from '@/types/agent';
import type { Conversation } from '@/types/chat';
import type {
    ConversationFilters,
    ConversationPage,
    ConversationSummary,
} from '@/types/conversation';

const csrfToken =
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

/**
 * Thin fetch wrapper for the Synapse JSON API. All calls are relative to
 * `{basePath}/api` and carry the CSRF header.
 */
export async function api<T = unknown>(path: string, init: RequestInit = {}): Promise<T> {
    const response = await fetch(`${basePath}/api${path}`, {
        ...init,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken,
            ...(init.headers ?? {}),
        },
    });

    if (!response.ok) {
        throw new Error(`Synapse API request failed: ${response.status}`);
    }

    return response.status === 204 ? (undefined as T) : (response.json() as Promise<T>);
}

/** Feature 1 — discovered agents with their card metadata. */
export function getAgents(signal?: AbortSignal): Promise<Agent[]> {
    return api<Agent[]>('/agents', { signal });
}

/** Feature 4 — full detail for one agent (Info panel). */
export function getAgent(slug: string, signal?: AbortSignal): Promise<AgentDetail> {
    return api<AgentDetail>(`/agents/${encodeURIComponent(slug)}`, { signal });
}

/**
 * Feature 2 — replay a conversation.
 *
 * Sending a message does not go through here: it streams, and lives in
 * `lib/stream.ts`.
 */
export function getConversation(id: string, signal?: AbortSignal): Promise<Conversation> {
    return api<Conversation>(`/conversations/${encodeURIComponent(id)}`, { signal });
}

/** Feature 2 — clear conversation: deletes the thread and its tool rows. */
export function deleteConversation(id: string): Promise<void> {
    return api<void>(`/conversations/${encodeURIComponent(id)}`, { method: 'DELETE' });
}

/** Feature 5 — the History list, filtered and paginated. */
export function getConversations(
    filters: Partial<ConversationFilters> = {},
    signal?: AbortSignal,
): Promise<ConversationPage> {
    return api<ConversationPage>(`/conversations?${toQuery(filters)}`, { signal });
}

/** Feature 5 — rename. Titles are never model-generated. */
export function renameConversation(id: string, title: string): Promise<ConversationSummary> {
    return api<ConversationSummary>(`/conversations/${encodeURIComponent(id)}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ title }),
    });
}

/**
 * Filters as a query string, omitting anything not set.
 *
 * Sending empty values would make every request look filtered and would put
 * noise in the URL the page mirrors its state to.
 */
function toQuery(filters: Partial<ConversationFilters>): string {
    const params = new URLSearchParams();

    if (filters.search) {
        params.set('search', filters.search);
    }

    for (const agent of filters.agents ?? []) {
        params.append('agents[]', agent);
    }

    for (const tool of filters.tools ?? []) {
        params.append('tools[]', tool);
    }

    if (filters.status) {
        params.set('status', filters.status);
    }

    if (filters.from) {
        params.set('from', filters.from);
    }

    if (filters.to) {
        params.set('to', filters.to);
    }

    if (filters.sort && filters.sort !== 'newest') {
        params.set('sort', filters.sort);
    }

    if (filters.page && filters.page > 1) {
        params.set('page', String(filters.page));
    }

    return params.toString();
}
