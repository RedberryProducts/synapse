<?php

namespace Redberry\Synapse\Tests;

use Redberry\Synapse\Synapse;

/**
 * Base class for browser (end-to-end) tests.
 *
 * Dashboard assets are inlined straight from the package's dist/ directory, so
 * there is nothing to publish here — the tests only need a built dist/.
 */
abstract class BrowserTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! file_exists(dirname(__DIR__).'/dist/app.js')) {
            $this->markTestSkipped('Synapse assets are not built. Run `npm run build` first.');
        }

        // The testing environment is not "local", so open the gate explicitly.
        Synapse::auth(fn (): bool => true);
    }
}
