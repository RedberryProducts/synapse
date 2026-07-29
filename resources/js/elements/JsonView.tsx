import { Copy } from './Copy';
import { cn } from '@/lib/utils';

/*
| Basic element — formatted JSON block, matching the argument/result blocks in
| the Figma tool card.
|
| Hand-rolled rather than a JSON-tree library: the design is a formatted block,
| not an interactive tree, and this bundle is inlined into every dashboard page
| load. Tokens are coloured with our own variables so both themes come free.
*/

export function JsonView({
    value,
    label,
    tone = 'default',
    testId,
    className,
}: {
    value: unknown;
    label?: string;
    tone?: 'default' | 'error';
    testId?: string;
    className?: string;
}) {
    const text = format(value);

    return (
        <div data-testid={testId} className={className}>
            {label && (
                <p
                    className={cn(
                        'mb-1.5 text-xs',
                        tone === 'error' ? 'text-destructive' : 'text-muted-foreground',
                    )}
                >
                    {label}
                </p>
            )}

            <div
                className={cn(
                    'relative rounded-lg border',
                    tone === 'error'
                        ? 'border-destructive/40 bg-destructive/5'
                        : 'border-border bg-muted',
                )}
            >
                <Copy value={text} className="absolute top-1.5 right-1.5" />

                <pre className="max-h-72 overflow-auto p-3 pr-10 font-mono text-[11px] leading-relaxed whitespace-pre-wrap">
                    {tone === 'error' ? (
                        <span className="text-destructive">{text}</span>
                    ) : (
                        highlight(text)
                    )}
                </pre>
            </div>
        </div>
    );
}

/**
 * Pretty-print, but never at the cost of showing the real value.
 *
 * A tool handler returns a string, so a result may be JSON or may be prose. A
 * string that doesn't parse is shown exactly as it came back rather than being
 * reported as malformed — what the model received is the point.
 */
function format(value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }

    if (typeof value === 'string') {
        try {
            return JSON.stringify(JSON.parse(value), null, 2);
        } catch {
            return value;
        }
    }

    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return String(value);
    }
}

/** Matches a JSON key, a string, a number, or a keyword — in that order. */
const TOKENS = /("(?:\\.|[^"\\])*"\s*:)|("(?:\\.|[^"\\])*")|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)|(\btrue\b|\bfalse\b|\bnull\b)/g;

function highlight(text: string): React.ReactNode {
    const parts: React.ReactNode[] = [];
    let lastIndex = 0;
    let match: RegExpExecArray | null;

    TOKENS.lastIndex = 0;

    while ((match = TOKENS.exec(text)) !== null) {
        if (match.index > lastIndex) {
            parts.push(text.slice(lastIndex, match.index));
        }

        const [token, key, string, number] = match;

        parts.push(
            <span
                key={match.index}
                className={
                    key
                        ? 'text-json-key'
                        : string
                          ? 'text-json-string'
                          : number
                            ? 'text-json-number'
                            : 'text-json-keyword'
                }
            >
                {token}
            </span>,
        );

        lastIndex = match.index + token.length;
    }

    if (lastIndex < text.length) {
        parts.push(text.slice(lastIndex));
    }

    return parts;
}
