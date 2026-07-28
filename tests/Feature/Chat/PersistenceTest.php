<?php

use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Redberry\Synapse\Models\SynapseConversation;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Synapse;
use Workbench\App\Agents\SupportAgent;

beforeEach(function () {
    Synapse::auth(fn (): bool => true);

    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
});

it('stores the user turn and the assistant turn', function () {
    fakeAgent(SupportAgent::class, ['The answer is 42.']);

    sendMessage('workbench.app.agents.support-agent', 'What is the answer?');

    $messages = SynapseMessage::query()->orderBy('id')->get();

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->role)->toBe('user')
        ->and($messages[0]->content)->toBe('What is the answer?')
        ->and($messages[1]->role)->toBe('assistant')
        ->and($messages[1]->content)->toBe('The answer is 42.');
});

it('promotes token counts and keeps the full usage breakdown', function () {
    fakeAgent(SupportAgent::class, [
        new TextResponse(
            'Counted.',
            new Usage(promptTokens: 142, completionTokens: 89, reasoningTokens: 7),
            new Meta('openai', 'gpt-5.6-luna'),
        ),
    ]);

    sendMessage('workbench.app.agents.support-agent', 'Count my tokens');

    $assistant = SynapseMessage::query()->where('role', 'assistant')->sole();

    // Promoted columns exist for cheap SQL aggregates...
    expect($assistant->prompt_tokens)->toBe(142)
        ->and($assistant->completion_tokens)->toBe(89)
        // ...while the JSON column keeps everything Usage carries.
        ->and($assistant->usage)->toBe([
            'prompt_tokens' => 142,
            'completion_tokens' => 89,
            'cache_write_input_tokens' => 0,
            'cache_read_input_tokens' => 0,
            'reasoning_tokens' => 7,
        ]);
});

it('records the provider and model that actually ran', function () {
    fakeAgent(SupportAgent::class, ['Ran.']);

    sendMessage('workbench.app.agents.support-agent', 'Which model are you?');

    $assistant = SynapseMessage::query()->where('role', 'assistant')->sole();

    expect($assistant->meta['provider'])->toBe('openai')
        ->and($assistant->meta['model'])->toBe('gpt-5.6-luna');
});

it('measures how long the turn took', function () {
    fakeAgent(SupportAgent::class, ['Quick.']);

    sendMessage('workbench.app.agents.support-agent', 'Be quick');

    expect(SynapseMessage::query()->where('role', 'assistant')->sole()->duration_ms)
        ->toBeGreaterThanOrEqual(0);
});

it('reports the usage on the closing part', function () {
    fakeAgent(SupportAgent::class, [
        new TextResponse('Done.', new Usage(promptTokens: 10, completionTokens: 4), new Meta('openai', 'gpt-5.6-luna')),
    ]);

    $end = chatPart(sendMessage('workbench.app.agents.support-agent', 'Finish up'), 'data-synapse-end');

    expect($end['data']['usage']['prompt_tokens'])->toBe(10)
        ->and($end['data']['usage']['completion_tokens'])->toBe(4)
        ->and($end['data']['assistantMessageId'])->not->toBeEmpty();
});

it('touches the conversation so retention and history order correctly', function () {
    fakeAgent(SupportAgent::class, ['One.', 'Two.']);

    $conversationId = chatConversationId(sendMessage('workbench.app.agents.support-agent', 'First'));

    SynapseConversation::query()->whereKey($conversationId)->update(['updated_at' => now()->subDay()]);

    sendMessage('workbench.app.agents.support-agent', 'Second', $conversationId);

    expect(SynapseConversation::query()->find($conversationId)->updated_at->isToday())->toBeTrue();
});

it('keeps the user turn even when the agent blows up', function () {
    fakeAgent(SupportAgent::class, [
        fn () => throw new RuntimeException('Provider exploded'),
    ]);

    sendMessage('workbench.app.agents.support-agent', 'This will fail');

    // The question survives the failure — a thread never loses what was asked.
    expect(SynapseMessage::query()->where('role', 'user')->sole()->content)->toBe('This will fail')
        ->and(SynapseMessage::query()->where('role', 'error')->count())->toBe(1);
});
