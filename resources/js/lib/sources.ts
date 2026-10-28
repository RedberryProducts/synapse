import type { CitationSource } from '@/types/chat';

export function sourceUrl(url: string): URL | null {
    if (!/^https?:\/\//i.test(url) || /[\x00-\x20\x7f\\]/.test(url)) return null;
    try {
        const parsed = new URL(url);
        return parsed.hostname && !parsed.username && !parsed.password ? parsed : null;
    } catch {
        return null;
    }
}

export function mergeSources(...groups: CitationSource[][]): CitationSource[] {
    const sources = new Map<string, CitationSource>();
    for (const source of groups.flat()) {
        if (!sourceUrl(source.url)) continue;
        const title = source.title?.trim() || null;
        const existing = sources.get(source.url);
        if (!existing) sources.set(source.url, { url: source.url, title });
        else if (!existing.title && title) sources.set(source.url, { ...existing, title });
    }
    return [...sources.values()];
}
