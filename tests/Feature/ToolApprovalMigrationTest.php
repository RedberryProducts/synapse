<?php

use Illuminate\Support\Facades\Schema;
use Redberry\Synapse\Models\SynapseConversation;
use Redberry\Synapse\Models\SynapseMessage;

it('adds approval storage without changing existing history', function () {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_27_000001_add_tool_approvals_to_synapse_messages.php';
    $migration->down();
    $conversation = SynapseConversation::create(['agent_class' => 'ExistingAgent', 'title' => 'Keep history']);
    $message = SynapseMessage::create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => 'Existing answer', 'status' => 'paused', 'attachments' => [], 'steps' => [], 'parts' => [], 'usage' => [], 'meta' => [], 'metadata' => []]);
    $migration->up();

    expect($message->fresh()->content)->toBe('Existing answer')
        ->and($message->fresh()->approval)->toBeNull()
        ->and($message->fresh()->approval_state)->toBeNull()
        ->and(Schema::hasColumns('synapse_messages', ['approval', 'approval_state']))->toBeTrue();
});
