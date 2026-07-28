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
            <Card data-testid="agent-card-unavailable">
                <CardBody>
                    <div className="flex items-start justify-between gap-2">
                        <h3 className="text-lg font-semibold text-muted-foreground">{agent.name}</h3>
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
        <Card interactive onClick={open} data-testid="agent-card">
            <CardBody>
                <h3 className="text-lg font-semibold">{agent.name}</h3>

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
