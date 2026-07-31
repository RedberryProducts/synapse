<?php

/*
| Targeting uses data-testid (`@name`); content is still asserted as real text,
| scoped to the element it belongs to. See AGENTS.md → Browser tests.
|
| Conversations are seeded through the chat pipeline with faked agents, so the
| page reads exactly the data the product writes.
*/

use Laravel\Ai\Responses\Data\ToolCall;
use Redberry\Synapse\Models\SynapseConversation;
use Workbench\App\Agents\SupportAgent;
use Workbench\App\Agents\WeatherAgent;

beforeEach(function () {
    config(['synapse.discovery.paths' => [dirname(__DIR__, 2).'/workbench/app/Agents']]);
});

/**
 * Seed a handful of conversations across two agents, one of which failed.
 *
 * One closure for both agents: every conversational agent shares a single fake
 * gateway, so two sets of responses would replace each other.
 */
function seedHistory(): void
{
    $responses = fn (string $prompt) => str_contains($prompt, 'fails')
        ? throw new RuntimeException('Provider exploded')
        : 'Answered.';

    fakeAgent(SupportAgent::class, $responses);
    fakeAgent(WeatherAgent::class, $responses);

    sendMessage('workbench.app.agents.support-agent', 'Find me a hoodie');
    sendMessage('workbench.app.agents.support-agent', 'This one fails');
    sendMessage('workbench.app.agents.weather-agent', 'Weather in Tbilisi');
}

it('shows an empty state before anything has happened', function () {
    visit('/synapse/history')
        ->assertPresent('@history-empty')
        ->assertSeeIn('@history-empty', 'No conversations yet')
        ->assertMissing('@history-table')
        ->assertNoJavaScriptErrors();
});

it('lists conversations with their agent, status and tokens', function () {
    seedHistory();

    $page = visit('/synapse/history');

    $page->assertPresent('@history-table')
        ->assertSeeIn('@history-table', 'Find me a hoodie')
        ->assertSeeIn('@history-table', 'Weather in Tbilisi')
        ->assertNoJavaScriptErrors();

    // Three rows, one of which failed.
    expect($page->script("document.querySelectorAll('[data-testid=history-row]').length"))->toBe(3);
    expect($page->script("document.querySelectorAll('[data-status=error]').length"))->toBe(1);
});

it('counts the conversations beside the title', function () {
    seedHistory();

    visit('/synapse/history')->assertSee('(3)');
});

it('narrows the list by search', function () {
    seedHistory();

    $page = visit('/synapse/history');

    $page->type('@history-search', 'hoodie');

    // Element assertions wait for the debounce and re-render; a text assertion
    // would read the pre-filter DOM and pass for the wrong reason.
    $page->assertMissing('[data-agent="workbench.app.agents.weather-agent"]')
        ->assertPresent('[data-agent="workbench.app.agents.support-agent"]')
        ->assertSeeIn('@history-table', 'Find me a hoodie')
        ->assertNoJavaScriptErrors();
});

it('shows a distinct state when nothing matches the filters', function () {
    seedHistory();

    $page = visit('/synapse/history');

    $page->type('@history-search', 'nothing will match this');

    // Different from "nothing yet": this one offers a way back.
    $page->assertPresent('@history-no-matches')
        ->assertSeeIn('@history-no-matches', 'No conversations match these filters')
        ->assertMissing('@history-empty');

    $page->click('Clear the filters');

    $page->assertPresent('@history-table');
});

it('filters by status and badges the active filter', function () {
    seedHistory();

    $page = visit('/synapse/history');

    $page->click('[data-testid=filter-status]');
    $page->click('Error');

    $page->assertMissing('[data-agent="workbench.app.agents.weather-agent"]')
        ->assertSeeIn('@history-table', 'This one fails')
        // The badge is what tells you the list is narrowed.
        ->assertPresent('[aria-label="1 selected"]');
});

it('keeps filters through a reload', function () {
    seedHistory();

    $page = visit('/synapse/history');

    $page->type('@history-search', 'hoodie');
    $page->assertSeeIn('@history-table', 'Find me a hoodie');

    // Filters live in the URL, so a reload restores the same view.
    $page->refresh();

    $page->assertMissing('[data-agent="workbench.app.agents.weather-agent"]')
        ->assertSeeIn('@history-table', 'Find me a hoodie');

    expect($page->script("document.querySelector('[data-testid=history-search]').value"))
        ->toBe('hoodie');
});

