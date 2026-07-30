<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\StreamingAgent;
use Redberry\Synapse\Discovery\AgentDiscovery;
use Redberry\Synapse\Discovery\ModelOptions;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Synapse;
use Workbench\App\Agents\SupportAgent;

beforeEach(function () {
    Synapse::auth(fn (): bool => true);

    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
});

it('runs on the model the send asks for', function () {
    fakeAgent(SupportAgent::class, ['Answered elsewhere.']);

    $model = null;
    Event::listen(StreamingAgent::class, function (StreamingAgent $event) use (&$model): void {
        $model = $event->prompt->model;
    });

    test()->post('/synapse/api/chat/workbench.app.agents.support-agent/send', [
        'message' => 'Try another model',
        'model' => 'gpt-5-mini',
    ])->streamedContent();

    // A non-null model skips the agent's own resolution inside the SDK, and the
    // decorator delegates that method to the wrapped agent, so the override has
    // to survive the wrap.
    expect($model)->toBe('gpt-5-mini');
});

it('uses the agent own model when no override is given', function () {
    fakeAgent(SupportAgent::class, ['Answered normally.']);

    $model = null;
    Event::listen(StreamingAgent::class, function (StreamingAgent $event) use (&$model): void {
        $model = $event->prompt->model;
    });

    sendMessage('workbench.app.agents.support-agent', 'Just answer');

    expect($model)->toBe('gpt-5.6-luna');
});

it('records the model that actually ran', function () {
    fakeAgent(SupportAgent::class, ['Answered elsewhere.']);

    test()->post('/synapse/api/chat/workbench.app.agents.support-agent/send', [
        'message' => 'Try another model',
        'model' => 'gpt-5-mini',
    ])->streamedContent();

    // Replay must never let you mistake an override for the agent's setting.
    expect(SynapseMessage::query()->where('role', 'assistant')->sole()->meta['model'])
        ->toBe('gpt-5-mini');
});

it('offers the agent model first, then the provider tiers', function () {
    $agent = app(AgentDiscovery::class)->find('workbench.app.agents.support-agent');

    $options = app(ModelOptions::class)->for($agent);

    expect($options[0])->toBe(['id' => 'gpt-5.6-luna', 'label' => 'gpt-5.6-luna', 'tier' => 'agent'])
        ->and(array_column($options, 'tier'))->toContain('cheapest')
        ->and(array_column($options, 'tier'))->toContain('smartest');
});

it('names the tier model as the default for an agent with no explicit model', function () {
    // KitchenSinkAgent carries #[UseSmartestModel] and no #[Model], so the model
    // it will actually run on comes from the tier. Without an `agent` entry the
    // composer's chip falls back to the first option — labelling the playground
    // with a model the agent is not going to use, which is precisely the lie
    // this product exists to prevent.
    $agent = app(AgentDiscovery::class)->find('workbench.app.agents.kitchen-sink-agent');

    $options = app(ModelOptions::class)->for($agent);

    expect($agent->model)->toBeNull()
        ->and($agent->modelTier)->toBe('smartest')
        ->and($options[0]['tier'])->toBe('agent')
        // The default and the smartest tier are the same model here, so it must
        // appear once, labelled as the agent's own.
        ->and(array_column($options, 'id'))->toBe(array_unique(array_column($options, 'id')));

    expect($options[0]['id'])->toBe(
        collect($options)->firstWhere('tier', 'smartest')['id'] ?? $options[0]['id']
    );
});

it('never offers a bare provider default as a choice', function () {
    // The provider's default is only used to resolve an agent that names neither
    // a model nor a tier; it is not a tier the developer picked.
    $agent = app(AgentDiscovery::class)->find('workbench.app.agents.support-agent');

    expect(array_column(app(ModelOptions::class)->for($agent), 'tier'))
        ->not->toContain('default');
});

it('appends configured extras without duplicating anything', function () {
    config(['synapse.playground.models' => ['gpt-5.6-luna', 'some-other-model']]);

    $agent = app(AgentDiscovery::class)->find('workbench.app.agents.support-agent');

    $ids = array_column(app(ModelOptions::class)->for($agent), 'id');

    // The agent already runs gpt-5.6-luna; listing it twice would imply a choice
    // that isn't one.
    expect(array_count_values($ids)['gpt-5.6-luna'])->toBe(1)
        ->and($ids)->toContain('some-other-model');
});

it('still offers the agent model when the provider cannot be resolved', function () {
    $agent = app(AgentDiscovery::class)->find('workbench.app.agents.support-agent');

    config(['ai.providers' => []]);

    // A misconfigured driver must not blank the composer.
    expect(array_column(app(ModelOptions::class)->for($agent), 'id'))->toContain('gpt-5.6-luna');
});

it('exposes the options on the agent detail endpoint', function () {
    test()->getJson('/synapse/api/agents/workbench.app.agents.support-agent')
        ->assertOk()
        ->assertJsonPath('models.0.id', 'gpt-5.6-luna')
        ->assertJsonPath('models.0.tier', 'agent');
});
