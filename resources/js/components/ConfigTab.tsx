import { Badge } from '@/elements/Badge';
import { InfoRow, InfoSection } from './InfoSection';
import { ToolTag } from './ToolTag';
import type { AgentDetail } from '@/types/agent';

/** Info panel → Config: provider, generation settings, capabilities, middleware. */
export function ConfigTab({ agent }: { agent: AgentDetail }) {
    const generation = agent.generation;
    const providerOptions = Object.entries(agent.provider_options ?? {});
    const toolChoice = generation?.tool_choice;

    return (
        <div className="flex flex-col gap-4">
            <InfoSection title="Provider">
                <InfoRow label="Provider" value={agent.provider} />
                <InfoRow
                    label="Model"
                    value={
                        agent.model ?? (
                            <Badge variant="pill" className="py-0">
                                {agent.model_tier}
                            </Badge>
                        )
                    }
                    accent={Boolean(agent.model)}
                />
                <InfoRow label="Class" value={agent.class} mono />
            </InfoSection>

            {generation && (
                <InfoSection title="Generation">
                    <InfoRow label="Temperature" value={generation.temperature} />
                    <InfoRow label="Max_Tokens" value={generation.max_tokens} />
                    <InfoRow label="Max_Steps" value={generation.max_steps} />
                    <InfoRow label="Top_P" value={generation.top_p} />
                    <InfoRow
                        label="Tool_Choice"
                        value={
                            toolChoice
                                ? toolChoice.tool
                                    ? `${toolChoice.mode}: ${toolChoice.tool}`
                                    : toolChoice.mode
                                : null
                        }
                    />
                    <InfoRow label="Timeout" value={`${generation.timeout}s`} />
                    <InfoRow label="Strict" value={generation.strict ? 'Enabled' : null} />
                </InfoSection>
            )}

            {providerOptions.length > 0 && (
                <InfoSection title="Provider options">
                    {providerOptions.map(([key, value]) => (
                        <InfoRow key={key} label={key} value={String(value)} />
                    ))}
                </InfoSection>
            )}

            {agent.tools.length > 0 && (
                <InfoSection title="Capabilities">
                    <div className="flex flex-wrap gap-2">
                        {agent.tools.map((tool, index) => (
                            <ToolTag
                                key={`${tool.name}-${index}`}
                                name={tool.name}
                                type={tool.type}
                            />
                        ))}
                    </div>
                </InfoSection>
            )}

            {agent.middleware.length > 0 && (
                <InfoSection title="Middleware" count={agent.middleware.length}>
                    {agent.middleware.map((name) => (
                        <span
                            key={name}
                            className="rounded-md bg-muted px-2 py-1 font-mono text-xs break-words text-muted-foreground"
                        >
                            {name}
                        </span>
                    ))}
                </InfoSection>
            )}
        </div>
    );
}
