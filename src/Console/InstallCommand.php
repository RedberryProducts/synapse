<?php

namespace Redberry\Synapse\Console;

use Illuminate\Console\Command;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'synapse:install')]
class InstallCommand extends Command
{
    protected $signature = 'synapse:install {--no-migrate : Skip running migrations}';

    protected $description = 'Install all of the Synapse resources';

    public function handle(): int
    {
        $this->components->info('Publishing Synapse resources...');

        $this->callSilent('vendor:publish', ['--tag' => 'synapse-config']);
        $this->callSilent('vendor:publish', ['--tag' => 'synapse-assets']);
        $this->callSilent('vendor:publish', ['--tag' => 'synapse-migrations']);
        $this->callSilent('vendor:publish', ['--tag' => 'synapse-provider']);

        $this->registerSynapseServiceProvider();

        if (! $this->option('no-migrate')) {
            $this->components->info('Running migrations...');
            $this->call('migrate');
        }

        $this->components->info('Synapse installed successfully.');

        return self::SUCCESS;
    }

    /**
     * Register the published SynapseServiceProvider in the application.
     */
    protected function registerSynapseServiceProvider(): void
    {
        ServiceProvider::addProviderToBootstrapFile('App\\Providers\\SynapseServiceProvider');
    }
}
