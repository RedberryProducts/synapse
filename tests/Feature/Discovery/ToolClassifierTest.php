<?php

use Redberry\Synapse\Discovery\ToolClassifier;
use Workbench\App\Agents\ExtractorAgent;
use Workbench\App\Agents\KitchenSinkAgent;
use Workbench\App\Agents\ResearchAgent;
use Workbench\App\Agents\SupportAgent;

it('classifies a user-defined tool', function () {
    $tools = (new ToolClassifier)->classify(new SupportAgent);

    expect($tools)->toHaveCount(1);
    expect($tools[0])->toBe(['name' => 'SearchProductsTool', 'type' => 'tool']);
});

it('classifies a provider tool without touching description or schema', function () {
    // ProviderTool implements only HasProviderOptions — calling description()
    // or schema() on it is a fatal error, so this must stay a pure type check.
    $tools = (new ToolClassifier)->classify(new ResearchAgent);

    expect($tools)->toBe([['name' => 'WebSearch', 'type' => 'provider_tool']]);
});

it('classifies a sub-agent used as a tool', function () {
    $tools = collect((new ToolClassifier)->classify(new KitchenSinkAgent));

    expect($tools->firstWhere('type', 'agent'))->toBe(['name' => 'WeatherAgent', 'type' => 'agent']);
});

it('returns an empty list for agents without tools', function () {
    expect((new ToolClassifier)->classify(new ExtractorAgent))->toBe([]);
});

it('classifies every entry of a mixed tool list', function () {
    $tools = (new ToolClassifier)->classify(new KitchenSinkAgent);

    expect($tools)->toHaveCount(7);
    expect(collect($tools)->pluck('type')->unique()->sort()->values()->all())
        ->toBe(['agent', 'provider_tool', 'tool']);
});
