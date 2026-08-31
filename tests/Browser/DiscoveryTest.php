<?php

/*
| Targeting uses data-testid (`@name`); content is still asserted as real text,
| scoped to the element it belongs to. See AGENTS.md → Browser tests.
*/

beforeEach(function () {
    config(['synapse.discovery.paths' => [dirname(__DIR__, 2).'/workbench/app/Agents']]);
});

function expectNameToTruncate(object $page, string $selector, string $name): void
{
    $encodedSelector = json_encode($selector);

    expect($page->script("document.querySelector({$encodedSelector}).getAttribute('title')"))->toBe($name);

    expect($page->script(<<<JS
        (() => {
            const element = document.querySelector({$encodedSelector});

            return element.scrollWidth > element.clientWidth;
        })()
    JS))->toBeTrue();
}

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
        ->assertSeeIn(
            '[data-testid="agent-card-unavailable-name"][title="BrokenAgent"]',
            'BrokenAgent',
        )
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
    visit('/synapse')->assertSeeIn('@sidebar-agents', 'KITCHENSINKAGENT');
});

it('truncates long agent names in discovery cards and the sidebar at desktop width', function () {
    $page = visit('/synapse')->resize(1440, 1100);

    expectNameToTruncate(
        $page,
        '[data-testid="agent-card-name"][title="ExpenditureDetailsRelatedNotesAnalyzer"]',
        'ExpenditureDetailsRelatedNotesAnalyzer',
    );

    expectNameToTruncate(
        $page,
        '[data-testid="agent-card-unavailable-name"][title="IncomeProtectionRecommendationDataCollectorBrokenAgent"]',
        'IncomeProtectionRecommendationDataCollectorBrokenAgent',
    );

    expectNameToTruncate(
        $page,
        '[data-testid="sidebar-agent-name"][title="ExpenditureDetailsRelatedNotesAnalyzer"]',
        'ExpenditureDetailsRelatedNotesAnalyzer',
    );

    $page->assertNoJavaScriptErrors();
});

it('keeps long agent names clipped inside constrained mobile-width layouts', function () {
    $page = visit('/synapse')->resize(420, 900);

    expectNameToTruncate(
        $page,
        '[data-testid="agent-card-name"][title="ExpenditureDetailsRelatedNotesAnalyzer"]',
        'ExpenditureDetailsRelatedNotesAnalyzer',
    );

    expectNameToTruncate(
        $page,
        '[data-testid="agent-card-unavailable-name"][title="IncomeProtectionRecommendationDataCollectorBrokenAgent"]',
        'IncomeProtectionRecommendationDataCollectorBrokenAgent',
    );

    expectNameToTruncate(
        $page,
        '[data-testid="sidebar-agent-name"][title="ExpenditureDetailsRelatedNotesAnalyzer"]',
        'ExpenditureDetailsRelatedNotesAnalyzer',
    );

    $page->assertNoJavaScriptErrors();
});

it('renders an empty state when no agents are found', function () {
    config(['synapse.discovery.paths' => [dirname(__DIR__, 2).'/workbench/app/Tools']]);

    visit('/synapse')
        ->assertSeeIn('@empty-state', 'No agents found')
        ->assertNoJavaScriptErrors();
});
