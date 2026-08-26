import { useNavigate } from 'react-router-dom';
import { AlertTriangle, ArrowRight, Info } from 'lucide-react';
import { Badge } from '@/elements/Badge';
import { Button } from '@/elements/Button';
import { Card, CardBody, CardFooter } from '@/elements/Card';
import { Tooltip } from '@/elements/Tooltip';
import { ToolTagList } from './ToolTagList';
import { UnresolvableHint } from './UnresolvableHint';
import { cn } from '@/lib/utils';
import type { Agent } from '@/types/agent';

/** One agent on the Discovery page (Figma `Card`). */
export function AgentCard({ agent }: { agent: Agent }) {
    const navigate = useNavigate();
    const open = () => navigate(`/playground/${agent.slug}`);

    if (!agent.available) {
        return (
            <Card className="min-w-0" data-testid="agent-card-unavailable">
                <CardBody>
                    <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0 flex-1">
                            <h3
                                className="truncate text-lg font-semibold text-muted-foreground"
                                title={agent.name}
                                data-testid="agent-card-unavailable-name"
                            >
                                {agent.name}
                            </h3>
                        </div>
                        <Tooltip content={agent.error ?? 'This agent could not be instantiated.'}>
                            <AlertTriangle className="h-4 w-4 shrink-0 text-destructive" />
                        </Tooltip>
                    </div>

                    <UnresolvableHint
                        kind={agent.error_kind}
                        dependencies={agent.unresolvable}
                        error={agent.error}
                    />
                </CardBody>
                <CardFooter>
                    <span className="text-xs text-subtle-foreground">
                        Fix the error above, then refresh
                    </span>
                </CardFooter>
            </Card>
        );
    }

    return (
        <Card interactive className="min-w-0" onClick={open} data-testid="agent-card">
            <CardBody>
                <h3
                    className="truncate text-lg font-semibold"
                    title={agent.name}
                    data-testid="agent-card-name"
                >
                    {agent.name}
                </h3>

                <p className="flex flex-wrap items-center gap-1.5 text-xs">
                    <span className="text-muted-foreground">{agent.provider ?? 'default'}</span>
                    <span className="text-subtle-foreground">/</span>
                    {agent.model ? (
                        <span className="text-accent">{agent.model}</span>
                    ) : (
                        <Badge variant="pill" className="py-0.5">
                            {agent.model_tier}
                        </Badge>
                    )}
                </p>

                <ToolTagList tools={agent.tools} />
            </CardBody>

            <CardFooter>
                <Button
                    variant="link"
                    size="sm"
                    className={cn('px-0')}
                    onClick={(event) => {
                        event.stopPropagation();
                        navigate(`/playground/${agent.slug}?info=1`);
                    }}
                >
                    <Info className="h-4 w-4" />
                    Info
                </Button>

                <Button variant="link" size="sm" className="px-0 text-foreground">
                    Open Playground
                    <ArrowRight className="h-4 w-4" />
                </Button>
            </CardFooter>
        </Card>
    );
}
