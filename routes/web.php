<?php

use Illuminate\Support\Facades\Route;
use Redberry\Synapse\Http\Controllers\AgentsController;
use Redberry\Synapse\Http\Controllers\AttachmentsController;
use Redberry\Synapse\Http\Controllers\ChatController;
use Redberry\Synapse\Http\Controllers\ConversationsController;
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
    Route::post('/chat/{agent}/send', [ChatController::class, 'send']);

    // Attachments
    Route::get('/attachments/{message}/{index}', [AttachmentsController::class, 'show']);

    // Feature 5 — History
    Route::get('/conversations', [ConversationsController::class, 'index']);
    Route::get('/conversations/{id}', [ConversationsController::class, 'show']);
    Route::patch('/conversations/{id}', [ConversationsController::class, 'update']);
    Route::delete('/conversations/{id}', [ConversationsController::class, 'destroy']);
    // No clear-all route: `synapse:clear` does that, and the dashboard has no
    // control for it. A route that returned 204 and did nothing used to live
    // here — worse than no route at all.
});

/*
| SPA catch-all — every non-API path returns the dashboard shell so React
| Router can own client-side navigation and deep links / refreshes work.
*/
Route::get('/{view?}', HomeController::class)
    ->where('view', '(.*)')
    ->name('synapse.index');
