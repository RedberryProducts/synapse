import ReactMarkdown from 'react-markdown';

/**
 * Basic element — markdown renderer.
 *
 * Styles are applied per element rather than via a typography plugin so the
 * output stays on our design tokens.
 */
export function Markdown({ children }: { children: string }) {
    return (
        <ReactMarkdown
            components={{
                h1: ({ children }) => (
                    <h1 className="mt-4 mb-2 text-base font-semibold first:mt-0">{children}</h1>
                ),
                h2: ({ children }) => (
                    <h2 className="mt-4 mb-2 text-sm font-semibold first:mt-0">{children}</h2>
                ),
                h3: ({ children }) => (
                    <h3 className="mt-3 mb-1.5 text-sm font-medium first:mt-0">{children}</h3>
                ),
                p: ({ children }) => <p className="mb-3 leading-relaxed last:mb-0">{children}</p>,
                ul: ({ children }) => (
                    <ul className="mb-3 list-disc space-y-1 pl-5 last:mb-0">{children}</ul>
                ),
                ol: ({ children }) => (
                    <ol className="mb-3 list-decimal space-y-1 pl-5 last:mb-0">{children}</ol>
                ),
                a: ({ children, href }) => (
                    <a href={href} className="text-accent underline underline-offset-2">
                        {children}
                    </a>
                ),
                code: ({ children }) => (
                    <code className="rounded bg-muted px-1.5 py-0.5 text-xs">{children}</code>
                ),
                pre: ({ children }) => (
                    <pre className="mb-3 overflow-x-auto rounded-lg bg-muted p-3 text-xs last:mb-0">
                        {children}
                    </pre>
                ),
                blockquote: ({ children }) => (
                    <blockquote className="mb-3 border-l-2 border-border pl-3 text-muted-foreground last:mb-0">
                        {children}
                    </blockquote>
                ),
                strong: ({ children }) => <strong className="font-semibold">{children}</strong>,
            }}
        >
            {children}
        </ReactMarkdown>
    );
}
