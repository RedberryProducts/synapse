import { SchemaParameterRow } from './SchemaParameterRow';
import { ToolDetailCard } from './ToolDetailCard';
import type { AgentDetail } from '@/types/agent';

/** Info panel → Tools: every registered tool, plus the output schema when present. */
export function ToolsTab({ agent }: { agent: AgentDetail }) {
    const hasNothing = agent.tools.length === 0 && !agent.output_schema;

    if (hasNothing) {
        return (
            <p className="rounded-xl border border-dashed border-border p-6 text-center text-sm text-subtle-foreground">
                This agent has no tools.
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-4">
            {agent.tools.map((tool, index) => (
                <ToolDetailCard key={`${tool.name}-${index}`} tool={tool} />
            ))}

            {agent.output_schema && (
                <section className="rounded-xl border border-border bg-card p-4">
                    <h3 className="mb-3 text-xs font-medium tracking-wide text-subtle-foreground uppercase">
                        Output schema
                    </h3>
                    <div className="flex flex-col gap-2.5">
                        {agent.output_schema.map((parameter) => (
                            <SchemaParameterRow key={parameter.name} parameter={parameter} />
                        ))}
                    </div>
                </section>
            )}
        </div>
    );
}
