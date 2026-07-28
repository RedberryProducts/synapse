<?php

namespace Redberry\Synapse\Discovery;

use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Attributes\Timeout as TimeoutAttribute;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ToolChoice;
use ReflectionClass;

/**
 * The generation settings the SDK will actually use for an agent.
 *
 * Everything except the timeout comes from TextGenerationOptions::forAgent(),
 * the SDK's own resolver — never hand-rolled reflection. The timeout lives in
 * Promptable::getTimeout(), which is protected, so its precedence
 * (method → attribute → 60) is mirrored here.
 */
class GenerationOptions
{
    /**
     * @return array<string, mixed>
     */
    public function for(object $agent): array
    {
        $options = TextGenerationOptions::forAgent($agent);

        return [
            'temperature' => $options->temperature,
            'max_tokens' => $options->maxTokens,
            'max_steps' => $options->maxSteps,
            'top_p' => $options->topP,
            'timeout' => $this->timeout($agent),
            'strict' => Strict::isAppliedTo($agent),
            'tool_choice' => $this->toolChoice($options->toolChoice),
        ];
    }

    /**
     * Provider-specific options the agent declares, if any.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(object $agent, ?string $provider): array
    {
        if (! $agent instanceof HasProviderOptions || $provider === null) {
            return [];
        }

        return $agent->providerOptions($provider);
    }

    /**
     * Mirrors Promptable::getTimeout(): method, then attribute, then 60.
     */
    protected function timeout(object $agent): int
    {
        if (method_exists($agent, 'timeout')) {
            return (int) $agent->timeout();
        }

        $attributes = (new ReflectionClass($agent))->getAttributes(TimeoutAttribute::class);

        return $attributes === [] ? 60 : (int) $attributes[0]->newInstance()->value;
    }

    /**
     * @return array{mode: string, tool: string|null}|null
     */
    protected function toolChoice(?ToolChoice $choice): ?array
    {
        if (! $choice instanceof ToolChoice) {
            return null;
        }

        return ['mode' => $choice->mode, 'tool' => $choice->toolName];
    }
}
