<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Redberry\Synapse\Synapse;
use Symfony\Component\Process\Process;

/*
| `synapse:install` must be safe to run twice.
|
| Developers re-run it after a `composer update`, or because they are not sure
| whether it took the first time. The published `SynapseServiceProvider` is
| where the `viewSynapse` gate lives, so silently overwriting it would not be an
| inconvenience — it would hand back the default deny-everyone stub and wipe
| whoever the developer had allowed.
*/

/**
 * Undo everything `synapse:install` writes.
 *
 * These land in the Testbench skeleton under `vendor/`, which persists between
 * runs. Left behind, the published config would make the *next* run start with
 * a customised file already in place and the idempotency assertion would pass
 * without ever publishing anything.
 *
 * The bootstrap cleanup also isolates the legacy-registration migration test
 * from every other test in the repository.
 */
function forgetPublishedFiles(): void
{
    File::delete([
        config_path('synapse.php'),
        app_path('Providers/AppServiceProvider.php'),
        app_path('Providers/SynapseServiceProvider.php'),
    ]);

    $bootstrap = base_path('bootstrap/providers.php');

    if (File::exists($bootstrap)) {
        File::put($bootstrap, str_replace(
            "    App\\Providers\\SynapseServiceProvider::class,\n",
            '',
            File::get($bootstrap),
        ));
    }
}

function createApplicationProvider(): void
{
    $provider = app_path('Providers/AppServiceProvider.php');

    File::ensureDirectoryExists(dirname($provider));
    File::put($provider, <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
PHP);
}

/**
 * Run `about --json` for Synapse and decode it.
 *
 * `-v` is not decoration. Symfony's `configureIO()` reads `SHELL_VERBOSITY` on
 * every command run, and `composer test --quiet` — which the pre-commit hook
 * uses — exports `SHELL_VERBOSITY=-1`. Every console assertion then compares
 * against an empty string, so these tests passed locally and failed in the
 * hook. An explicit verbosity on the output buffer does *not* help; only an
 * input option outranks the environment, because `configureIO()` applies the
 * env first and input options after. The JSON payload is identical either way —
 * `-v` only lifts a suppression the test runner imposed.
 *
 * @return array<string, string>
 */
function synapseAbout(): array
{
    Artisan::call('about', ['--only' => 'synapse', '--json' => true, '-v' => true]);

    return json_decode(Artisan::output(), true)['synapse'] ?? [];
}

beforeEach(function () {
    forgetPublishedFiles();
    createApplicationProvider();
});
afterEach(fn () => forgetPublishedFiles());

it('does not overwrite a customised config or an edited provider', function () {
    $config = config_path('synapse.php');
    $provider = app_path('Providers/SynapseServiceProvider.php');

    File::ensureDirectoryExists(dirname($provider));

    $this->artisan('synapse:install', ['--no-migrate' => true])->assertSuccessful();

    expect($config)->toBeReadableFile()
        ->and($provider)->toBeReadableFile();

    // Stand in for a developer's edits: a changed dashboard path, and a gate
    // that actually allows somebody.
    File::put($config, str_replace("'synapse')", "'ai-dashboard')", File::get($config)));
    File::put($provider, str_replace(
        '//',
        "'ada@example.com',",
        File::get($provider),
    ));

    $customisedConfig = File::get($config);
    $customisedProvider = File::get($provider);

    $this->artisan('synapse:install', ['--no-migrate' => true])->assertSuccessful();

    expect(File::get($config))->toBe($customisedConfig)
        ->and(File::get($provider))->toBe($customisedProvider)
        ->and(File::get($provider))->toContain('ada@example.com');
});

it('registers the provider locally once however many times it is run', function () {
    $this->artisan('synapse:install', ['--no-migrate' => true])->assertSuccessful();

    $path = app_path('Providers/AppServiceProvider.php');
    File::put($path, str_replace('//', '// Keep this application binding.', File::get($path)));

    $this->artisan('synapse:install', ['--no-migrate' => true])->assertSuccessful();

    $appServiceProvider = File::get($path);

    expect(substr_count($appServiceProvider, "environment('local')"))->toBe(1)
        ->and(substr_count($appServiceProvider, 'SynapseApplicationServiceProvider::class'))->toBe(1)
        ->and(substr_count($appServiceProvider, '$this->app->register(SynapseServiceProvider::class)'))->toBe(1)
        ->and($appServiceProvider)->toContain('// Keep this application binding.')
        ->and(File::get(base_path('bootstrap/providers.php')))
        ->not->toContain('App\\Providers\\SynapseServiceProvider');
});

it('removes the old unconditional provider registration', function () {
    ServiceProvider::addProviderToBootstrapFile('App\\Providers\\SynapseServiceProvider');

    $this->artisan('synapse:install', ['--no-migrate' => true])->assertSuccessful();

    expect(File::get(base_path('bootstrap/providers.php')))
        ->not->toContain('App\\Providers\\SynapseServiceProvider');
});

it('boots the generated application provider without Synapse installed', function () {
    $this->artisan('synapse:install', ['--no-migrate' => true])->assertSuccessful();

    expect(File::get(app_path('Providers/AppServiceProvider.php')))
        ->toContain('class_exists(\\Redberry\\Synapse\\SynapseApplicationServiceProvider::class)');

    $provider = var_export(app_path('Providers/AppServiceProvider.php'), true);
    $script = <<<PHP
namespace Illuminate\Support {
    class ServiceProvider {
        public function __construct(protected object \$app) {}
    }
}

namespace {
    require {$provider};

    \$app = new class {
        public function environment(string \$environment): bool
        {
            return \$environment === 'local';
        }

        public function register(string \$provider): void
        {
            throw new RuntimeException("Unexpected provider registration: {\$provider}");
        }
    };

    (new App\Providers\AppServiceProvider(\$app))->register();
    echo 'booted';
}
PHP;
    $process = new Process([PHP_BINARY, '-r', $script]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getOutput())->toBe('booted')
        ->and($process->getErrorOutput())->toBeEmpty();
});

it('reports itself in php artisan about', function () {
    $about = synapseAbout();

    expect($about)->toHaveKeys(['version', 'enabled', 'path', 'agents_discovered', 'retention'])
        ->and($about['version'])->toBe(Synapse::version())
        ->and($about['path'])->toBe('/synapse');
});

it('does not claim a streaming answer it cannot have', function () {
    // `about` always runs on the CLI SAPI, where Synapse::streams() is false by
    // definition. Reporting it would tell every developer their dashboard
    // cannot stream — including everyone whose dashboard streams perfectly.
    expect(synapseAbout())->not->toHaveKey('streaming');
});

it('reports the version Composer actually installed', function () {
    // A hardcoded constant drifts the moment a release is tagged without
    // bumping it — v0.1.1 shipped reporting "0.1.0" in `about`, the sidebar
    // footer and window.Synapse. Deriving it from Composer's runtime metadata
    // makes that impossible; this asserts the two cannot diverge again.
    expect(Synapse::version())->toBe(Synapse::scriptVariables()['version'])
        ->and(synapseAbout()['version'])->toBe(Synapse::version())
        ->and(Synapse::version())->not->toBeEmpty();
});
