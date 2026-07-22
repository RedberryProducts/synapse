<?php

use Redberry\Synapse\Models\SynapseConversation;

it('prunes conversations older than the retention window', function () {
    $old = SynapseConversation::create(['agent_class' => 'A', 'title' => 'old']);
    // Bypass timestamp management to backdate the row.
    SynapseConversation::whereKey($old->id)->update(['updated_at' => now()->subDays(30)]);

    $new = SynapseConversation::create(['agent_class' => 'A', 'title' => 'new']);

    $this->artisan('synapse:prune', ['--days' => 7])->assertSuccessful();

    expect(SynapseConversation::find($old->id))->toBeNull();
    expect(SynapseConversation::find($new->id))->not->toBeNull();
});

it('clears all conversations', function () {
    SynapseConversation::create(['agent_class' => 'A', 'title' => 'x']);
    SynapseConversation::create(['agent_class' => 'A', 'title' => 'y']);

    $this->artisan('synapse:clear')->assertSuccessful();

    expect(SynapseConversation::count())->toBe(0);
});

it('cascades deletion to messages and tool invocations', function () {
    $conversation = SynapseConversation::create(['agent_class' => 'A', 'title' => 'x']);
    $conversation->messages()->create([
        'role' => 'user',
        'content' => 'hi',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
        'metadata' => [],
    ]);
    $conversation->toolInvocations()->create([
        'invocation_id' => 'inv-1',
        'tool_invocation_id' => 'ti-1',
        'type' => 'tool',
        'name' => 'searchProducts',
        'arguments' => [],
        'status' => 'success',
    ]);

    $this->artisan('synapse:clear')->assertSuccessful();

    expect(Redberry\Synapse\Models\SynapseMessage::count())->toBe(0);
    expect(Redberry\Synapse\Models\SynapseToolInvocation::count())->toBe(0);
});
