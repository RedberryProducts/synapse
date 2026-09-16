<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
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
        app_path('Providers/SynapseServiceProvider.php'),
    ]);

    foreach (File::files(dirname(__DIR__, 2).'/database/migrations') as $migrationFile) {
        File::delete(database_path('migrations/'.$migrationFile->getFilename()));
    }

    $bootstrap = base_path('bootstrap/providers.php');

    if (File::exists($bootstrap)) {
        File::put($bootstrap, str_replace(
            "    App\\Providers\\SynapseServiceProvider::class,\n",
            '',
            File::get($bootstrap),
        ));
    }
}

function createApplicationProvider(?string $contents = null): void
{
    $provider = app_path('Providers/AppServiceProvider.php');

    File::ensureDirectoryExists(dirname($provider));
    File::put($provider, $contents ?? <<<'PHP'
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
afterEach(function () {
    forgetPublishedFiles();
    createApplicationProvider();
});

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

it('rejects an existing registration without the complete guards', function (string $registration, string $imports = '') {
    $path = app_path('Providers/AppServiceProvider.php');
    $contents = str_replace('        //', $registration, File::get($path));
    $contents = str_replace('namespace App\\Providers;', 'namespace App\\Providers;'.$imports, $contents);
    File::put($path, $contents);
    ServiceProvider::addProviderToBootstrapFile('App\\Providers\\SynapseServiceProvider');
    $bootstrap = File::get(base_path('bootstrap/providers.php'));

    expect(fn () => $this->artisan('synapse:install', ['--no-migrate' => true]))
        ->toThrow(RuntimeException::class, 'Remove the existing Synapse registration from AppServiceProvider::register()');

    expect(File::get($path))->toBe($contents)
        ->and(File::get(base_path('bootstrap/providers.php')))->toBe($bootstrap);
})->with([
    'unguarded call' => '        $this->app->register(SynapseServiceProvider::class);',
    'commented call' => '        // $this->app->register(SynapseServiceProvider::class);',
    'fully qualified class' => '        $this->app->register(\\App\\Providers\\SynapseServiceProvider::class);',
    'whitespace around the call' => '        $this -> app -> register ( SynapseServiceProvider :: class );',
    'case-insensitive class name' => '        $this->app->register(\\App\\Providers\\synapseserviceprovider::class);',
    'class name string' => "        \$this->app->register('App\\\\Providers\\\\SynapseServiceProvider');",
    'imported alias' => [
        '        $this->app->register(DashboardProvider::class);',
        "\nuse App\\Providers\\SynapseServiceProvider as DashboardProvider;\n",
    ],
    'grouped import alias' => [
        '        $this->app->register(DashboardProvider::class);',
        "\nuse App\\Providers\\{SynapseServiceProvider as DashboardProvider};\n",
    ],
    'local guard without class check' => <<<'PHP'
        if ($this->app->environment('local')) {
            $this->app->register(SynapseServiceProvider::class);
        }
PHP,
    'commented guarded block' => <<<'PHP'
        /*
        if ($this->app->environment('local') &&
            class_exists(\Redberry\Synapse\SynapseApplicationServiceProvider::class)) {
            $this->app->register(SynapseServiceProvider::class);
        }
        */
PHP,
]);

it('preserves unrelated provider registrations', function () {
    $path = app_path('Providers/AppServiceProvider.php');
    $registration = '        $this->app->register(\\App\\Providers\\BillingServiceProvider::class);';
    File::put($path, str_replace('        //', $registration, File::get($path)));

    $this->artisan('synapse:install', ['--no-migrate' => true])->assertSuccessful();
    $this->artisan('synapse:install', ['--no-migrate' => true])->assertSuccessful();

    expect(File::get($path))->toContain($registration);
});

it('preserves the bootstrap file when no legacy provider is registered', function () {
    $path = base_path('bootstrap/providers.php');
    $original = File::get($path);
    $contents = <<<'PHP'
<?php

return [
    // Provider order is intentional.
    App\Providers\ZebraServiceProvider::class,
    App\Providers\AppServiceProvider::class,
];
PHP;
    File::put($path, $contents);

    try {
        $this->artisan('synapse:install', ['--no-migrate' => true])->assertSuccessful();
        $this->artisan('synapse:install', ['--no-migrate' => true])->assertSuccessful();

        expect(File::get($path))->toBe($contents);
    } finally {
        File::put($path, $original);
    }
});

it('recognizes the generated block after whitespace formatting', function () {
    $this->artisan('synapse:install', ['--no-migrate' => true])->assertSuccessful();
    $path = app_path('Providers/AppServiceProvider.php');
    $formatted = str_replace(['    ', "\n"], ["\t", "\r\n"], File::get($path));
    File::put($path, $formatted);

    $this->artisan('synapse:install', ['--no-migrate' => true])->assertSuccessful();

    expect(File::get($path))->toBe($formatted);
});

it('preserves statements added before the generated block', function (string $statement) {
    $this->artisan('synapse:install', ['--no-migrate' => true])->assertSuccessful();
    $path = app_path('Providers/AppServiceProvider.php');
    $contents = str_replace("public function register(): void\n    {", "public function register(): void\n    {\n".$statement, File::get($path));
    File::put($path, $contents);

    $this->artisan('synapse:install', ['--no-migrate' => true])->assertSuccessful();

    expect(File::get($path))->toBe($contents);
})->with([
    'binding' => "        \$this->app->bind('example', fn () => 'value');",
    'closure binding' => <<<'PHP'
        $this->app->singleton('example', function () {
            return new \stdClass;
        });
PHP,
    'interpolated string' => <<<'PHP'
        $example = "{$this->app['env']}";
PHP,
]);

it('does not accept a generated block outside executable register code', function (string $location) {
    $this->artisan('synapse:install', ['--no-migrate' => true])->assertSuccessful();
    $path = app_path('Providers/AppServiceProvider.php');
    $installed = File::get($path);
    $block = <<<'PHP'
        if ($this->app->environment('local') &&
            class_exists(\Redberry\Synapse\SynapseApplicationServiceProvider::class)) {
            $this->app->register(SynapseServiceProvider::class);
        }
PHP;
    $replacement = match ($location) {
        'nowdoc' => "        \$example = <<<'EXAMPLE'\n".$block."\nEXAMPLE;",
        'string' => '        $example = "'.$block.'";',
        'closure' => "        \$example = function () {\n".$block."\n        };",
        'boot' => '',
    };
    $contents = str_replace($block, $replacement, $installed);

    if ($location === 'boot') {
        $contents = str_replace("public function boot(): void\n    {", "public function boot(): void\n    {\n".$block, $contents);
    }

    File::put($path, $contents);
    ServiceProvider::addProviderToBootstrapFile('App\\Providers\\SynapseServiceProvider');
    $bootstrap = File::get(base_path('bootstrap/providers.php'));

    expect(fn () => $this->artisan('synapse:install', ['--no-migrate' => true]))
        ->toThrow(RuntimeException::class, 'Remove the existing Synapse registration');

    expect(File::get($path))->toBe($contents)
        ->and(File::get(base_path('bootstrap/providers.php')))->toBe($bootstrap);
})->with(['nowdoc', 'string', 'closure', 'boot']);

it('rejects an additional unguarded registration beside the generated block', function () {
    $this->artisan('synapse:install', ['--no-migrate' => true])->assertSuccessful();

    $path = app_path('Providers/AppServiceProvider.php');
    $contents = str_replace('        //', '        $this->app->register(\\App\\Providers\\SynapseServiceProvider::class);', File::get($path));
    File::put($path, $contents);

    expect(fn () => $this->artisan('synapse:install', ['--no-migrate' => true]))
        ->toThrow(RuntimeException::class, 'Remove the existing Synapse registration');

    expect(File::get($path))->toBe($contents);
});

it('fails clearly when the application provider file is missing', function () {
    File::delete(app_path('Providers/AppServiceProvider.php'));

    expect(fn () => $this->artisan('synapse:install', ['--no-migrate' => true]))
        ->toThrow(RuntimeException::class, 'Unable to register Synapse: app/Providers/AppServiceProvider.php was not found.');
});

it('registers the provider when the host app uses a valid register signature variant', function () {
    createApplicationProvider(<<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register() {
        //
    }

    public function boot(): void
    {
        //
    }
}
PHP);

    $this->artisan('synapse:install', ['--no-migrate' => true])->assertSuccessful();

    expect(File::get(app_path('Providers/AppServiceProvider.php')))
        ->toContain('$this->app->register(SynapseServiceProvider::class)');
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

it('removes the published provider registration before uninstalling', function () {
    $this->artisan('synapse:install', ['--no-migrate' => true])->assertSuccessful();
    ServiceProvider::addProviderToBootstrapFile('App\\Providers\\SynapseServiceProvider');

    Event::dispatch('composer_package.redberry/synapse:pre_uninstall');

    expect(File::get(base_path('bootstrap/providers.php')))
        ->not->toContain('App\\Providers\\SynapseServiceProvider');
})->skip(
    fn (): bool => ! file_exists(base_path('bootstrap/providers.php')),
    'The skeleton has no bootstrap/providers.php to unregister from.',
);

it('publishes resources that remain loadable without Synapse classes', function () {
    $this->artisan('synapse:install', ['--no-migrate' => true])->assertSuccessful();

    $migrationFiles = File::files(database_path('migrations'));
    $publishedProvider = File::get(app_path('Providers/SynapseServiceProvider.php'));

    expect($migrationFiles)->toHaveCount(3)
        ->and($publishedProvider)->toContain('extends ServiceProvider')
        ->and($publishedProvider)->not->toContain('extends SynapseApplicationServiceProvider')
        ->and($publishedProvider)->toContain('in_array($user?->email')
        ->and($publishedProvider)->toContain("Gate::forUser(\$request->user())->check('viewSynapse')");

    foreach ($migrationFiles as $migrationFile) {
        expect($migrationFile->getContents())
            ->toContain('extends Migration')
            ->not->toContain('Redberry\\Synapse\\Migrations');
    }
});

it('keeps the configured connection in self-contained migrations', function () {
    config()->set('synapse.storage.connection', 'synapse-testing');

    foreach (File::files(dirname(__DIR__, 2).'/database/migrations') as $migrationFile) {
        $migration = require $migrationFile->getPathname();

        expect($migration->getConnection())->toBe('synapse-testing');
    }
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
