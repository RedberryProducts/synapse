import { Info } from 'lucide-react';
import { Badge } from '@/elements/Badge';

/**
 * Shown for agents that do not implement `Conversational`.
 *
 * The playground looks like a chat, but for these agents it is a sequence of
 * independent request/response pairs — the agent itself never receives the
 * earlier turns. Saying so is the point: Synapse mirrors how an agent really
 * behaves rather than lending it memory it does not have in production.
 */
export function StatelessNotice() {
    return (
        <Badge
            data-testid="stateless-notice"
            variant="pill"
            title="This agent does not implement Conversational, so Synapse sends each message on its own."
        >
            <Info className="h-3 w-3" />
            Stateless — each message is sent independently
        </Badge>
    );
}
