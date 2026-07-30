/** Mirrors the payload from GET /synapse/api/conversations. */

export type ConversationStatus = 'success' | 'error';

export interface ConversationSummary {
    id: string;
    agent_class: string;
    agent_slug: string;
    agent_name: string;
    /** False once the agent class is gone — the thread stays readable. */
    agent_available: boolean;
    title: string;
    /**
     * Message-level only. A conversation whose tool failed but which answered
     * anyway is still a success.
     */
    status: ConversationStatus;
    tool_calls: number;
    prompt_tokens: number;
    completion_tokens: number;
    total_tokens: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface ConversationFilters {
    search: string;
    agents: string[];
    status: ConversationStatus | null;
    tools: string[];
    from: string | null;
    to: string | null;
    sort: 'newest' | 'oldest';
    page: number;
}

export interface FilterOptions {
    agents: { slug: string; name: string }[];
    tools: string[];
}

export interface ConversationPage {
    data: ConversationSummary[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: FilterOptions;
}

export const emptyFilters: ConversationFilters = {
    search: '',
    agents: [],
    status: null,
    tools: [],
    from: null,
    to: null,
    sort: 'newest',
    page: 1,
};

/** How many filters are narrowing the list right now. */
export function activeFilterCount(filters: ConversationFilters): number {
    return (
        filters.agents.length +
        filters.tools.length +
        (filters.status ? 1 : 0) +
        (filters.from || filters.to ? 1 : 0) +
        (filters.search ? 1 : 0)
    );
}
