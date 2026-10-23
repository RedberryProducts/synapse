<?php

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasSkills;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Skills\Skill;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\LoadSkill;
use Redberry\Synapse\Discovery\AgentMetadata;
use Redberry\Synapse\Discovery\GenerationOptions;
use Redberry\Synapse\Discovery\ToolClassifier;
use Redberry\Synapse\Discovery\ToolDetail;
use Redberry\Synapse\Synapse;
use Workbench\App\Agents\SupportAgent;
use Workbench\App\Agents\WeatherAgent;

beforeEach(function () {
    Synapse::auth(fn (): bool => true);
    Http::preventStrayRequests();
    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
});

it('discovers the injected tool for an agent with only skills', function () {
    $agent = new class implements Agent, HasSkills
    {
        use Promptable;

        public function instructions(): string
        {
            return 'Use the catalog skill.';
        }

        public function skills(): iterable
        {
            return [new Skill('catalog', 'Catalog guidance', 'Search before answering.')];
        }
    };
    app()->instance(SupportAgent::class, $agent);
    $this->getJson('/synapse/api/agents/workbench.app.agents.support-agent')->assertOk()
        ->assertJsonPath('tools.0.name', 'LoadSkill')
        ->assertJsonPath('tools.0.parameters.0.name', 'name');
    expect(app(ToolClassifier::class)->classify($agent))->toBe([['name' => 'LoadSkill', 'type' => 'tool']])
        ->and(app(ToolDetail::class)->all($agent)[0]['description'])->toContain('catalog: Catalog guidance');
});

it('merges declared skills with an explicit LoadSkill without duplicates', function () {
    $agent = new class extends SupportAgent implements HasSkills
    {
        public function skills(): iterable
        {
            return [new Skill('catalog', 'Catalog guidance', 'Search first.')];
        }

        public function tools(): iterable
        {
            return [new LoadSkill([new Skill('returns', 'Returns guidance', 'Thirty days.')])];
        }
    };
    $details = app(ToolDetail::class)->all($agent);
    expect(app(ToolClassifier::class)->classify($agent))->toBe([['name' => 'LoadSkill', 'type' => 'tool']])
        ->and($details)->toHaveCount(1)
        ->and($details[0]['description'])->toContain('catalog: Catalog guidance', 'returns: Returns guidance');
});

it('does not invent a skill loader when the agent declares no skills', function () {
    $agent = new class extends SupportAgent implements HasSkills
    {
        public function skills(): iterable
        {
            return [];
        }
    };
    expect(array_column(app(ToolClassifier::class)->classify($agent), 'name'))->toBe(['SearchProductsTool']);
});

it('classifies an SDK wrapped subagent consistently with a raw agent', function () {
    $agent = new class extends SupportAgent
    {
        public function tools(): iterable
        {
            return [new AgentTool(new WeatherAgent)];
        }
    };
    expect(app(ToolClassifier::class)->classify($agent))->toBe([['name' => 'WeatherAgent', 'type' => 'agent']])
        ->and(app(ToolDetail::class)->all($agent)[0]['agent_slug'])->toBe('workbench.app.agents.weather-agent');
});

it('displays the primary provider model pair from a failover map', function () {
    app()->instance(SupportAgent::class, new class extends SupportAgent
    {
        public function provider(): array
        {
            return ['anthropic' => 'primary-model', 'openai' => 'fallback-model'];
        }

        public function model(): string
        {
            return 'ignored-for-provider-map';
        }
    });
    $this->getJson('/synapse/api/agents/workbench.app.agents.support-agent')->assertOk()
        ->assertJsonPath('provider', 'anthropic')->assertJsonPath('model', 'primary-model')
        ->assertJsonPath('models.0.id', 'primary-model');
});

it('uses provider defaults for a failover list instead of the model method', function () {
    app()->instance(SupportAgent::class, new class extends SupportAgent
    {
        public function provider(): array
        {
            return [Lab::Anthropic, Lab::OpenAI];
        }

        public function model(): string
        {
            return 'ignored-for-provider-list';
        }
    });
    $agent = app(AgentMetadata::class)->for(SupportAgent::class);
    expect($agent->provider)->toBe('anthropic')->and($agent->model)->toBeNull();
});

it('resolves provider options with the SDK enum semantics', function () {
    $agent = new class extends SupportAgent implements HasProviderOptions
    {
        public function providerOptions(Lab|string $provider): array
        {
            return ['matched' => $provider === Lab::Anthropic];
        }
    };
    expect(app(GenerationOptions::class)->providerOptions($agent, 'anthropic'))->toBe(['matched' => true]);
});

it('preserves custom provider names in provider options', function () {
    $agent = new class extends SupportAgent implements HasProviderOptions
    {
        public function providerOptions(Lab|string $provider): array
        {
            return ['matched' => $provider === 'internal-gateway'];
        }
    };
    expect(app(GenerationOptions::class)->providerOptions($agent, 'internal-gateway'))->toBe(['matched' => true]);
});

it('keeps Bedrock discovery available without the optional AWS SDK', function () {
    app()->instance(SupportAgent::class, new class extends SupportAgent
    {
        public function provider(): string
        {
            return 'bedrock';
        }

        public function model(): string
        {
            return 'anthropic.claude-test';
        }
    });
    $this->getJson('/synapse/api/agents/workbench.app.agents.support-agent')->assertOk()
        ->assertJsonPath('available', true)->assertJsonPath('models.0.id', 'anthropic.claude-test');
    expect(class_exists(BedrockRuntimeClient::class))->toBeFalse();
    $response = sendMessage('workbench.app.agents.support-agent', 'Hello');
    expect(chatPart($response, 'error')['errorText'])->toContain('composer require aws/aws-sdk-php');
    Http::assertNothingSent();
});
