/**
 * Where you were, per agent.
 *
 * Opening an agent should return you to the conversation you were last in — or
 * to a blank page if that is where you deliberately were. This is per-browser UI
 * state, not stored data: it lives in `localStorage` so it never resurrects a
 * thread on a machine you have never used, and it is not part of anything the
 * package persists.
 */

const KEY = 'synapse-last-conversation';

type Stored = Partial<Record<string, string | null>>;

export function remember(slug: string, conversationId: string | null): void {
    const all = read();

    all[slug] = conversationId;

    try {
        localStorage.setItem(KEY, JSON.stringify(all));
    } catch {
        // A full or unavailable localStorage costs a convenience, not the page.
    }
}

export function recall(slug: string): string | null {
    return read()[slug] ?? null;
}

/** Drop an id that no longer resolves, so a stale record can't strand you. */
export function forget(conversationId: string): void {
    const all = read();
    let changed = false;

    for (const [slug, id] of Object.entries(all)) {
        if (id === conversationId) {
            all[slug] = null;
            changed = true;
        }
    }

    if (changed) {
        try {
            localStorage.setItem(KEY, JSON.stringify(all));
        } catch {
            // As above.
        }
    }
}

function read(): Stored {
    try {
        const raw = localStorage.getItem(KEY);
        const parsed = raw ? JSON.parse(raw) : {};

        return typeof parsed === 'object' && parsed !== null ? parsed : {};
    } catch {
        return {};
    }
}
