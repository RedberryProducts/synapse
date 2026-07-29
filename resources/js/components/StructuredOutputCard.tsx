import { Braces } from 'lucide-react';
import { JsonView } from '@/elements/JsonView';

/**
 * A structured-output agent's parsed response.
 *
 * No Figma component — the PRD lists this under "implement without dedicated
 * designs", so it reuses the tool card's JSON block.
 */
export function StructuredOutputCard({ data }: { data: Record<string, unknown> }) {
    return (
        <div
            data-testid="structured-card"
            className="mt-1 rounded-xl border border-border bg-card p-4"
        >
            <p className="mb-2 flex items-center gap-1.5 text-xs text-muted-foreground">
                <Braces className="h-3.5 w-3.5" />
                Structured output
            </p>

            <JsonView value={data} testId="structured-output" />
        </div>
    );
}
