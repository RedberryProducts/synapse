<?php

use Redberry\Synapse\Synapse;

beforeEach(function () {
    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
    Synapse::auth(fn (): bool => true);
});

it('returns the documented detail payload', function () {
    $payload = $this->getJson('/synapse/api/agents/workbench.app.agents.support-agent')
        ->assertOk()
        ->json();

    expect($payload)->toHaveKeys([
        'slug', 'name', 'class', 'provider', 'model', 'model_tier', 'capabilities',
        'available', 'instructions', 'generation', 'provider_options',
        'middleware', 'tools', 'output_schema',
    ]);

    expect($payload['generation'])->toHaveKeys([
        'temperature', 'max_tokens', 'max_steps', 'top_p', 'timeout', 'strict', 'tool_choice',
    ]);
});

it('returns 404 for an unknown agent', function () {
    $this->getJson('/synapse/api/agents/nope.not.here')->assertNotFound();
});

it('forbids the endpoint when the gate denies', function () {
    Synapse::auth(fn (): bool => false);

    $this->getJson('/synapse/api/agents/workbench.app.agents.support-agent')->assertForbidden();
});
