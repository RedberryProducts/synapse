<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Synapse Master Switch
    |--------------------------------------------------------------------------
    |
    | This value determines whether Synapse is enabled. In production, Synapse
    | only registers its routes when this is explicitly set to true. In every
    | other environment it defaults to enabled.
    |
    */

    'enabled' => (bool) env('SYNAPSE_ENABLED', env('APP_ENV') !== 'production'),

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | The URI path and middleware for the Synapse dashboard. In non-local
    | environments every route is additionally protected by the "viewSynapse"
    | authorization gate defined in your SynapseServiceProvider.
    |
    */

    'ui' => [
        'path' => env('SYNAPSE_PATH', 'synapse'),
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Discovery
    |--------------------------------------------------------------------------
    |
    | Directories scanned for classes implementing the Laravel AI SDK's Agent
    | contract, plus any agent classes to hide from the dashboard.
    |
    */

    'discovery' => [
        'paths' => [app_path('Agents')],
        'ignore' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Playground
    |--------------------------------------------------------------------------
    |
    | Extra models offered in the composer's model selector, on top of each
    | agent's own model and its provider's cheapest / smartest tiers.
    |
    */

    'playground' => [
        'models' => [
            // 'anthropic/claude-sonnet-5',
            // 'openai/gpt-5',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | The database connection used for Synapse's tables (null = app default),
    | and the filesystem disk used for chat attachment uploads. Point the
    | connection at a dedicated store to isolate Synapse's data.
    |
    */

    'storage' => [
        'connection' => env('SYNAPSE_DB_CONNECTION', null),
        'attachments_disk' => env('SYNAPSE_ATTACHMENTS_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Retention
    |--------------------------------------------------------------------------
    |
    | When "auto_prune" is enabled, Synapse registers a daily scheduled
    | "synapse:prune" command that removes conversations older than "days".
    |
    */

    'retention' => [
        'auto_prune' => env('SYNAPSE_AUTO_PRUNE', false),
        'days' => env('SYNAPSE_PRUNE_DAYS', 7),
    ],

];
