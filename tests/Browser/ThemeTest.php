<?php

it('switches the theme from the sidebar switcher', function () {
    $page = visit('/synapse');

    // The trigger shows the current theme; default is System.
    $page->click('System')
        ->click('Dark');

    expect($page->script('document.documentElement.className'))->toContain('dark');
    expect($page->script("localStorage.getItem('synapse-theme')"))->toBe('dark');
});

it('restores the stored theme on reload', function () {
    // Each visit() gets a fresh browser context, so reload in place to keep
    // the stored preference — this exercises the layout's inline script.
    $page = visit('/synapse');
    $page->script("localStorage.setItem('synapse-theme', 'dark')");
    $page->refresh();

    expect($page->script('document.documentElement.className'))->toContain('dark');
});

it('applies the light theme when chosen', function () {
    $page = visit('/synapse');

    $page->click('System')->click('Light');

    expect($page->script('document.documentElement.className'))->not->toContain('dark');
    expect($page->script("localStorage.getItem('synapse-theme')"))->toBe('light');
});
