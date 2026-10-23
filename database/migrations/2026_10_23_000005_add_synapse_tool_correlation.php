<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('synapse.storage.connection') ?: config('database.default');
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table('synapse_tool_invocations', function (Blueprint $table): void {
            $table->string('tool_invocation_id')->nullable()->change();
            $table->string('tool_call_id')->nullable();
            $table->string('provider')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::connection($this->getConnection())->table('synapse_tool_invocations')->exists()) {
            throw new RuntimeException('Back up Synapse history and run php artisan synapse:clear before rolling back tool correlation storage.');
        }

        Schema::connection($this->getConnection())->table('synapse_tool_invocations', function (Blueprint $table): void {
            $table->dropColumn(['tool_call_id', 'provider']);
            $table->string('tool_invocation_id')->nullable(false)->change();
        });
    }
};
