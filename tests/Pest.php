<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Ai;
use Redberry\Synapse\Chat\StrictSynapseConversationalAgent;
use Redberry\Synapse\Chat\SynapseConversationalAgent;
use Redberry\Synapse\Tests\BrowserTestCase;
use Redberry\Synapse\Tests\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Unit');

// Browser end-to-end tests: `composer test:e2e` (excluded from `composer test` and CI).
uses(BrowserTestCase::class, RefreshDatabase::class)->group('e2e')->in('Browser');

/**
 * Fake an agent's responses for the whole chat pipeline.
 *
 * `Ai::hasFakeGatewayFor()` keys on the concrete class name, and Synapse hands
 * the SDK a `SynapseConversationalAgent` wrapper for conversational agents — so
 * `SupportAgent::fake()` alone would leave the decorated call talking to a real
 * provider. Registering all three classes is what makes the fake stick
 * regardless of which path the invoker takes.
 *
 * @param  array<int, mixed>|Closure  $responses
 */
function fakeAgent(string $agent, array|Closure $responses = []): void
{
    foreach ([$agent, SynapseConversationalAgent::class, StrictSynapseConversationalAgent::class] as $class) {
        Ai::fakeAgent($class, $responses);
    }
}

/**
 * Post a message to an agent and run the stream to completion.
 *
 * A `StreamedResponse` does nothing until its body is consumed, so without the
 * `streamedContent()` call the agent would never be invoked and nothing would be
 * persisted. The result is cached, so `chatParts()` reuses it.
 */
function sendMessage(string $slug, string $message, ?string $conversationId = null): TestResponse
{
    $response = test()->post("/synapse/api/chat/{$slug}/send", array_filter([
        'message' => $message,
        'conversation_id' => $conversationId,
    ]));

    if ($response->baseResponse instanceof StreamedResponse) {
        $response->streamedContent();
    }

    return $response;
}

/**
 * Decode a streamed SSE response into protocol parts.
 *
 * @return list<array<string, mixed>>
 */
function chatParts(TestResponse $response): array
{
    return collect(explode("\n\n", $response->streamedContent()))
        ->map(fn (string $chunk): string => trim(str_replace('data: ', '', $chunk)))
        ->filter(fn (string $chunk): bool => $chunk !== '' && $chunk !== '[DONE]')
        ->map(fn (string $chunk): array => (array) json_decode($chunk, true))
        ->values()
        ->all();
}

/** @return list<string> */
function chatPartTypes(TestResponse $response): array
{
    return array_column(chatParts($response), 'type');
}

/** @return array<string, mixed>|null */
function chatPart(TestResponse $response, string $type): ?array
{
    return collect(chatParts($response))->firstWhere('type', $type);
}

/**
 * The conversation id announced at the head of a stream.
 */
function chatConversationId(TestResponse $response): string
{
    return chatPart($response, 'data-synapse-start')['data']['conversationId'];
}
