<?php

use Redberry\Synapse\Synapse;

beforeEach(function () {
    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
    Synapse::auth(fn (): bool => true);
});

it('lists discovered agents', function () {
    $response = $this->getJson('/synapse/api/agents')->assertOk();

    expect(collect($response->json())->pluck('class'))
        ->toContain('Workbench\\App\\Agents\\SupportAgent');
});

it('returns the documented payload shape', function () {
    $agent = collect($this->getJson('/synapse/api/agents')->json())
        ->firstWhere('class', 'Workbench\\App\\Agents\\SupportAgent');

    expect($agent)->toHaveKeys([
        'slug', 'name', 'class', 'provider', 'model',
        'model_tier', 'tools', 'capabilities', 'available', 'error',
    ]);

    expect($agent['name'])->toBe('SupportAgent');
    expect($agent['tools'][0])->toHaveKeys(['name', 'type']);
    expect($agent['capabilities'])->toHaveKeys([
        'conversational', 'remembers_conversations', 'has_tools',
        'has_structured_output', 'has_middleware', 'can_act_as_tool',
    ]);
});

it('reports capabilities from the implemented interfaces', function () {
    $agents = collect($this->getJson('/synapse/api/agents')->json());

    expect($agents->firstWhere('class', 'Workbench\\App\\Agents\\SupportAgent')['capabilities'])
        ->toMatchArray(['conversational' => true, 'has_tools' => true]);

    expect($agents->firstWhere('class', 'Workbench\\App\\Agents\\WeatherAgent')['capabilities'])
        ->toMatchArray(['conversational' => false, 'has_tools' => true]);

    expect($agents->firstWhere('class', 'Workbench\\App\\Agents\\ExtractorAgent')['capabilities'])
        ->toMatchArray(['has_structured_output' => true]);
});

it('forbids the endpoint when the gate denies', function () {
    Synapse::auth(fn (): bool => false);

    $this->getJson('/synapse/api/agents')->assertForbidden();
});
