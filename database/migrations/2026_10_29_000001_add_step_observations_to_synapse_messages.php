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
        Schema::connection($this->getConnection())->table('synapse_messages', function (Blueprint $table): void {
            $table->text('step_observations')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('synapse_messages', function (Blueprint $table): void {
            $table->dropColumn(['step_observations']);
        });
    }
};
