import { Badge } from '@/elements/Badge';
import { Tooltip } from '@/elements/Tooltip';
import { ToolTag } from './ToolTag';
import type { AgentTool } from '@/types/agent';

/**
 * Tool chips with overflow: shows up to `limit`, collapses the rest into a
 * `+N` chip whose tooltip lists every tool.
 */
export function ToolTagList({ tools, limit = 3 }: { tools: AgentTool[]; limit?: number }) {
    if (tools.length === 0) {
        return <p className="text-xs text-subtle-foreground">No tools</p>;
    }

    const visible = tools.slice(0, limit);
    const hidden = tools.slice(limit);

    return (
        <div className="flex flex-wrap items-center gap-2">
            {visible.map((tool, index) => (
                <ToolTag key={`${tool.name}-${index}`} name={tool.name} type={tool.type} />
            ))}

            {hidden.length > 0 && (
                <Tooltip
                    content={
                        <span className="flex flex-col gap-1.5">
                            <span className="text-subtle-foreground">
                                Tools ({tools.length})
                            </span>
                            {tools.map((tool, index) => (
                                <span key={`${tool.name}-all-${index}`} className="text-foreground">
                                    {tool.name}
                                </span>
                            ))}
                        </span>
                    }
                >
                    <Badge
                        variant="pill"
                        tabIndex={0}
                        className="cursor-default"
                        data-testid="tool-overflow"
                        aria-label={`${hidden.length} more tools`}
                    >
                        + {hidden.length}
                    </Badge>
                </Tooltip>
            )}
        </div>
    );
}
