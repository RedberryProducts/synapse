<?php

namespace Redberry\Synapse\Tests;

use Illuminate\Support\Facades\File;
use Redberry\Synapse\Synapse;

/**
 * Base class for browser (end-to-end) tests.
 *
 * The browser plugin boots the Testbench application in-process and serves
 * static files from public_path(), so the compiled dashboard assets must be
 * published there before a page is visited.
 */
abstract class BrowserTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishAssets();

        // The testing environment is not "local", so open the gate explicitly.
        Synapse::auth(fn (): bool => true);
    }

    /**
     * Copy the compiled assets into the test application's public directory.
     */
    protected function publishAssets(): void
    {
        $dist = dirname(__DIR__).'/dist';

        if (! File::isDirectory($dist) || ! File::exists($dist.'/.vite/manifest.json')) {
            $this->markTestSkipped('Synapse assets are not built. Run `npm run build` first.');
        }

        File::ensureDirectoryExists(public_path('vendor/synapse'));
        File::copyDirectory($dist, public_path('vendor/synapse'));
    }
}
