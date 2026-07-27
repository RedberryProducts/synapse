import { cn } from '@/lib/utils';

/** Basic element — loading placeholder. No Figma counterpart. */
export function Skeleton({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('animate-pulse rounded-md bg-muted', className)} {...props} />;
}
