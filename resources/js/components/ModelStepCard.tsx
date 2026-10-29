import { useId, useState } from 'react';
import { ChevronDown } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Collapsible } from '@/elements/Collapsible';
import { JsonView } from '@/elements/JsonView';
import { Markdown } from '@/elements/Markdown';
import { ReasoningPane } from './ReasoningPane';
import { ToolStatusBadge } from './ToolStatusBadge';
import type { ModelStep, StepTool } from '@/types/chat';

const completionLabels = {
    completed: 'completed',
    response: 'recorded',
    incomplete: 'incomplete',
    unverified: 'unverified',
};

export function ModelStepCard({ step }: { step: ModelStep }) {
    const [open, setOpen] = useState(false);
    const contentId = useId();
    const model = `${step.provider ?? 'Provider unavailable'} / ${step.model ?? 'Model unavailable'}`;
    const duration = step.model_duration_ms === null ? 'Unavailable' : `${Number(step.model_duration_ms.toFixed(2))} ms`;
    const usage = step.usage;
    const total = usage.input_tokens === null || usage.output_tokens === null ? null : usage.input_tokens + usage.output_tokens;
    return (
        <article data-testid={`model-step-${step.number}`} className="min-w-0 rounded-lg border border-border bg-card">
            <button
                type="button"
                aria-label={`Step ${step.number}`}
                aria-expanded={open}
                aria-controls={contentId}
                onClick={() => setOpen(!open)}
                className="flex w-full flex-wrap items-center gap-x-3 gap-y-1 rounded-lg px-3 py-2 text-left text-xs transition-colors hover:bg-muted/50 focus-visible:outline-2 focus-visible:outline-ring"
            >
                <span className="flex min-w-0 flex-1 basis-56 items-center gap-2">
                    <ChevronDown aria-hidden="true" className={cn('h-3.5 w-3.5 shrink-0 text-muted-foreground transition-transform', open && 'rotate-180')} />
                    <span className="shrink-0 font-medium">Step {step.number}</span>
                    <span title={model} className="truncate text-muted-foreground">{model}</span>
                </span>
                <span className="ml-auto flex items-center gap-3 whitespace-nowrap text-subtle-foreground">
                    <span title={step.completion === 'response' ? 'Response recorded; model call not observed' : step.completion === 'unverified' ? 'Historical completion unverified' : undefined}>{completionLabels[step.completion]}</span>
                    <span title="Finish reason">{step.finish_reason ?? 'Unavailable'}</span>
                    <span title="Model-call time">{duration}</span>
                </span>
            </button>
            {open && (
                <div id={contentId} className="flex min-w-0 flex-col gap-4 border-t border-border p-3" data-testid={`model-step-${step.number}-content`}>
                    <p className="break-all text-xs text-muted-foreground">{model}</p>
                    {step.completion === 'response' && <p className="text-xs text-muted-foreground">Response recorded; model call not observed.</p>}
                    {step.completion === 'unverified' && <p className="text-xs text-muted-foreground">Historical completion unverified.</p>}
                    {step.reasoning && <ReasoningPane text={step.reasoning} streaming={false} />}
                    <div className="min-w-0 break-words">
                        <p className="mb-1 text-xs text-muted-foreground">Output</p>
                        {step.text ? <Markdown>{step.text}</Markdown> : <p className="text-xs text-muted-foreground">{step.text === '' ? 'No text returned.' : 'Unavailable'}</p>}
                    </div>
                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground" data-testid="step-usage">
                        <span>Input: {usage.input_tokens ?? 'Unavailable'}</span>
                        <span>Output: {usage.output_tokens ?? 'Unavailable'}</span>
                        <span>Total: {total ?? 'Unavailable'}</span>
                        {usage.cache_read_input_tokens !== null && <span>Cache read (input subset): {usage.cache_read_input_tokens}</span>}
                        {usage.cache_write_input_tokens !== null && <span>Cache write (input subset): {usage.cache_write_input_tokens}</span>}
                        {usage.reasoning_tokens !== null && <span>Reasoning (output subset): {usage.reasoning_tokens}</span>}
                    </div>
                    {step.tools.map((tool, index) => <StepToolDetail key={tool.observation_id ?? `${tool.call_id}:${index}`} tool={tool} />)}
                </div>
            )}
        </article>
    );
}

function StepToolDetail({ tool }: { tool: StepTool }) {
    return (
        <div data-testid="step-tool" className="min-w-0 rounded-lg border border-border p-3">
            <div className="mb-2 flex flex-wrap items-center gap-2 text-xs">
                <span className="break-all font-medium">{tool.name ?? 'Tool unavailable'}</span>
                {tool.type === 'provider_tool' && <span className="text-muted-foreground">Provider-hosted</span>}
                {tool.status === 'unavailable' ? <span>Result unavailable</span> : <ToolStatusBadge status={tool.status} />}
            </div>
            <p className="mb-2 break-all text-xs text-subtle-foreground">Call ID: {tool.call_id ?? 'Unavailable'}</p>
            <p className="mb-3 text-xs text-subtle-foreground">Tool time: {tool.duration_ms === null ? 'Unavailable' : `${tool.duration_ms} ms`}</p>
            <Collapsible label="Arguments and result">
                <div className="flex min-w-0 flex-col gap-3">
                    {tool.arguments === null ? <p className="text-xs">Arguments unavailable</p> : <JsonView label="Arguments" value={tool.arguments} />}
                    {tool.result === null && tool.status !== 'success' ? <p className="text-xs">Result unavailable</p> : <JsonView label="Result" value={tool.result} />}
                    {tool.error && <p className="break-words text-xs text-destructive">{tool.error}</p>}
                </div>
            </Collapsible>
        </div>
    );
}
