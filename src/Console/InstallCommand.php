<?php

namespace Redberry\Synapse\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
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

    protected function registerSynapseServiceProvider(): void
    {
        $path = app_path('Providers/AppServiceProvider.php');

        if (! File::exists($path)) {
            throw new RuntimeException(
                'Unable to register Synapse: app/Providers/AppServiceProvider.php was not found. Recreate it or register SynapseServiceProvider manually.'
            );
        }

        $contents = File::get($path);

        if (! str_contains($contents, '$this->app->register(SynapseServiceProvider::class)')) {
            $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";

            $updatedContents = preg_replace(
                '/^(\s*public\s+function\s+register\s*\(\s*\)\s*(?::\s*void)?\s*(?:\{\R|\R\s*\{\R))/m',
                '$1'.implode($eol, [
                    "        if (\$this->app->environment('local') &&",
                    '            class_exists(\\Redberry\\Synapse\\SynapseApplicationServiceProvider::class)) {',
                    '            $this->app->register(SynapseServiceProvider::class);',
                    '        }',
                    '',
                    '',
                ]),
                $contents,
                1,
            );

            if ($updatedContents === null || $updatedContents === $contents) {
                throw new RuntimeException(
                    'Unable to register Synapse: App\\Providers\\AppServiceProvider::register() was not found.'
                );
            }

            File::put($path, $updatedContents);
        }

        ServiceProvider::removeProviderFromBootstrapFile(
            'App\\Providers\\SynapseServiceProvider',
            strict: true,
        );
    }
}
