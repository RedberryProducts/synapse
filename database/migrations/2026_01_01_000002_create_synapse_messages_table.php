<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Redberry\Synapse\Migrations\SynapseMigration;

return new class extends SynapseMigration
{
    public function up(): void
    {
        Schema::create('synapse_messages', function (Blueprint $table) {
            $table->string('id', 36)->primary();            // uuid7
            $table->string('conversation_id', 36)->index();
            $table->string('role', 25);                     // user | assistant | error
            $table->text('content')->nullable();            // message text, or error message
            $table->text('attachments');                    // JSON — SDK File serialization
            $table->text('tool_calls');                     // JSON — ToolCall::toArray() shapes
            $table->text('tool_results');                   // JSON — ToolResult::toArray() shapes
            $table->text('usage');                          // JSON — full Usage::toArray()
            $table->unsignedInteger('prompt_tokens')->nullable();     // promoted for SQL aggregates
            $table->unsignedInteger('completion_tokens')->nullable(); // promoted for SQL aggregates
            $table->unsignedInteger('duration_ms')->nullable();       // response wall time
            $table->text('meta');                           // JSON — provider, model, citations
            $table->text('metadata');                       // JSON — Synapse-specific (exception_class, stack_trace)
            $table->timestamp('created_at');

            $table->index(['conversation_id', 'id']);       // uuid7 ⇒ id-sorted = chronological
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('synapse_messages');
    }
};
