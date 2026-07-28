<?php

/*
| Targeting uses data-testid (`@name`); content is still asserted as real text,
| scoped to the element it belongs to. See AGENTS.md → Browser tests.
*/

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
        ->assertPresent('@sidebar-agents');
});

it('shows tool chips and collapses overflow into a +N chip', function () {
    visit('/synapse')
        ->assertSee('SearchProductsTool')
        ->assertPresent('@tool-overflow');
});

it('explains why an agent cannot be instantiated and how to fix it', function () {
    visit('/synapse')
        ->assertPresent('@agent-card-unavailable')
        ->assertSeeIn('@unresolvable-hint', 'an interface with no binding')
        ->assertSeeIn('@unresolvable-hint', '$this->app->bind(');
});

it('opens the playground when a card is clicked', function () {
    visit('/synapse')
        ->click('WeatherAgent')
        ->assertPathContains('/playground/')
        ->assertNoJavaScriptErrors();
});

it('lists agents in the sidebar', function () {
    visit('/synapse')->assertSeeIn('@sidebar-agents', 'KITCHENSINKAGENT');
});

it('renders an empty state when no agents are found', function () {
    config(['synapse.discovery.paths' => [dirname(__DIR__, 2).'/workbench/app/Tools']]);

    visit('/synapse')
        ->assertSeeIn('@empty-state', 'No agents found')
        ->assertNoJavaScriptErrors();
});
