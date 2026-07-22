<?php

use Redberry\Synapse\Synapse;

it('serves the dashboard shell when authorized', function () {
    Synapse::auth(fn () => true);

    $this->get('/synapse')
        ->assertOk()
        ->assertSee('id="synapse"', false)
        ->assertSee('window.Synapse', false);
});

it('forbids access when the gate denies', function () {
    Synapse::auth(fn () => false);

    $this->get('/synapse')->assertForbidden();
});

it('registers the synapse artisan commands', function () {
    $commands = array_keys(Illuminate\Support\Facades\Artisan::all());

    expect($commands)
        ->toContain('synapse:install')
        ->toContain('synapse:prune')
        ->toContain('synapse:clear');
});
