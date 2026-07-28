import { Badge } from '@/elements/Badge';
import type { SchemaParameter } from '@/types/agent';

/**
 * One schema parameter: name + type badge, with the description underneath when
 * the tool defines one. Required parameters are marked with an asterisk.
 */
export function SchemaParameterRow({ parameter }: { parameter: SchemaParameter }) {
    return (
        <div className="flex flex-col gap-1">
            <div className="flex items-start justify-between gap-3">
                <span className="text-sm text-muted-foreground">
                    {parameter.name}
                    {parameter.required && (
                        <span className="ml-0.5 text-primary" title="Required">
                            *
                        </span>
                    )}
                </span>
                <Badge className="shrink-0 capitalize">{parameter.type}</Badge>
            </div>

            {parameter.description && (
                <p className="text-xs text-subtle-foreground">{parameter.description}</p>
            )}
        </div>
    );
}
