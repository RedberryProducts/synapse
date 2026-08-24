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
        $contents = File::get($path);

        if (! str_contains($contents, '$this->app->register(SynapseServiceProvider::class)')) {
            $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";
            $registerMethod = "    public function register(): void{$eol}    {{$eol}";

            if (! str_contains($contents, $registerMethod)) {
                throw new RuntimeException(
                    'Unable to register Synapse: App\\Providers\\AppServiceProvider::register() was not found.'
                );
            }

            $registration = implode($eol, [
                "        if (\$this->app->environment('local') &&",
                '            class_exists(\\Redberry\\Synapse\\SynapseApplicationServiceProvider::class)) {',
                '            $this->app->register(SynapseServiceProvider::class);',
                '        }',
                '',
                '',
            ]);

            File::put($path, str_replace(
                $registerMethod,
                $registerMethod.$registration,
                $contents,
            ));
        }

        ServiceProvider::removeProviderFromBootstrapFile(
            'App\\Providers\\SynapseServiceProvider',
            strict: true,
        );
    }
}
