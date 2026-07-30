<?php

use Redberry\Synapse\Models\SynapseConversation;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Synapse;
use Workbench\App\Agents\SupportAgent;

beforeEach(function () {
    Synapse::auth(fn (): bool => true);

    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
});

it('renames a conversation and returns the updated row', function () {
    fakeAgent(SupportAgent::class, ['Answered.']);

    $id = chatConversationId(sendMessage('workbench.app.agents.support-agent', 'Find me a hoodie'));

    test()->patchJson("/synapse/api/conversations/{$id}", ['title' => 'Hoodie search, take three'])
        ->assertOk()
        ->assertJsonPath('title', 'Hoodie search, take three');

    expect(SynapseConversation::query()->find($id)->title)->toBe('Hoodie search, take three');
});

it('leaves the messages untouched', function () {
    fakeAgent(SupportAgent::class, ['Answered.']);

    $id = chatConversationId(sendMessage('workbench.app.agents.support-agent', 'Find me a hoodie'));

    test()->patchJson("/synapse/api/conversations/{$id}", ['title' => 'Renamed']);

    // The title is metadata about the conversation, not part of it.
    expect(SynapseMessage::query()->where('role', 'user')->sole()->content)
        ->toBe('Find me a hoodie');
});

it('requires a title', function () {
    fakeAgent(SupportAgent::class, ['Answered.']);

    $id = chatConversationId(sendMessage('workbench.app.agents.support-agent', 'Find me a hoodie'));

    test()->patchJson("/synapse/api/conversations/{$id}", ['title' => ''])->assertStatus(422);
    test()->patchJson("/synapse/api/conversations/{$id}", [])->assertStatus(422);
});

it('404s for a conversation that is not there', function () {
    test()->patchJson('/synapse/api/conversations/nope', ['title' => 'Renamed'])->assertNotFound();
});

it('keeps rename behind the gate', function () {
    Synapse::auth(fn (): bool => false);

    test()->patchJson('/synapse/api/conversations/anything', ['title' => 'Renamed'])
        ->assertForbidden();
});
