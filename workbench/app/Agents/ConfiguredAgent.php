<?php

namespace Workbench\App\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Attributes\TopP;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\ToolChoice;
use Stringable;
use Workbench\App\Middleware\PiiRedactor;
use Workbench\App\Tools\BrokenSchemaTool;
use Workbench\App\Tools\SearchProductsTool;

/**
 * Exercises every field the Info panel's Config tab can show, plus a tool whose
 * schema throws.
 *
 * An **inspection** fixture, not a chat one. Setting every option at once is the
 * point, and a provider is entitled to reject some of them — `gpt-5.6-luna`
 * rejects `temperature`, for instance. Sending it a message against a real
 * provider is expected to produce an error card, which is itself a fair test of
 * the error path; use SupportAgent when you want a conversation.
 */
#[Provider('openai')]
#[Model('gpt-5.6-luna')]
#[Temperature(0.7)]
#[MaxTokens(2048)]
#[MaxSteps(4)]
#[TopP(0.9)]
#[Timeout(45)]
#[Strict]
#[ToolChoice(ToolChoice::required)]
class ConfiguredAgent implements Agent, HasMiddleware, HasProviderOptions, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return "# Configured agent\n\nEvery generation option is set explicitly.";
    }

    public function tools(): iterable
    {
        return [
            new SearchProductsTool,
            new BrokenSchemaTool,
        ];
    }

    public function middleware(): array
    {
        return [PiiRedactor::class];
    }

    public function providerOptions(Lab|string $provider): array
    {
        return ['reasoning_effort' => 'high'];
    }
}
