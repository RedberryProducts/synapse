/**
 * A nudge for anything showing a list of conversations.
 *
 * The sidebar's recents live in the app shell while writes happen on the History
 * page and in the playground. Re-fetching on navigation covers moving *between*
 * those places, but a rename or delete happens without navigating — which left
 * the sidebar showing a title that no longer existed.
 *
 * A window event rather than a store: one producer, one consumer, and no
 * state-management dependency for a five-row list.
 */

const EVENT = 'synapse:conversations-changed';

export function conversationsChanged(): void {
    window.dispatchEvent(new Event(EVENT));
}

export function onConversationsChanged(listener: () => void): () => void {
    window.addEventListener(EVENT, listener);

    return () => window.removeEventListener(EVENT, listener);
}
