<?php

namespace Redberry\Synapse\Tests\Fixtures;

use Closure;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\StructuredTextResponse;
use Workbench\App\Agents\ExtractorAgent;
use Workbench\App\Tools\SearchProductsTool;

#[MaxSteps(3)]
class StructuredFailoverAgent extends ExtractorAgent implements HasTools
{
    public function provider(): array
    {
        return ['openai' => 'primary', 'anthropic' => 'fallback'];
    }

    public function tools(): iterable
    {
        return [new SearchProductsTool];
    }

    public static function responses(): Closure
    {
        $calls = [];

        return function (string $prompt, $attachments, TextProvider $provider, string $model) use (&$calls): ToolCall|StructuredTextResponse {
            $name = $provider->name();
            $calls[$name] = ($calls[$name] ?? 0) + 1;
            if ($calls[$name] === 1) {
                return new ToolCall('search', 'SearchProductsTool', ['query' => 'hoodie']);
            }
            if ($name === 'openai') {
                throw RateLimitedException::forProvider('openai');
            }

            return new StructuredTextResponse(['name' => 'Ada'], '{"name":"Ada"}', new TextUsage(20, 4), new Meta($name, $model));
        };
    }
}
