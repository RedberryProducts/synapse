<?php

/*
| Browser (end-to-end) smoke tests.
|
| These drive a real browser against the dashboard to verify the parts no PHP
| test can reach: that the compiled React app actually mounts, renders, and
| routes. Run with `composer test:e2e` (requires `npm run build` + Playwright).
*/

it('mounts the react app and renders the sidebar shell', function () {
    visit('/synapse')
        ->assertSee('Synapse')
        ->assertSee('Discovery')
        ->assertSee('History')
        ->assertNoJavaScriptErrors();
});

it('renders the discovery page as the index route', function () {
    visit('/synapse')
        ->assertSee('Agents')
        ->assertSee('Click a card to open the chat playground.');
});

it('navigates to history via the sidebar without a full page load', function () {
    visit('/synapse')
        ->click('History')
        ->assertPathIs('/synapse/history')
        ->assertSee('Past conversations will appear here.')
        ->assertNoJavaScriptErrors();
});

it('serves a deep-linked route through the spa catch-all', function () {
    visit('/synapse/history')
        ->assertSee('History')
        ->assertNoJavaScriptErrors();
});
