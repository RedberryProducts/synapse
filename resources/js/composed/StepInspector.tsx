import { Collapsible } from '@/elements/Collapsible';
import { ModelStepCard } from '@/components/ModelStepCard';
import type { ModelStep } from '@/types/chat';

export function StepInspector({ steps, durationMs }: { steps: ModelStep[]; durationMs: number | null }) {
    return (
        <section data-testid="step-inspector" aria-label="Response steps" className="min-w-0 text-sm">
            <Collapsible label={`Steps (${steps.length})`}>
                <div className="flex min-w-0 flex-col gap-3" data-testid="step-inspector-content">
                    <p className="text-xs text-subtle-foreground">Total turn time: {durationMs === null ? 'Unavailable' : `${durationMs} ms`}</p>
                    <p className="text-xs text-muted-foreground">Model-call time excludes local tool execution and approval waiting. Provider-hosted tools may run within the model call.</p>
                    {steps.map((step) => <ModelStepCard key={step.number} step={step} />)}
                </div>
            </Collapsible>
        </section>
    );
}
