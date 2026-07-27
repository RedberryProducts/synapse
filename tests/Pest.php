<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Redberry\Synapse\Tests\BrowserTestCase;
use Redberry\Synapse\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Unit');

// Browser end-to-end tests: `composer test:e2e` (excluded from `composer test` and CI).
uses(BrowserTestCase::class, RefreshDatabase::class)->group('e2e')->in('Browser');
