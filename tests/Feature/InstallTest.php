<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Redberry\Synapse\Synapse;

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
 * The bootstrap entry is worse than stale: `addProviderToBootstrapFile()`
 * registers `App\Providers\SynapseServiceProvider` permanently, while the class
 * file itself is deleted here — leaving the skeleton pointing at a class that
 * does not exist, for every other test in the repository.
 */
function forgetPublishedFiles(): void
{
    File::delete([
        config_path('synapse.php'),
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

beforeEach(fn () => forgetPublishedFiles());
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

it('registers the provider once however many times it is run', function () {
    $this->artisan('synapse:install', ['--no-migrate' => true])->assertSuccessful();
    $this->artisan('synapse:install', ['--no-migrate' => true])->assertSuccessful();

    $bootstrap = base_path('bootstrap/providers.php');

    expect(substr_count(File::get($bootstrap), 'App\\Providers\\SynapseServiceProvider'))->toBe(1);
})->skip(
    // A closure, because `base_path()` needs a booted app and a bare argument
    // is evaluated while the file is still being collected.
    fn (): bool => ! file_exists(base_path('bootstrap/providers.php')),
    'The skeleton has no bootstrap/providers.php to register into.',
);

it('reports itself in php artisan about', function () {
    $about = synapseAbout();

    expect($about)->toHaveKeys(['version', 'enabled', 'path', 'agents_discovered', 'retention'])
        ->and($about['version'])->toBe(Synapse::VERSION)
        ->and($about['path'])->toBe('/synapse');
});

it('does not claim a streaming answer it cannot have', function () {
    // `about` always runs on the CLI SAPI, where Synapse::streams() is false by
    // definition. Reporting it would tell every developer their dashboard
    // cannot stream — including everyone whose dashboard streams perfectly.
    expect(synapseAbout())->not->toHaveKey('streaming');
});
