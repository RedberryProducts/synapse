<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('synapse.storage.connection') ?: config('database.default');
    }

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
