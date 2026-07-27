/** Mirrors the payload from GET /synapse/api/agents. */

export type ToolType = 'tool' | 'provider_tool' | 'agent' | 'mcp' | 'unknown';

export type ModelTier = 'default' | 'cheapest' | 'smartest';

export interface AgentTool {
    name: string;
    type: ToolType;
}

export interface AgentCapabilities {
    conversational: boolean;
    remembers_conversations: boolean;
    has_tools: boolean;
    has_structured_output: boolean;
    has_middleware: boolean;
    can_act_as_tool: boolean;
}

export type UnresolvableReason =
    | 'unbound_interface'
    | 'unbound_abstract'
    | 'missing_class'
    | 'primitive'
    | 'untyped'
    | 'unsupported_type';

export interface UnresolvableDependency {
    parameter: string;
    type: string | null;
    reason: UnresolvableReason;
}

export interface Agent {
    slug: string;
    name: string;
    class: string;
    provider: string | null;
    model: string | null;
    model_tier: ModelTier;
    tools: AgentTool[];
    capabilities: AgentCapabilities;
    available: boolean;
    error: string | null;
    /** 'binding' = the container could not wire it; 'exception' = something else threw. */
    error_kind: 'binding' | 'exception' | null;
    /** Constructor parameters the container could not resolve (empty when the failure was something else). */
    unresolvable: UnresolvableDependency[];
}
