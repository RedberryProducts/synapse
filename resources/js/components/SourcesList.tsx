import { ExternalLink } from 'lucide-react';
import { sourceUrl } from '@/lib/sources';
import type { CitationSource } from '@/types/chat';

export function SourcesList({ sources }: { sources: CitationSource[] }) {
    const links = sources.flatMap((source) => {
        const url = sourceUrl(source.url);
        return url ? [{ ...source, hostname: url.hostname }] : [];
    });
    if (links.length === 0) return null;

    return (
        <section aria-label="Sources" data-testid="sources" className="mt-4 border-t border-border pt-3">
            <h3 className="mb-2 text-xs font-medium text-muted-foreground">Sources</h3>
            <ul className="space-y-2">
                {links.map((source) => (
                    <li key={source.url}>
                        <a href={source.url} target="_blank" rel="noopener noreferrer" title={source.url}
                            className="flex min-w-0 items-start gap-2 rounded-sm text-xs text-foreground underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-ring">
                            <ExternalLink aria-hidden="true" className="mt-0.5 h-3 w-3 shrink-0 text-muted-foreground" />
                            <span className="min-w-0 break-words [overflow-wrap:anywhere]">
                                {source.title || source.hostname || source.url}
                                {source.title && <span className="mt-0.5 block text-muted-foreground">{source.hostname}</span>}
                            </span>
                        </a>
                    </li>
                ))}
            </ul>
        </section>
    );
}
