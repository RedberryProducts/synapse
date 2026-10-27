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
            $table->string('approval_state')->nullable();
            $table->text('approval')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('synapse_messages', function (Blueprint $table): void {
            $table->dropColumn(['approval_state', 'approval']);
        });
    }
};
