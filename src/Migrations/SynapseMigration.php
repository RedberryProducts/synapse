<?php

namespace Redberry\Synapse\Migrations;

use Illuminate\Database\Migrations\Migration;

abstract class SynapseMigration extends Migration
{
    /**
     * Run Synapse migrations on the configured connection (app default when null).
     */
    public function getConnection(): ?string
    {
        return config('synapse.storage.connection') ?: config('database.default');
    }
}
