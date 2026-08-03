import { TriangleAlert } from 'lucide-react';
import { Badge } from '@/elements/Badge';

/**
 * Shown when the runtime cannot push a response as it is produced.
 *
 * Without it the failure is indistinguishable from a hang: the thread sits
 * empty for however long the agent takes, then the whole conversation appears
 * at once. Synapse shipped exactly that behaviour for six epics before anyone
 * noticed, which is the argument for saying it out loud.
 */
export function StreamingNotice() {
    return (
        <Badge
            data-testid="streaming-notice"
            variant="pill"
            title="PHP can only stream while serving HTTP. Under this runtime the whole response is assembled before it is sent."
        >
            <TriangleAlert className="h-3 w-3" />
            This runtime buffers responses — replies appear all at once
        </Badge>
    );
}
