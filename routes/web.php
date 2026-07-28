<?php

use Illuminate\Support\Facades\Route;
use Redberry\Synapse\Http\Controllers\AgentsController;
use Redberry\Synapse\Http\Controllers\HomeController;

/*
|--------------------------------------------------------------------------
| Synapse Routes
|--------------------------------------------------------------------------
|
| Registered inside a group that applies the configured path/middleware plus
| the Synapse authorization gate (see SynapseServiceProvider).
|
*/

/*
| JSON API — the React app talks only to these endpoints.
|
| Stubbed for the skeleton so the SPA boots. Each is replaced by a dedicated
| controller as its feature lands (see PRD → HTTP API surface).
*/
Route::prefix('api')->group(function (): void {
    // Feature 1 — Agent discovery
    Route::get('/agents', [AgentsController::class, 'index']);
    Route::get('/agents/{agent}', [AgentsController::class, 'show']);

    // Feature 2 — Chat playground (SSE)
    Route::post('/chat/{agent}/send', fn (string $agent) => response()->noContent());

    // Attachments
    Route::get('/attachments/{message}/{index}', fn (string $message, int $index) => response()->noContent());

    // Feature 5 — History
    Route::get('/conversations', fn () => response()->json(['data' => []]));
    Route::get('/conversations/{id}', fn (string $id) => response()->json(null));
    Route::patch('/conversations/{id}', fn (string $id) => response()->noContent());
    Route::delete('/conversations/{id}', fn (string $id) => response()->noContent());
    Route::post('/conversations/clear', fn () => response()->noContent());
});

/*
| SPA catch-all — every non-API path returns the dashboard shell so React
| Router can own client-side navigation and deep links / refreshes work.
*/
Route::get('/{view?}', HomeController::class)
    ->where('view', '(.*)')
    ->name('synapse.index');
