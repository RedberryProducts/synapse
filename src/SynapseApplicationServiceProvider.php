<?php

namespace Redberry\Synapse;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class SynapseApplicationServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->authorization();
    }

    /**
     * Configure the Synapse authorization services.
     */
    protected function authorization(): void
    {
        $this->gate();

        Synapse::auth(function ($request) {
            return Gate::check('viewSynapse', [$request->user()]);
        });
    }

    /**
     * Register the Synapse gate.
     *
     * This gate determines who can access Synapse in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewSynapse', function ($user) {
            return in_array($user->email, [
                //
            ]);
        });
    }
}
