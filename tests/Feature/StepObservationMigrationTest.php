<?php

use Redberry\Synapse\Models\SynapseConversation;
use Redberry\Synapse\Models\SynapseMessage;

it('adds step observations without changing existing history', function () {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_29_000001_add_step_observations_to_synapse_messages.php';
    $migration->down();
    $conversation = SynapseConversation::create(['agent_class' => 'ExistingAgent', 'title' => 'Keep history']);
    $message = SynapseMessage::create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'attachments' => [], 'parts' => [], 'meta' => [], 'metadata' => [], 'content' => 'Existing answer', 'steps' => [['text' => 'Existing answer']], 'usage' => ['input_tokens' => 5]]);
    $before = $message->fresh()->toArray();
    $migration->up();
    expect($message->fresh()->toArray())->toBe([...$before, 'step_observations' => null]);
});
