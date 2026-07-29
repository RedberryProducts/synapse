import { Check, ChevronDown, Sparkles } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/elements/DropdownMenu';
import type { ModelOption } from '@/types/agent';

/**
 * The composer's model chip — Figma `Models`, collapsed and open.
 *
 * A per-send override, never a stored preference: the playground always opens
 * on the model the agent is configured with, so you can't come back later and
 * mistake an experiment for the agent's real setting.
 */
const tierLabels: Record<string, string> = {
    agent: 'agent default',
    cheapest: 'cheapest',
    smartest: 'smartest',
    configured: 'configured',
};

export function ModelSelector({
    models,
    selected,
    onSelect,
}: {
    models: ModelOption[];
    selected: string | null;
    onSelect: (model: string | null) => void;
}) {
    if (models.length === 0) {
        return null;
    }

    const current = models.find((model) => model.id === selected) ?? models[0];

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                data-testid="model-selector"
                aria-label={`Model: ${current.label}`}
                className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-muted px-2.5 py-1 text-xs text-muted-foreground transition-colors hover:text-foreground"
            >
                <Sparkles className="h-3.5 w-3.5 text-primary" />
                <span className="max-w-40 truncate">{current.label}</span>
                <ChevronDown className="h-3 w-3 opacity-60" />
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" side="top" className="w-56">
                {models.map((model) => (
                    <DropdownMenuItem
                        key={model.id}
                        active={model.id === current.id}
                        // Selecting the agent's own model clears the override
                        // rather than pinning it, so the request stays identical
                        // to one the developer never touched.
                        onSelect={() => onSelect(model.tier === 'agent' ? null : model.id)}
                    >
                        <span className="flex min-w-0 flex-1 flex-col">
                            <span className="truncate">{model.label}</span>
                            {tierLabels[model.tier] && (
                                <span className="text-[10px] text-subtle-foreground">
                                    {tierLabels[model.tier]}
                                </span>
                            )}
                        </span>
                        {model.id === current.id && <Check className="h-3.5 w-3.5 shrink-0" />}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
