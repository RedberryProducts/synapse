<?php

use Redberry\Synapse\Chat\StreamEmitter;
use Redberry\Synapse\Synapse;

/*
| Why this file exists.
|
| Synapse streamed nothing for six epics and nobody noticed. The emitter guarded
| its flush with `headers_sent()`, which is false under the stock php.ini
| (`output_buffering=4096`): the first echo lands in PHP's own buffer, so the
| headers are never sent, so the guard answers "no" — for every part, for the
| whole run. Measured against a real server, time-to-first-byte equalled total
| time: the dashboard waited out the agent and then painted everything at once.
|
| Nothing in the suite could catch it. The test harness and the browser driver
| both run Laravel in-process and read the body back out of their own output
| buffer, so no test ever exercises a real SAPI. That is exactly why the decision
| lives in a method that takes the SAPI as data.
*/

it('flushes on a web SAPI even though the headers are not sent yet', function () {
    // The regression in one line: under fpm the headers have not been sent when
    // the first part is written, and it must flush anyway.
    expect(headers_sent())->toBeFalse()
        ->and((new StreamEmitter(sapi: 'fpm-fcgi'))->shouldFlush())->toBeTrue();
});

it('flushes under every SAPI that serves HTTP', function () {
    foreach (['fpm-fcgi', 'cli-server', 'apache2handler', 'litespeed', 'frankenphp'] as $sapi) {
        expect((new StreamEmitter(sapi: $sapi))->shouldFlush())->toBeTrue("SAPI {$sapi}");
    }
});

it('never flushes under the CLI, where the caller owns the output buffer', function () {
    // A test harness calls `sendContent()` inside its own `ob_start()` and reads
    // the body with `ob_get_clean()`. Flushing there pushes the bytes past that
    // buffer to stdout and hands the caller an empty response.
    foreach (['cli', 'phpdbg', 'embed'] as $sapi) {
        expect((new StreamEmitter(sapi: $sapi))->shouldFlush())->toBeFalse("SAPI {$sapi}");
    }
});

it('defaults to the running SAPI', function () {
    expect((new StreamEmitter)->shouldFlush())->toBe(PHP_SAPI !== 'cli');
});

it('tells the dashboard the same thing it tells the emitter', function () {
    // Two answers to one question is how a UI ends up claiming a run streams
    // while the wire says otherwise. `Synapse::streams()` must be the emitter's
    // own rule, not a second copy of the SAPI list.
    expect(Synapse::streams())->toBe(StreamEmitter::flushesUnder(PHP_SAPI))
        ->and(Synapse::scriptVariables()['streaming'])->toBe(Synapse::streams());
});

it('reports no streaming under the test runner', function () {
    // The suite runs on the CLI SAPI, so this is also what an Octane worker
    // reports — which is why the dashboard carries a notice rather than
    // assuming every deployment streams.
    expect(Synapse::streams())->toBeFalse();
})->skip(PHP_SAPI !== 'cli', 'Only meaningful under the CLI SAPI.');

it('still captures every part when flushing is off', function () {
    $emitter = new StreamEmitter(echo: false, sapi: 'cli');

    $emitter->text('Hello.', 'msg_1');

    // Skipping the flush must never skip the write.
    expect(array_column(
        array_map(fn (string $part): array => (array) json_decode($part, true), $emitter->written()),
        'type'
    ))->toBe(['start', 'text-start', 'text-delta', 'text-end']);
});
