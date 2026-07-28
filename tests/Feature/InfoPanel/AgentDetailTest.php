<?php

use Redberry\Synapse\Discovery\AgentDetail;
use Redberry\Synapse\Discovery\AgentDiscovery;
use Workbench\App\Agents\BrokenAgent;
use Workbench\App\Agents\ConfiguredAgent;
use Workbench\App\Agents\ExtractorAgent;
use Workbench\App\Agents\KitchenSinkAgent;
use Workbench\App\Agents\ResearchAgent;
use Workbench\App\Agents\SupportAgent;
use Workbench\App\Agents\WeatherAgent;

beforeEach(function () {
    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
});

function detailFor(string $class): array
{
    $discovery = app(AgentDiscovery::class);
    $discovered = collect($discovery->all())->firstWhere('class', $class);

    return app(AgentDetail::class)->for($discovered);
}

it('resolves generation options through the sdk resolver', function () {
    $generation = detailFor(ConfiguredAgent::class)['generation'];

    expect($generation['temperature'])->toBe(0.7);
    expect($generation['max_tokens'])->toBe(2048);
    expect($generation['max_steps'])->toBe(4);
    expect($generation['top_p'])->toBe(0.9);
});

it('reads the timeout attribute and falls back to sixty', function () {
    expect(detailFor(ConfiguredAgent::class)['generation']['timeout'])->toBe(45);
    expect(detailFor(SupportAgent::class)['generation']['timeout'])->toBe(60);
});

it('detects the strict attribute', function () {
    expect(detailFor(ConfiguredAgent::class)['generation']['strict'])->toBeTrue();
    expect(detailFor(SupportAgent::class)['generation']['strict'])->toBeFalse();
});

it('reports the tool choice mode', function () {
    expect(detailFor(ConfiguredAgent::class)['generation']['tool_choice'])
        ->toBe(['mode' => 'required', 'tool' => null]);

    expect(detailFor(SupportAgent::class)['generation']['tool_choice'])->toBeNull();
});

it('returns the agent instructions', function () {
    expect(detailFor(SupportAgent::class)['instructions'])
        ->toContain('friendly customer support agent');
});

it('lists middleware class names', function () {
    expect(detailFor(ConfiguredAgent::class)['middleware'])
        ->toBe(['Workbench\\App\\Middleware\\PiiRedactor']);

    expect(detailFor(SupportAgent::class)['middleware'])->toBe([]);
});

it('returns provider options declared by the agent', function () {
    expect(detailFor(ConfiguredAgent::class)['provider_options'])
        ->toBe(['reasoning_effort' => 'high']);
});

it('describes a user tool with its parameters and required flags', function () {
    $tool = collect(detailFor(SupportAgent::class)['tools'])->firstWhere('name', 'SearchProductsTool');

    expect($tool['type'])->toBe('tool');
    expect($tool['description'])->toContain('Search the product catalog');

    $parameters = collect($tool['parameters']);

    expect($parameters->firstWhere('name', 'query'))->toBe([
        'name' => 'query',
        'type' => 'string',
        'description' => 'Search query text',
        'required' => true,
    ]);

    // Optional parameters must not be reported as required — this only works
    // because the schema goes through the SDK's ObjectSchema.
    expect($parameters->firstWhere('name', 'max_results')['required'])->toBeFalse();
});

it('describes a provider tool without calling description or schema', function () {
    $tool = collect(detailFor(ResearchAgent::class)['tools'])->firstWhere('type', 'provider_tool');

    expect($tool['name'])->toBe('WebSearch');
    expect($tool['description'])->toBeNull();
    expect($tool['parameters'])->toBe([]);
    expect($tool['provider_options'])->toBeArray();
});

it('links a sub-agent tool to its own slug', function () {
    $tool = collect(detailFor(KitchenSinkAgent::class)['tools'])->firstWhere('type', 'agent');

    expect($tool['name'])->toBe('WeatherAgent');
    expect($tool['agent_slug'])->toBe('workbench.app.agents.weather-agent');
    expect(app(AgentDiscovery::class)->find($tool['agent_slug'])->class)->toBe(WeatherAgent::class);
});

it('degrades a tool whose schema throws without breaking the payload', function () {
    $detail = detailFor(ConfiguredAgent::class);
    $broken = collect($detail['tools'])->firstWhere('name', 'BrokenSchemaTool');

    expect($broken['schema_error'])->toContain('Schema could not be built');

    // The other tool and the rest of the panel are unaffected.
    expect(collect($detail['tools'])->firstWhere('name', 'SearchProductsTool')['parameters'])->not->toBeEmpty();
    expect($detail['instructions'])->not->toBeNull();
});

it('returns the output schema only for structured-output agents', function () {
    $schema = detailFor(ExtractorAgent::class)['output_schema'];

    expect(collect($schema)->firstWhere('name', 'name'))
        ->toMatchArray(['type' => 'string', 'required' => true]);

    expect(detailFor(SupportAgent::class)['output_schema'])->toBeNull();
});

it('returns an empty detail for an unavailable agent', function () {
    $detail = detailFor(BrokenAgent::class);

    expect($detail['available'])->toBeFalse();
    expect($detail['error_kind'])->toBe('binding');
    expect($detail['generation'])->toBeNull();
    expect($detail['tools'])->toBe([]);
});
