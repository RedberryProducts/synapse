<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Redberry\Synapse\Migrations\SynapseMigration;

return new class extends SynapseMigration
{
    public function up(): void
    {
        Schema::create('synapse_conversations', function (Blueprint $table) {
            $table->string('id', 36)->primary();   // uuid7 — k-sortable
            $table->string('agent_class')->index(); // FQCN
            $table->string('title');                // derived: truncated first user message
            $table->timestamps();

            $table->index('updated_at');            // history list ordering
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('synapse_conversations');
    }
};
