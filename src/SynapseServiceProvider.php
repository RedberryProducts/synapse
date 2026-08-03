<?php

namespace Redberry\Synapse;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Redberry\Synapse\Discovery\AgentDiscovery;
use Redberry\Synapse\Http\Middleware\Authorize;

class SynapseServiceProvider extends ServiceProvider
{
    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/synapse.php', 'synapse');

        // Singleton = discovery runs once per request, never persistently cached.
        $this->app->singleton(AgentDiscovery::class);
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->registerRoutes();

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'synapse');

        $this->registerScheduledTasks();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
            $this->registerCommands();
            $this->registerAboutCommand();
        }
    }

    /**
     * Add a Synapse section to `php artisan about`.
     *
     * Every Laravel developer already knows where to look, which beats a
     * bespoke `synapse:doctor` nobody would think to run. These are the values
     * that explain a dashboard behaving unexpectedly: whether it is enabled at
     * all, where it is mounted, whether it found anything, and how long its
     * records live.
     *
     * Streaming support is deliberately **not** reported here. `about` always
     * runs on the CLI SAPI, where `Synapse::streams()` is false by definition —
     * the row would tell every user their dashboard cannot stream, including
     * the ones whose dashboard streams perfectly. The playground answers it in
     * the web request, where the question means something, and
     * `bin/check-streaming.sh` measures it.
     */
    protected function registerAboutCommand(): void
    {
        AboutCommand::add('Synapse', fn (): array => [
            'Version' => Synapse::version(),
            'Enabled' => config('synapse.enabled') ? 'true' : 'false',
            'Path' => '/'.trim((string) config('synapse.ui.path', 'synapse'), '/'),
            // Deferred: discovery instantiates every agent class, which is too
            // much to do for an unrelated `about` call. The closure only runs
            // when the section is rendered.
            'Agents discovered' => fn (): string => (string) count($this->app->make(AgentDiscovery::class)->all()),
            'Retention' => config('synapse.retention.auto_prune')
                ? sprintf('auto-prune after %s days', config('synapse.retention.days'))
                : 'kept until pruned manually',
        ]);
    }

    /**
     * Register the dashboard routes.
     */
    protected function registerRoutes(): void
    {
        if (! $this->shouldRegisterRoutes()) {
            return;
        }

        Route::group([
            'prefix' => config('synapse.ui.path', 'synapse'),
            'middleware' => array_merge(
                (array) config('synapse.ui.middleware', ['web']),
                [Authorize::class],
            ),
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }

    /**
     * Determine whether the dashboard routes should be registered.
     *
     * The production opt-in rule lives in the config default
     * (`SYNAPSE_ENABLED` defaults to false in production), so this stays
     * config-cache safe — no runtime env() calls.
     */
    protected function shouldRegisterRoutes(): bool
    {
        return (bool) config('synapse.enabled');
    }

    /**
     * Register a daily prune when automatic retention is enabled.
     */
    protected function registerScheduledTasks(): void
    {
        if (! config('synapse.retention.auto_prune')) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $days = (int) config('synapse.retention.days', 7);

            $schedule->command('synapse:prune', ['--days' => $days])->daily();
        });
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/synapse.php' => config_path('synapse.php'),
        ], 'synapse-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'synapse-migrations');

        // Note: dashboard assets are NOT publishable — they are inlined from the
        // package's dist/ directory (see Synapse::css()/js()), so an upgrade can
        // never leave stale assets behind in the host application.

        $this->publishes([
            __DIR__.'/../stubs/SynapseServiceProvider.stub' => app_path('Providers/SynapseServiceProvider.php'),
        ], 'synapse-provider');
    }

    /**
     * Register the package's Artisan commands.
     */
    protected function registerCommands(): void
    {
        $this->commands([
            Console\InstallCommand::class,
            Console\PruneCommand::class,
            Console\ClearCommand::class,
        ]);
    }
}
