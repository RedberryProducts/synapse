<?php

use Illuminate\Support\Facades\DB;
use Redberry\Synapse\Models\SynapseConversation;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Models\SynapseToolInvocation;
use Redberry\Synapse\Synapse;

/*
| Every dashboard read must cost a fixed number of queries.
|
| These assert *shape*, not a magic number: each endpoint is measured against a
| small dataset and a large one, and the two counts must match. A hardcoded
| budget passes an N+1 that happens to be small on the day it was written; a
| comparison across sizes cannot.
*/

beforeEach(function () {
    Synapse::auth(fn (): bool => true);

    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
});

/**
 * Conversations with messages and tool rows, written straight to the tables.
 *
 * The chat pipeline is the honest way to create these, but it costs a faked
 * agent run per conversation — far too slow at the sizes an N+1 needs to become
 * visible.
 */
function seedConversations(int $conversations, int $messagesEach = 4, int $toolsEach = 3): void
{
    for ($c = 0; $c < $conversations; $c++) {
        $conversation = SynapseConversation::query()->create([
            'agent_class' => 'Workbench\\App\\Agents\\SupportAgent',
            'title' => "Conversation {$c}",
        ]);

        for ($m = 0; $m < $messagesEach; $m++) {
            $message = SynapseMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => $m % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message {$m} of conversation {$c}",
                'prompt_tokens' => 10,
                'completion_tokens' => 20,
                'attachments' => [],
                'tool_calls' => [],
                'tool_results' => [],
                'usage' => [],
                'meta' => ['provider' => 'openai', 'model' => 'gpt-5.6-luna'],
                'metadata' => [],
            ]);

            for ($t = 0; $t < $toolsEach; $t++) {
                SynapseToolInvocation::query()->create([
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                    'invocation_id' => "inv_{$c}_{$m}",
                    'tool_invocation_id' => "call_{$c}_{$m}_{$t}",
                    'type' => 'tool',
                    'name' => 'SearchProductsTool',
                    'arguments' => ['query' => 'hoodie'],
                    'result' => ['found' => 3],
                    'status' => 'success',
                    'started_at' => now(),
                    'finished_at' => now(),
                ]);
            }
        }
    }
}

/** Queries issued while running the callback. */
function queriesFor(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $count = count(DB::getQueryLog());

    DB::disableQueryLog();

    return $count;
}

it('costs the same number of queries however long the history is', function () {
    seedConversations(3);
    $small = queriesFor(fn () => test()->getJson('/synapse/api/conversations')->assertOk());

    seedConversations(25);
    $large = queriesFor(fn () => test()->getJson('/synapse/api/conversations')->assertOk());

    expect($large)->toBe($small);
});

it('costs the same number of queries however many messages a conversation has', function () {
    seedConversations(1, messagesEach: 2, toolsEach: 1);
    $short = SynapseConversation::query()->sole();
    $small = queriesFor(fn () => test()->getJson("/synapse/api/conversations/{$short->id}")->assertOk());

    SynapseConversation::query()->delete();

    seedConversations(1, messagesEach: 30, toolsEach: 5);
    $long = SynapseConversation::query()->sole();
    $large = queriesFor(fn () => test()->getJson("/synapse/api/conversations/{$long->id}")->assertOk());

    // Replay reads the conversation, its messages and its tool rows — three
    // reads, whether the thread is two messages or thirty. Attachments are a
    // cast on the row the message already loaded, not a lookup.
    expect($large)->toBe($small);
});

it('costs the same number of queries however many filter options exist', function () {
    seedConversations(3);
    $small = queriesFor(
        fn () => test()->getJson('/synapse/api/conversations?status=success&search=Conversation')->assertOk()
    );

    seedConversations(25);
    $large = queriesFor(
        fn () => test()->getJson('/synapse/api/conversations?status=success&search=Conversation')->assertOk()
    );

    expect($large)->toBe($small);
});

it('keeps the whole dashboard read within a small fixed budget', function () {
    // The comparisons above catch growth; this catches a fixed cost that has
    // quietly crept up. Deliberately loose — it should fail on a new join, not
    // on a refactor.
    seedConversations(25);

    expect(queriesFor(fn () => test()->getJson('/synapse/api/conversations')->assertOk()))
        ->toBeLessThanOrEqual(10);

    $conversation = SynapseConversation::query()->first();

    expect(queriesFor(fn () => test()->getJson("/synapse/api/conversations/{$conversation->id}")->assertOk()))
        ->toBeLessThanOrEqual(10);
});
