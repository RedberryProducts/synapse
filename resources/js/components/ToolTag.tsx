import { Bot, Plug, Wrench, Zap } from 'lucide-react';
import { Badge } from '@/elements/Badge';
import { cn } from '@/lib/utils';
import type { ToolType } from '@/types/agent';

/** One tool chip (Figma `Tool Tag`), with an icon per tool kind. */

const icons: Record<ToolType, typeof Wrench> = {
    tool: Wrench,
    provider_tool: Zap,
    agent: Bot,
    mcp: Plug,
    unknown: Wrench,
};

export function ToolTag({
    name,
    type,
    className,
}: {
    name: string;
    type: ToolType;
    className?: string;
}) {
    const Icon = icons[type] ?? Wrench;

    return (
        <Badge className={cn('min-w-0', className)}>
            <Icon className="h-3 w-3 shrink-0" />
            <span className="truncate">{name}</span>
        </Badge>
    );
}
