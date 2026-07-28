import { Link } from 'react-router-dom';
import { AlertTriangle, ArrowUpRight } from 'lucide-react';
import { Badge } from '@/elements/Badge';
import { SchemaParameterRow } from './SchemaParameterRow';
import type { ToolDetail } from '@/types/agent';

const kindLabels: Partial<Record<ToolDetail['type'], string>> = {
    provider_tool: 'Provider tool',
    agent: 'Agent tool',
    mcp: 'MCP',
};

/** One tool in the Info panel's Tools tab. */
export function ToolDetailCard({ tool }: { tool: ToolDetail }) {
    const kind = kindLabels[tool.type];
    const options = Object.entries(tool.provider_options ?? {});

    return (
        <section data-testid="tool-detail" className="rounded-xl border border-border bg-card p-4">
            <div className="mb-3 flex items-start justify-between gap-2">
                <h3 className="text-xs font-medium tracking-wide text-subtle-foreground uppercase">
                    {tool.name}
                </h3>
                {kind && (
                    <Badge variant="pill" className="shrink-0">
                        {kind}
                    </Badge>
                )}
            </div>

            {tool.description && (
                <p className="mb-3 text-xs text-muted-foreground">{tool.description}</p>
            )}

            {tool.schema_error && (
                <p className="mb-3 flex items-start gap-1.5 text-xs text-destructive">
                    <AlertTriangle className="mt-0.5 h-3 w-3 shrink-0" />
                    Schema unavailable: {tool.schema_error}
                </p>
            )}

            {tool.parameters.length > 0 && (
                <div className="flex flex-col gap-2.5">
                    {tool.parameters.map((parameter) => (
                        <SchemaParameterRow key={parameter.name} parameter={parameter} />
                    ))}
                </div>
            )}

            {options.length > 0 && (
                <div className="flex flex-col gap-2.5">
                    {options.map(([key, value]) => (
                        <div key={key} className="flex items-start justify-between gap-3 text-sm">
                            <span className="text-muted-foreground">{key}</span>
                            <Badge className="shrink-0">{String(value)}</Badge>
                        </div>
                    ))}
                </div>
            )}

            {tool.agent_slug && (
                <Link
                    to={`/playground/${tool.agent_slug}?info=1`}
                    className="inline-flex items-center gap-1 text-xs text-accent hover:underline"
                >
                    Inspect {tool.name}
                    <ArrowUpRight className="h-3 w-3" />
                </Link>
            )}

            {tool.parameters.length === 0 &&
                options.length === 0 &&
                !tool.agent_slug &&
                !tool.schema_error && (
                    <p className="text-xs text-subtle-foreground">No parameters</p>
                )}
        </section>
    );
}
