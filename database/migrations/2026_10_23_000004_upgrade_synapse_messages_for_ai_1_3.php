<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureEmpty();

        Schema::connection($this->getConnection())->table('synapse_messages', function (Blueprint $table): void {
            $table->dropColumn(['tool_calls', 'tool_results']);
            $table->renameColumn('prompt_tokens', 'input_tokens');
            $table->renameColumn('completion_tokens', 'output_tokens');
            $table->text('steps')->nullable();
            $table->text('parts')->nullable();
            $table->string('status', 25)->default('completed');
        });
    }

    public function down(): void
    {
        $this->ensureEmpty();

        Schema::connection($this->getConnection())->table('synapse_messages', function (Blueprint $table): void {
            $table->dropColumn(['steps', 'parts', 'status']);
            $table->renameColumn('input_tokens', 'prompt_tokens');
            $table->renameColumn('output_tokens', 'completion_tokens');
            $table->text('tool_calls');
            $table->text('tool_results');
        });
    }

    public function getConnection(): ?string
    {
        return config('synapse.storage.connection') ?: config('database.default');
    }

    protected function ensureEmpty(): void
    {
        foreach (['synapse_conversations', 'synapse_messages', 'synapse_tool_invocations'] as $table) {
            if (DB::connection($this->getConnection())->table($table)->exists()) {
                throw new RuntimeException(
                    'Synapse storage format has changed. Back up any history you need, stop Synapse traffic, '
                    .'then explicitly run php artisan synapse:clear on the existing installation before migrating '
                    .'or rolling back. This removes Synapse history and its stored attachments only. '
                    .'All three synapse_* tables must be empty; no data has been changed by this migration.',
                );
            }
        }
    }
};
