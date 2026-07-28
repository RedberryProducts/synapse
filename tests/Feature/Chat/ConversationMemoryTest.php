<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Redberry\Synapse\Chat\MessageHistory;
use Redberry\Synapse\Chat\SynapseConversationalAgent;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Synapse;
use Workbench\App\Agents\SupportAgent;
use Workbench\App\Agents\WeatherAgent;

/*
| The conversational/stateless split is the playground's central honesty claim:
| a stateless agent must behave in Synapse exactly as it does in production, so
| the chat UI can never imply memory the agent does not have.
|
| These tests assert it at the SDK's own injection point. `StreamsText::stream()`
| builds its message list with precisely this expression:
|
|     $messages = $agent instanceof Conversational ? $agent->messages() : [];
|
| so capturing the same thing off the dispatched event is a faithful reading of
| what the provider was actually handed, not a proxy for it.
*/

beforeEach(function () {
    Synapse::auth(fn (): bool => true);

    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
});

/**
 * Record, per invocation, exactly what the SDK will hand the provider as
 * conversation history.
 *
 * @param  array<int, array<int, Message>>  $captured
 */
function watchHistory(array &$captured): void
{
    Event::listen(StreamingAgent::class, function (StreamingAgent $event) use (&$captured): void {
        $agent = $event->prompt->agent;

        $captured[] = $agent instanceof Conversational ? [...$agent->messages()] : [];
    });
}

it('feeds prior turns back to a conversational agent', function () {
    fakeAgent(SupportAgent::class, ['First answer.', 'Second answer.']);

    $captured = [];
    watchHistory($captured);

    $conversationId = chatConversationId(
        sendMessage('workbench.app.agents.support-agent', 'What is your return policy?')
    );

    sendMessage('workbench.app.agents.support-agent', 'And for sale items?', $conversationId);

    expect($captured[0])->toBe([]);

    // Turn two carries turn one: the question and the answer.
    expect($captured[1])->toHaveCount(2)
        ->and($captured[1][0]->content)->toBe('What is your return policy?')
        ->and($captured[1][1]->content)->toBe('First answer.');
});

it('sends a stateless agent only the current message', function () {
    fakeAgent(WeatherAgent::class, ['Sunny.', 'Still sunny.']);

    $captured = [];
    watchHistory($captured);

    $conversationId = chatConversationId(
        sendMessage('workbench.app.agents.weather-agent', 'Weather in Tbilisi?')
    );

    sendMessage('workbench.app.agents.weather-agent', 'And tomorrow?', $conversationId);

    // Both turns are stored and shown as one thread, but the agent itself never
    // sees the earlier turn — this is what the "Stateless" notice explains.
    expect($captured[0])->toBe([])
        ->and($captured[1])->toBe([]);
});

it('never wraps a stateless agent', function () {
    fakeAgent(WeatherAgent::class, ['Clear skies.']);

    $wrapped = null;
    Event::listen(StreamingAgent::class, function (StreamingAgent $event) use (&$wrapped): void {
        $wrapped = $event->prompt->agent instanceof SynapseConversationalAgent;
    });

    sendMessage('workbench.app.agents.weather-agent', 'Weather?');

    expect($wrapped)->toBeFalse();
});

it('replays a tool turn back into the SDK message sequence', function () {
    fakeAgent(SupportAgent::class, ['Found three matches.', 'Here they are again.']);

    $conversationId = chatConversationId(
        sendMessage('workbench.app.agents.support-agent', 'Find me a hoodie')
    );

    // A completed tool turn stored on the assistant row rehydrates into the
    // SDK's own assistant → tool-result → assistant sequence.
    SynapseMessage::query()
        ->where('conversation_id', $conversationId)
        ->where('role', 'assistant')
        ->update([
            'tool_calls' => json_encode([[
                'id' => 'call_1',
                'name' => 'search_products',
                'arguments' => ['query' => 'hoodie'],
            ]]),
            'tool_results' => json_encode([[
                'id' => 'call_1',
                'name' => 'search_products',
                'arguments' => ['query' => 'hoodie'],
                'result' => '3 matches',
            ]]),
        ]);

    $history = app(MessageHistory::class)->for($conversationId);

    expect($history)->toHaveCount(4)
        ->and($history[1])->toBeInstanceOf(AssistantMessage::class)
        ->and($history[2])->toBeInstanceOf(ToolResultMessage::class)
        ->and($history[3]->content)->toBe('Found three matches.');
});

it('leaves error rows out of the history the agent sees', function () {
    fakeAgent(SupportAgent::class, [
        fn () => throw new RuntimeException('Provider exploded'),
        'Recovered.',
    ]);

    $conversationId = chatConversationId(
        sendMessage('workbench.app.agents.support-agent', 'This one fails')
    );

    $history = app(MessageHistory::class)->for($conversationId);

    // The user turn is there; the error card is a Synapse observation, not context.
    expect($history)->toHaveCount(1)
        ->and($history[0]->content)->toBe('This one fails');
});
