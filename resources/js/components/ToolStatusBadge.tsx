import { CircleCheck, LoaderCircle, TriangleAlert } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { ToolStatus } from '@/types/chat';

/**
 * Status pill on a tool card — Figma `Status Badges`.
 *
 * A provider tool also carries the provider's own status word (`searching`,
 * `result_received`, …), which Synapse normalizes into these three. The raw
 * value is surfaced on hover rather than discarded: the normalization is for
 * layout, not for hiding what the provider said.
 */
const styles: Record<ToolStatus, string> = {
    pending: 'border-warning/40 bg-warning/10 text-warning',
    success: 'border-success/40 bg-success/10 text-success',
    error: 'border-destructive/40 bg-destructive/10 text-destructive',
};

const icons: Record<ToolStatus, typeof CircleCheck> = {
    pending: LoaderCircle,
    success: CircleCheck,
    error: TriangleAlert,
};

export function ToolStatusBadge({
    status,
    providerStatus,
}: {
    status: ToolStatus;
    providerStatus?: string | null;
}) {
    const Icon = icons[status];

    return (
        <span
            data-testid="tool-status"
            title={providerStatus ? `Provider reported "${providerStatus}"` : undefined}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-xs',
                styles[status],
            )}
        >
            <Icon className={cn('h-3 w-3', status === 'pending' && 'animate-spin')} />
            {status}
        </span>
    );
}
