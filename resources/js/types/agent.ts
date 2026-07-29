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

/* ── Info panel detail (GET /api/agents/{slug}) ───────────────────────────── */

export interface ToolChoiceSetting {
    mode: 'auto' | 'none' | 'required' | 'tool';
    tool: string | null;
}

export interface GenerationSettings {
    temperature: number | null;
    max_tokens: number | null;
    max_steps: number | null;
    top_p: number | null;
    timeout: number;
    strict: boolean;
    tool_choice: ToolChoiceSetting | null;
}

export interface SchemaParameter {
    name: string;
    type: string;
    description: string | null;
    required: boolean;
}

export interface ToolDetail {
    name: string;
    type: ToolType;
    description: string | null;
    parameters: SchemaParameter[];
    provider_options: Record<string, unknown> | null;
    agent_slug: string | null;
    schema_error: string | null;
}

/** A model the playground can run this agent on, for one send. */
export interface ModelOption {
    id: string;
    label: string;
    /** `agent` is the agent's own configuration and always the default. */
    tier: 'agent' | 'cheapest' | 'smartest' | 'configured';
}

export interface AgentDetail extends Agent {
    models: ModelOption[];
    instructions: string | null;
    generation: GenerationSettings | null;
    provider_options: Record<string, unknown>;
    middleware: string[];
    tools: ToolDetail[];
    output_schema: SchemaParameter[] | null;
}
