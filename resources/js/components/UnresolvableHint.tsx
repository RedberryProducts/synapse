import type { UnresolvableDependency } from '@/types/agent';

/**
 * Turns a container resolution failure into something the developer can act on.
 */
function shortName(type: string | null): string {
    return type ? (type.split('\\').pop() ?? type) : 'unknown';
}

function hintFor(dependency: UnresolvableDependency): { what: string; fix: string } {
    const { parameter, type, reason } = dependency;
    const name = shortName(type);

    switch (reason) {
        case 'unbound_interface':
            return {
                what: `$${parameter} needs ${name}, an interface with no binding.`,
                fix: `Bind it in a service provider: $this->app->bind(${name}::class, YourImplementation::class);`,
            };
        case 'unbound_abstract':
            return {
                what: `$${parameter} needs ${name}, an abstract class with no binding.`,
                fix: `Bind a concrete implementation: $this->app->bind(${name}::class, YourImplementation::class);`,
            };
        case 'missing_class':
            return {
                what: `$${parameter} is type-hinted ${name}, which does not exist.`,
                fix: 'Fix the import or the type-hint, then refresh.',
            };
        case 'primitive':
            return {
                what: `$${parameter} is a ${name} value, which the container cannot autowire.`,
                fix: 'Give it a default value, or resolve it inside the agent instead of the constructor.',
            };
        case 'untyped':
            return {
                what: `$${parameter} has no type-hint.`,
                fix: 'Add a type-hint the container can resolve, or give the parameter a default value.',
            };
        default:
            return {
                what: `$${parameter} uses a union or intersection type.`,
                fix: 'Type-hint a single class or interface the container can resolve.',
            };
    }
}

export function UnresolvableHint({
    kind,
    dependencies,
    error,
}: {
    kind: 'binding' | 'exception' | null;
    dependencies: UnresolvableDependency[];
    error: string | null;
}) {
    // Something other than the container failed — usually the constructor body.
    if (kind !== 'binding') {
        return (
            <div className="flex flex-col gap-1.5 rounded-lg border border-border bg-muted p-3 text-xs">
                <p className="font-medium text-foreground">This agent threw while starting up</p>
                <code className="block rounded-md bg-background px-2 py-1.5 break-words text-subtle-foreground">
                    {error ?? 'Unknown error.'}
                </code>
            </div>
        );
    }

    // The container failed but the constructor looks fine — the failure is one
    // level deeper (a dependency of a dependency).
    if (dependencies.length === 0) {
        return (
            <div className="flex flex-col gap-1.5 rounded-lg border border-border bg-muted p-3 text-xs">
                <p className="font-medium text-foreground">
                    Laravel couldn&rsquo;t build one of this agent&rsquo;s dependencies
                </p>
                <code className="block rounded-md bg-background px-2 py-1.5 break-words text-subtle-foreground">
                    {error ?? 'Unknown binding error.'}
                </code>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-2 rounded-lg border border-border bg-muted p-3 text-xs">
            <p className="font-medium text-foreground">
                Synapse can&rsquo;t construct this agent
            </p>

            {dependencies.map((dependency) => {
                const { what, fix } = hintFor(dependency);

                return (
                    <div key={dependency.parameter} className="flex flex-col gap-1">
                        <p className="text-muted-foreground">{what}</p>
                        <code className="block rounded-md bg-background px-2 py-1.5 text-subtle-foreground break-words">
                            {fix}
                        </code>
                    </div>
                );
            })}
        </div>
    );
}
