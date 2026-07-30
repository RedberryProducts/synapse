<?php

use Laravel\Ai\Responses\Data\ToolCall;
use Redberry\Synapse\Models\SynapseConversation;
use Redberry\Synapse\Synapse;
use Workbench\App\Agents\SupportAgent;
use Workbench\App\Agents\WeatherAgent;

beforeEach(function () {
    Synapse::auth(fn (): bool => true);

    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
});

/** @return array<int, string> */
function listedTitles(string $query = ''): array
{
    return array_column(
        test()->getJson('/synapse/api/conversations'.($query === '' ? '' : "?{$query}"))->json('data'),
        'title',
    );
}

it('searches conversation titles', function () {
    fakeAgent(SupportAgent::class, fn (): string => 'Answered.');

    sendMessage('workbench.app.agents.support-agent', 'Find me a hoodie');
    sendMessage('workbench.app.agents.support-agent', 'Where is my order');

    expect(listedTitles('search=hoodie'))->toBe(['Find me a hoodie']);
});

it('searches message content, not just titles', function () {
    fakeAgent(SupportAgent::class, fn (): string => 'The scarf is on aisle four.');

    sendMessage('workbench.app.agents.support-agent', 'Question one');
    sendMessage('workbench.app.agents.support-agent', 'Question two');

    // "scarf" appears only in an assistant answer. Searching titles alone would
    // miss the conversation you actually remember.
    expect(listedTitles('search=scarf'))->toHaveCount(2);

    expect(listedTitles('search=aisle+nine'))->toBe([]);
});

it('filters by agent', function () {
    fakeAgent(SupportAgent::class, fn (): string => 'Answered.');
    fakeAgent(WeatherAgent::class, fn (): string => 'Sunny.');

    sendMessage('workbench.app.agents.support-agent', 'Support question');
    sendMessage('workbench.app.agents.weather-agent', 'Weather question');

    expect(listedTitles('agents[]=workbench.app.agents.weather-agent'))
        ->toBe(['Weather question']);
});

it('filters by several agents at once', function () {
    fakeAgent(SupportAgent::class, fn (): string => 'Answered.');
    fakeAgent(WeatherAgent::class, fn (): string => 'Sunny.');

    sendMessage('workbench.app.agents.support-agent', 'Support question');
    sendMessage('workbench.app.agents.weather-agent', 'Weather question');

    expect(listedTitles('agents[]=workbench.app.agents.weather-agent&agents[]=workbench.app.agents.support-agent'))
        ->toHaveCount(2);
});

it('returns nothing for an agent slug it does not know', function () {
    fakeAgent(SupportAgent::class, fn (): string => 'Answered.');

    sendMessage('workbench.app.agents.support-agent', 'Support question');

    // A slug is only ever resolved by looking it up among discovered agents, so
    // a crafted parameter cannot name an arbitrary class — and an unmatched
    // filter must exclude everything rather than quietly match all.
    expect(listedTitles('agents[]=App\\Models\\User'))->toBe([]);
    expect(listedTitles('agents[]=nope.not.here'))->toBe([]);
});

it('filters by status', function () {
    fakeAgent(SupportAgent::class, fn (string $prompt) => str_contains($prompt, 'fails')
        ? throw new RuntimeException('Provider exploded')
        : 'Answered.');

    sendMessage('workbench.app.agents.support-agent', 'This one fails');
    sendMessage('workbench.app.agents.support-agent', 'This one works');

    expect(listedTitles('status=error'))->toBe(['This one fails']);
    expect(listedTitles('status=success'))->toBe(['This one works']);
});

it('filters by tool used', function () {
    fakeAgent(SupportAgent::class, [
        new ToolCall(id: 'call_1', name: 'SearchProductsTool', arguments: ['query' => 'hoodie']),
        'Found it.',
    ]);

    sendMessage('workbench.app.agents.support-agent', 'With a tool');

    fakeAgent(SupportAgent::class, fn (): string => 'No tool needed.');

    sendMessage('workbench.app.agents.support-agent', 'Without a tool');

    expect(listedTitles('tools[]=SearchProductsTool'))->toBe(['With a tool']);
});

it('filters by date range', function () {
    fakeAgent(SupportAgent::class, fn (): string => 'Answered.');

    sendMessage('workbench.app.agents.support-agent', 'Old question');

    SynapseConversation::query()->update(['updated_at' => now()->subDays(10)]);

    sendMessage('workbench.app.agents.support-agent', 'Recent question');

    expect(listedTitles('from='.now()->subDay()->toDateString()))->toBe(['Recent question']);
    expect(listedTitles('to='.now()->subDays(5)->toDateString()))->toBe(['Old question']);
});

it('composes filters rather than replacing them', function () {
    // One closure for both agents: every conversational agent shares a single
    // fake gateway (the decorator's), so a second `fakeAgent()` call with
    // different responses would silently replace the first.
    $responses = fn (string $prompt) => str_contains($prompt, 'fails')
        ? throw new RuntimeException('Provider exploded')
        : 'Answered.';

    fakeAgent(SupportAgent::class, $responses);
    fakeAgent(WeatherAgent::class, $responses);

    sendMessage('workbench.app.agents.support-agent', 'Support that fails');
    sendMessage('workbench.app.agents.support-agent', 'Support that works');
    sendMessage('workbench.app.agents.weather-agent', 'Weather question');

    expect(listedTitles('agents[]=workbench.app.agents.support-agent&status=error'))
        ->toBe(['Support that fails']);
});

it('rejects a status it does not understand', function () {
    test()->getJson('/synapse/api/conversations?status=maybe')->assertStatus(422);
});
