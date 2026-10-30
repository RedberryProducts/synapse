<?php

use Illuminate\Support\Facades\Route;
use Redberry\Synapse\Synapse;

Route::get('/runtime-info', fn (): array => [
    'sapi' => PHP_SAPI,
    'php' => PHP_VERSION,
    'pid' => getmypid(),
    'streaming' => Synapse::streams(),
    'buffering' => ini_get('output_buffering'),
    'framework' => app()->version(),
]);
