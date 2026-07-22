<?php

use Illuminate\Support\Facades\Schema;
use Redberry\Synapse\Models\SynapseConversation;

it('creates the synapse tables', function () {
    expect(Schema::hasTable('synapse_conversations'))->toBeTrue();
    expect(Schema::hasTable('synapse_messages'))->toBeTrue();
    expect(Schema::hasTable('synapse_tool_invocations'))->toBeTrue();
});

it('persists a conversation with messages and tool invocations', function () {
    $conversation = SynapseConversation::create([
        'agent_class' => 'Workbench\\App\\Agents\\SupportAgent',
        'title' => 'Wireless headphones under $200',
    ]);

    $message = $conversation->messages()->create([
        'role' => 'assistant',
        'content' => 'Here are some options',
        'attachments' => [],
        'tool_calls' => [['id' => 't1', 'name' => 'searchProducts']],
        'tool_results' => [],
        'usage' => ['prompt_tokens' => 340, 'completion_tokens' => 128],
        'meta' => ['provider' => 'openai', 'model' => 'gpt-5.6-luna'],
        'metadata' => [],
        'prompt_tokens' => 340,
        'completion_tokens' => 128,
    ]);

    $conversation->toolInvocations()->create([
        'invocation_id' => 'inv-1',
        'tool_invocation_id' => 'ti-1',
        'type' => 'tool',
        'name' => 'searchProducts',
        'arguments' => ['query' => 'headphones'],
        'result' => ['ok' => true],
        'status' => 'success',
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    expect($conversation->messages)->toHaveCount(1);
    expect($conversation->toolInvocations)->toHaveCount(1);

    // uuid7 string keys + JSON array casts round-trip correctly.
    expect($conversation->id)->toBeString();
    expect($message->tool_calls)->toBeArray();
    expect($message->tool_calls[0]['name'])->toBe('searchProducts');
    expect($message->usage['completion_tokens'])->toBe(128);
});
