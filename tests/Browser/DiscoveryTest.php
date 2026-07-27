<?php

beforeEach(function () {
    config(['synapse.discovery.paths' => [dirname(__DIR__, 2).'/workbench/app/Agents']]);
});

it('renders a card for each discovered agent', function () {
    visit('/synapse')
        ->assertSee('SupportAgent')
        ->assertSee('WeatherAgent')
        ->assertSee('gpt-5.6-luna')
        ->assertNoJavaScriptErrors();
});

it('shows the agent count in the header and sidebar footer', function () {
    visit('/synapse')
        ->assertSee('Agents')
        ->assertSee('agents');
});

it('shows tool chips and collapses overflow into a +N chip', function () {
    visit('/synapse')
        ->assertSee('SearchProductsTool')
        ->assertSee('+ 4');
});

it('explains why an agent cannot be instantiated and how to fix it', function () {
    visit('/synapse')
        ->assertSee('BrokenAgent')
        ->assertSee('construct this agent')
        ->assertSee('an interface with no binding')
        ->assertSee('$this->app->bind(');
});

it('opens the playground when a card is clicked', function () {
    visit('/synapse')
        ->click('WeatherAgent')
        ->assertPathContains('/playground/')
        ->assertNoJavaScriptErrors();
});

it('lists agents in the sidebar', function () {
    visit('/synapse')
        ->assertSee('KITCHENSINKAGENT');
});

it('renders an empty state when no agents are found', function () {
    config(['synapse.discovery.paths' => [dirname(__DIR__, 2).'/workbench/app/Tools']]);

    visit('/synapse')
        ->assertSee('No agents found')
        ->assertNoJavaScriptErrors();
});
