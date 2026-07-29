<?php

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Laravel\Ai\Responses\Data\ToolCall;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Models\SynapseToolInvocation;
use Redberry\Synapse\Synapse;
use Workbench\App\Agents\FlakyToolAgent;
use Workbench\App\Agents\SupportAgent;

/*
| The SDK does not catch exceptions thrown inside a tool: InvokesTools::
| executeTool() wraps the handler in try/finally with no catch, and the loop
| hard-codes `successful: true` on every tool result event. A throwing tool
| therefore exits stream() entirely — which is why Synapse's whole error story
| rests on one catch-all around the invocation, not on inspecting tool results.
*/

beforeEach(function () {
    Synapse::auth(fn (): bool => true);

    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
});

it('turns a provider failure into an inline error card', function () {
    fakeAgent(SupportAgent::class, [
        fn () => throw new RuntimeException('Rate limit exceeded for openai.'),
    ]);

    $response = sendMessage('workbench.app.agents.support-agent', 'Anything');

    $error = chatPart($response, 'error');

    expect($error['errorText'])->toBe('Rate limit exceeded for openai.')
        ->and($error['data']['exceptionClass'])->toBe(RuntimeException::class)
        ->and($error['data']['stackTrace'])->not->toBeEmpty()
        ->and($error['data']['messageId'])->not->toBeEmpty();
});

it('stores the failure so the thread still explains itself after a refresh', function () {
    fakeAgent(SupportAgent::class, [
        fn () => throw new RuntimeException('Provider exploded'),
    ]);

    sendMessage('workbench.app.agents.support-agent', 'Anything');

    $error = SynapseMessage::query()->where('role', 'error')->sole();

    expect($error->content)->toBe('Provider exploded')
        ->and($error->metadata['exception_class'])->toBe(RuntimeException::class)
        ->and($error->metadata['stack_trace'])->toBeString();
});

it('surfaces what the provider actually said, not just the status code', function () {
    // A RequestException's message is only ever "HTTP request returned status
    // code 400". The reason lives in the response body, and dropping it would
    // leave a debugging tool telling you nothing you couldn't already see.
    $response = new Response(new Psr7Response(400, [], json_encode([
        'error' => ['message' => "Unsupported parameter: 'temperature'.", 'code' => 'unsupported_parameter'],
    ])));

    fakeAgent(SupportAgent::class, [
        fn () => throw new RequestException($response),
    ]);

    $error = chatPart(sendMessage('workbench.app.agents.support-agent', 'Anything'), 'error');

    expect($error['data']['responseStatus'])->toBe(400)
        ->and($error['data']['responseBody'])->toContain('Unsupported parameter');

    expect(SynapseMessage::query()->where('role', 'error')->sole()->metadata)
        ->toMatchArray(['response_status' => 400]);
});

it('finds the response on an exception the SDK wrapped', function () {
    // The SDK converts rate limits and credit failures into its own types with
    // the original attached as `previous`, so the chain has to be walked.
    $response = new Response(new Psr7Response(429, [], '{"error":{"message":"Rate limit reached."}}'));

    fakeAgent(SupportAgent::class, [
        fn () => throw new RuntimeException('Rate limited', 429, new RequestException($response)),
    ]);

    $error = chatPart(sendMessage('workbench.app.agents.support-agent', 'Anything'), 'error');

    expect($error['data']['responseStatus'])->toBe(429)
        ->and($error['data']['responseBody'])->toContain('Rate limit reached');
});

it('leaves the response fields empty for a failure that was not an http call', function () {
    fakeAgent(SupportAgent::class, [
        fn () => throw new RuntimeException('Something local broke'),
    ]);

    $error = chatPart(sendMessage('workbench.app.agents.support-agent', 'Anything'), 'error');

    expect($error['data']['responseStatus'])->toBeNull()
        ->and($error['data']['responseBody'])->toBeNull();
});

it('still closes the stream cleanly when the agent fails', function () {
    fakeAgent(SupportAgent::class, [
        fn () => throw new RuntimeException('Boom'),
    ]);

    $response = sendMessage('workbench.app.agents.support-agent', 'Anything');

    // The client must always reach a terminator, or it would spin forever.
    expect(array_slice(chatPartTypes($response), -2))->toBe(['error', 'data-synapse-end'])
        ->and($response->streamedContent())->toEndWith("data: [DONE]\n\n");
});

it('never lets a failure escape as an http error', function () {
    fakeAgent(SupportAgent::class, [
        fn () => throw new RuntimeException('Boom'),
    ]);

    // Headers are already on the wire by the time the stream closure runs, so a
    // failure has to arrive as a part. 200 with an error card is correct here.
    sendMessage('workbench.app.agents.support-agent', 'Anything')->assertOk();
});

it('surfaces an exception thrown inside a developer tool', function () {
    fakeAgent(FlakyToolAgent::class, [
        new ToolCall(id: 'call_1', name: 'BrokenLedgerTool', arguments: ['entry' => '42']),
    ]);

    $response = sendMessage('workbench.app.agents.flaky-tool-agent', 'Look up entry 42');

    expect(chatPart($response, 'error')['errorText'])->toBe('Ledger service unavailable')
        ->and(SynapseMessage::query()->where('role', 'error')->sole()->metadata['exception_class'])
        ->toBe(RuntimeException::class);
});

it('closes out a tool invocation the exception left hanging', function () {
    fakeAgent(FlakyToolAgent::class, [
        new ToolCall(id: 'call_1', name: 'BrokenLedgerTool', arguments: ['entry' => '42']),
    ]);

    // A throwing tool fires InvokingTool but never ToolInvoked, so the recorder's
    // row would stay `pending` forever if nothing swept it.
    sendMessage('workbench.app.agents.flaky-tool-agent', 'Look up entry 42');

    $invocation = SynapseToolInvocation::query()->sole();

    expect($invocation->status)->toBe('error')
        ->and($invocation->error)->toBe('Ledger service unavailable')
        ->and($invocation->finished_at)->not->toBeNull();
});

it('leaves the conversation usable after a failure', function () {
    // Keyed on the prompt rather than a response list: the fake advances its
    // index with `tap()` *after* marshalling, so a throwing entry never moves
    // the cursor and would fail every subsequent turn too.
    fakeAgent(SupportAgent::class, fn (string $prompt) => str_contains($prompt, 'fails')
        ? throw new RuntimeException('Transient blip')
        : 'Recovered fine.');

    $conversationId = chatConversationId(
        sendMessage('workbench.app.agents.support-agent', 'This one fails')
    );

    sendMessage('workbench.app.agents.support-agent', 'This one works', $conversationId);

    expect(SynapseMessage::query()->where('role', 'assistant')->sole()->content)
        ->toBe('Recovered fine.');
});
