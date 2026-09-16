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
        Schema::create('synapse_tool_invocations', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('conversation_id', 36)->index();
            $table->string('message_id', 36)->nullable();   // linked to assistant row once the turn completes
            $table->string('invocation_id');                // agent invocation uuid from SDK events
            $table->string('tool_invocation_id')->index();  // from InvokingTool/ToolInvoked; matches stream ids
            $table->string('type', 25);                     // tool | provider_tool
            $table->string('name');                         // tool name, or provider tool type
            $table->text('arguments');                      // JSON
            $table->text('result')->nullable();             // JSON
            $table->string('status', 25);                   // pending | success | error
            $table->string('provider_status')->nullable();  // raw provider status, unnormalized (provider tools)
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();    // chronological card placement
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('synapse_tool_invocations');
    }
};
