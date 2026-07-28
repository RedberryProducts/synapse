<?php

beforeEach(function () {
    config(['synapse.discovery.paths' => [dirname(__DIR__, 2).'/workbench/app/Agents']]);
});

it('opens the info panel from an agent card', function () {
    visit('/synapse')
        ->click('Info')
        ->assertPathContains('/playground/')
        ->assertSee('Config')
        ->assertSee('Provider')
        ->assertNoJavaScriptErrors();
});

it('opens directly from a deep link with the panel already showing', function () {
    visit('/synapse/playground/workbench.app.agents.configured-agent?info=1')
        ->assertSee('ConfiguredAgent')
        ->assertSee('PROVIDER')
        ->assertSee('GENERATION')
        ->assertNoJavaScriptErrors();
});

it('shows every generation setting the agent declares', function () {
    visit('/synapse/playground/workbench.app.agents.configured-agent?info=config')
        ->assertSee('Temperature')
        ->assertSee('0.7')
        ->assertSee('Max_Tokens')
        ->assertSee('Top_P')
        ->assertSee('Tool_Choice')
        ->assertSee('required')
        ->assertSee('45s')
        ->assertSee('Enabled');
});

it('lists provider options and middleware', function () {
    visit('/synapse/playground/workbench.app.agents.configured-agent?info=config')
        ->assertSee('reasoning_effort')
        ->assertSee('PiiRedactor');
});

it('renders the system prompt as markdown', function () {
    visit('/synapse/playground/workbench.app.agents.configured-agent?info=prompt')
        ->assertSee('Configured agent')
        ->assertSee('Every generation option is set explicitly')
        ->assertNoJavaScriptErrors();
});

it('shows tool parameters with their types', function () {
    visit('/synapse/playground/workbench.app.agents.support-agent?info=tools')
        ->assertSee('SEARCHPRODUCTSTOOL')
        ->assertSee('query')
        ->assertSee('String')
        ->assertSee('Search query text');
});

it('marks a provider tool and a sub-agent tool distinctly', function () {
    visit('/synapse/playground/workbench.app.agents.kitchen-sink-agent?info=tools')
        ->assertSee('Provider tool')
        ->assertSee('Agent tool')
        ->assertSee('Inspect WeatherAgent');
});

it('degrades a tool whose schema cannot be built', function () {
    visit('/synapse/playground/workbench.app.agents.configured-agent?info=tools')
        ->assertSee('Schema unavailable')
        // The healthy tool alongside it still renders.
        ->assertSee('Search query text');
});

it('shows the output schema for a structured-output agent', function () {
    visit('/synapse/playground/workbench.app.agents.extractor-agent?info=tools')
        ->assertSee('OUTPUT SCHEMA')
        ->assertSee('email');
});

it('shows an empty state when the agent has no tools', function () {
    visit('/synapse/playground/workbench.app.agents.hidden-agent?info=tools')
        ->assertSee('This agent has no tools');
});

it('closes the panel and reopens it from the header', function () {
    // The panel toggles are icon-only, so target them by their accessible name
    // (an explicit attribute selector — Pest's text guessing can't match these).
    $page = visit('/synapse/playground/workbench.app.agents.support-agent?info=1');

    $page->assertSee('Config');

    // Element assertions auto-wait for the re-render; a text assertion would
    // race React here.
    $page->click('[aria-label="Close info panel"]');
    $page->assertMissing('[aria-label="Close info panel"]');

    $page->click('[aria-label="Open info panel"]');
    $page->assertPresent('[aria-label="Close info panel"]');
    $page->assertSee('Config')->assertNoJavaScriptErrors();
});

it('explains an unavailable agent inside the panel', function () {
    visit('/synapse/playground/workbench.app.agents.broken-agent?info=1')
        ->assertSee('construct this agent')
        ->assertSee('$this->app->bind(');
});

it('shows a not-found state for an unknown agent', function () {
    visit('/synapse/playground/nope.not.here')
        ->assertSee('Agent not found')
        ->assertNoJavaScriptErrors();
});