it('opens a conversation with its thread intact', function () {
    fakeAgent(SupportAgent::class, [
        new ToolCall(id: 'call_1', name: 'SearchProductsTool', arguments: ['query' => 'hoodie']),
        'Found three matches.',
    ]);

    sendMessage('workbench.app.agents.support-agent', 'Find me a hoodie');

    $page = visit('/synapse/history');

    $page->click('Find me a hoodie');

    $page->assertPathContains('/playground/')
        ->assertSeeIn('@message-user', 'Find me a hoodie')
        ->assertSeeIn('@message-assistant', 'Found three matches.')
        ->assertPresent('@tool-card')
        ->assertNoJavaScriptErrors();
});

it('renames a conversation from the row menu', function () {
    seedHistory();

    $page = visit('/synapse/history');

    $page->click('[aria-label="Actions for Find me a hoodie"]');
    $page->click('Rename');

    $page->assertPresent('@rename-dialog');

    $page->type('[aria-label="Conversation name"]', 'Hoodie search, take three');
    $page->click('Save');

    $page->assertSeeIn('@history-table', 'Hoodie search, take three')
        ->assertNoJavaScriptErrors();
});

it('deletes a conversation after confirming', function () {
    seedHistory();

    $page = visit('/synapse/history');

    $page->click('[aria-label="Actions for Weather in Tbilisi"]');
    $page->click('Delete');

    $page->assertPresent('@delete-dialog')
        ->assertSeeIn('@delete-dialog', 'cannot be undone');

    $page->click('[aria-label="Confirm delete"]');

    $page->assertMissing('[data-agent="workbench.app.agents.weather-agent"]');

    expect(SynapseConversation::query()->count())->toBe(2);
});

it('lists recent conversations in the sidebar with an error indicator', function () {
    seedHistory();

    $page = visit('/synapse/history');

    $page->assertPresent('@sidebar-conversations')
        ->assertSeeIn('@sidebar-conversations', 'Weather in Tbilisi');

    // The failed conversation is flagged — getting back to the run that broke is
    // the point of a recents list on a debugging tool.
    expect($page->script(
        "document.querySelectorAll('[data-testid=sidebar-conversations] [aria-label=\"This conversation contains an error\"]').length"
    ))->toBe(1);
});

it('renames a conversation from the sidebar menu', function () {
    seedHistory();

    $page = visit('/synapse/history');

    $page->click('[aria-label="Recent conversation actions for Find me a hoodie"]');
    $page->click('Rename');

    $page->assertPresent('@rename-dialog');
    $page->type('[aria-label="Conversation name"]', 'Renamed from the sidebar');
    $page->click('Save');

    // Both lists read the same records, so both have to show the new title —
    // the sidebar going stale after a write is the bug this shares its
    // implementation with History to avoid.
    $page->assertSeeIn('@sidebar-conversations', 'Renamed from the sidebar')
        ->assertSeeIn('@history-table', 'Renamed from the sidebar')
        ->assertNoJavaScriptErrors();
});

it('deletes a conversation from the sidebar after confirming', function () {
    seedHistory();

    $page = visit('/synapse/history');

    $page->click('[aria-label="Recent conversation actions for Weather in Tbilisi"]');
    $page->click('Delete');

    $page->assertPresent('@delete-dialog');
    $page->click('[aria-label="Confirm delete"]');

    $page->assertMissing('[data-agent="workbench.app.agents.weather-agent"]');

    expect(SynapseConversation::query()->count())->toBe(2);
});

it('returns you to the conversation you were last in', function () {
    fakeAgent(SupportAgent::class, ['Answered.']);

    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $page->type('@composer-input', 'Find me a hoodie')->click('Send');
    $page->assertSeeIn('@message-assistant', 'Answered.');

    // Leave and come back the way you would from Discovery.
    $page->click('Discovery');
    $page->click('SupportAgent');

    $page->assertSeeIn('@message-user', 'Find me a hoodie')->assertNoJavaScriptErrors();
});

it('stays blank when a fresh thread is what you chose', function () {
    fakeAgent(SupportAgent::class, ['Answered.']);

    $page = visit('/synapse/playground/workbench.app.agents.support-agent');
    $page->type('@composer-input', 'Find me a hoodie')->click('Send');
    $page->assertSeeIn('@message-assistant', 'Answered.');

    $page->click('[aria-label="Conversation actions"]');
    $page->click('New conversation');
    $page->assertPresent('@chat-empty');

    // A deliberately blank page must stay blank on return.
    $page->click('Discovery');
    $page->click('SupportAgent');

    $page->assertPresent('@chat-empty')->assertNoJavaScriptErrors();
});

it('renders history in both themes', function () {
    seedHistory();

    foreach (['Light', 'Dark'] as $theme) {
        $page = visit('/synapse/history');

        $page->click('System')->click($theme);

        $page->assertPresent('@history-table')->assertNoJavaScriptErrors();
    }
});
