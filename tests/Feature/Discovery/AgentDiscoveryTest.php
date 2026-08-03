<?php

use Redberry\Synapse\Discovery\AgentDiscovery;
use Redberry\Synapse\Discovery\DiscoveredAgent;
use Workbench\App\Agents\BrokenAgent;
use Workbench\App\Agents\HiddenAgent;
use Workbench\App\Agents\KitchenSinkAgent;
use Workbench\App\Agents\SupportAgent;
use Workbench\App\Agents\WeatherAgent;

function discovery(array $config = []): AgentDiscovery
{
    config(array_merge([
        'synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents'],
        'synapse.discovery.ignore' => [],
    ], $config));

    return app()->makeWith(AgentDiscovery::class, []);
}

/** @return array<int, string> */
function discoveredClasses(AgentDiscovery $discovery): array
{
    return array_map(fn (DiscoveredAgent $agent): string => $agent->class, $discovery->all());
}

it('finds agent classes in the configured paths', function () {
    expect(discoveredClasses(discovery()))
        ->toContain(SupportAgent::class)
        ->toContain(WeatherAgent::class);
});

it('ignores classes that do not implement the agent contract', function () {
    expect(discoveredClasses(discovery()))
        ->not->toContain('Workbench\\App\\Agents\\NotAnAgent');
});

it('ignores abstract agents', function () {
    expect(discoveredClasses(discovery()))
        ->not->toContain('Workbench\\App\\Agents\\BaseAgent');
});

it('honours the discovery ignore list', function () {
    $discovery = discovery(['synapse.discovery.ignore' => [HiddenAgent::class]]);

    expect(discoveredClasses($discovery))->not->toContain(HiddenAgent::class);
});

it('returns nothing when no configured path exists', function () {
    $discovery = discovery(['synapse.discovery.paths' => [__DIR__.'/does-not-exist']]);

    expect($discovery->all())->toBe([]);
});

it('resolves classes in a non-app namespace', function () {
    // The workbench lives under Workbench\App\, so PSR-4 mapping must not
    // assume the host application's App\ prefix.
    expect(discoveredClasses(discovery()))->toContain(SupportAgent::class);
});

it('reads provider and model from attributes', function () {
    $agent = collect(discovery()->all())->firstWhere('class', SupportAgent::class);

    expect($agent->provider)->toBe('openai');
    expect($agent->model)->toBe('gpt-5.6-luna');
    expect($agent->modelTier)->toBe('default');
});

it('reports the model tier when no explicit model is declared', function () {
    $agent = collect(discovery()->all())->firstWhere('class', KitchenSinkAgent::class);

    expect($agent->model)->toBeNull();
    expect($agent->modelTier)->toBe('smartest');
});

it('falls back to the configured default provider', function () {
    config(['ai.default' => 'anthropic']);

    $agent = collect(discovery()->all())->firstWhere('class', KitchenSinkAgent::class);

    expect($agent->provider)->toBe('anthropic');
});

it('marks agents that cannot be instantiated as unavailable', function () {
    $agents = discovery()->all();
    $broken = collect($agents)->firstWhere('class', BrokenAgent::class);

    expect($broken->available)->toBeFalse();
    expect($broken->error)->not->toBeEmpty();

    // The rest of the dashboard still works.
    expect(collect($agents)->firstWhere('class', SupportAgent::class)->available)->toBeTrue();
});

it('builds a url-safe slug and finds an agent by it', function () {
    $discovery = discovery();
    $agent = collect($discovery->all())->firstWhere('class', SupportAgent::class);

    expect($agent->slug)->toBe('workbench.app.agents.support-agent');
    expect($discovery->find($agent->slug)->class)->toBe(SupportAgent::class);
});

it('returns null for an unknown slug', function () {
    expect(discovery()->find('nope.not.here'))->toBeNull();
});

it('sorts agents by name', function () {
    $names = array_map(fn (DiscoveredAgent $agent): string => $agent->name, discovery()->all());

    expect($names)->toBe(collect($names)->sort()->values()->all());
});

it('finds an agent written after the container booted', function () {
    // Success Criterion #1: write a class, refresh, it's there. No cache-clear
    // step, no restart. Discovery deliberately has no persistent cache — the
    // scan is cheap (measured at 9ms for 250 agents, cold, in Epic 7.2) and a
    // stale dashboard is worse than a rescan on a tool you use while editing
    // the very classes it lists.
    //
    // This is the test that fails the day someone adds one.
    $dir = dirname(__DIR__, 3).'/workbench/app/Agents/Runtime';

    // A first scan populates whatever caching might exist, so the new class has
    // something to be missing from.
    expect(discoveredClasses(discovery()))->not->toContain('Workbench\App\Agents\Runtime\JustWrittenAgent');

    // `AgentDiscovery` is a singleton and memoises for its own lifetime, which
    // is one request. Forgetting the instance is what a refresh does — without
    // this the test would assert against the cache it is supposed to be proving
    // does not outlive the request.
    app()->forgetInstance(AgentDiscovery::class);

    mkdir($dir, 0777, true);
    file_put_contents("{$dir}/JustWrittenAgent.php", <<<'AGENT'
    <?php

    namespace Workbench\App\Agents\Runtime;

    use Laravel\Ai\Attributes\Provider;
    use Laravel\Ai\Contracts\Agent;
    use Laravel\Ai\Promptable;
    use Stringable;

    #[Provider('openai')]
    class JustWrittenAgent implements Agent
    {
        use Promptable;

        public function instructions(): Stringable|string
        {
            return 'Written while the app was already running.';
        }
    }
    AGENT);

    try {
        expect(discoveredClasses(discovery()))
            ->toContain('Workbench\App\Agents\Runtime\JustWrittenAgent');
    } finally {
        unlink("{$dir}/JustWrittenAgent.php");
        rmdir($dir);
    }
});
