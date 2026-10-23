<?php

namespace Redberry\Synapse\Chat;

use Closure;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\CacheToolDefinitions;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\PendingStep;

class ForwardCacheOptions
{
    public function __construct(
        protected ?CacheInstructions $instructions,
        protected ?CacheToolDefinitions $tools,
    ) {}

    public function handle(PendingStep $step, Closure $next): StepResult
    {
        // Cache attributes have no method fallback in the SDK. Preserve the
        // current step's other options, including its released tool choice.
        $options = new TextGenerationOptions(
            maxSteps: $step->options?->maxSteps,
            maxTokens: $step->options?->maxTokens,
            temperature: $step->options?->temperature,
            agent: $step->options?->agent,
            topP: $step->options?->topP,
            toolChoice: $step->options?->toolChoice,
            cacheInstructions: $this->instructions,
            cacheToolDefinitions: $this->tools,
            providerOptions: $step->options?->providerOptions,
        );

        return $next(new PendingStep(
            number: $step->number,
            isFinalStep: $step->isFinalStep,
            provider: $step->provider,
            model: $step->model,
            instructions: $step->instructions,
            messages: $step->messages,
            tools: $step->tools,
            schema: $step->schema,
            options: $options,
            steps: $step->steps,
            usage: $step->usage,
            timeout: $step->timeout,
            invocationId: $step->invocationId,
        ));
    }
}
