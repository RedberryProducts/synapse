import { basePath } from './config';
import type { Agent, AgentDetail } from '@/types/agent';

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
