<?php

use Redberry\Synapse\Discovery\AgentDiscovery;
use Redberry\Synapse\Discovery\InstantiationDiagnostic;
use Workbench\App\Agents\BrokenAgent;
use Workbench\App\Agents\SupportAgent;
use Workbench\App\Contracts\UnresolvableDependency;

it('identifies an unbound interface in the constructor', function () {
    $unresolvable = (new InstantiationDiagnostic)->unresolvable(BrokenAgent::class);

    expect($unresolvable)->toBe([[
        'parameter' => 'dependency',
        'type' => UnresolvableDependency::class,
        'reason' => 'unbound_interface',
    ]]);
});

it('reports nothing for an agent with no constructor', function () {
    expect((new InstantiationDiagnostic)->unresolvable(SupportAgent::class))->toBe([]);
});

it('treats a bound interface as resolvable', function () {
    app()->bind(UnresolvableDependency::class, fn () => new class implements UnresolvableDependency
    {
        public function handle(): string
        {
            return 'ok';
        }
    });

    expect((new InstantiationDiagnostic)->unresolvable(BrokenAgent::class))->toBe([]);
});

it('classifies the failure as a binding problem', function () {
    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);

    $broken = collect(app(AgentDiscovery::class)->all())
        ->firstWhere('class', BrokenAgent::class);

    expect($broken->errorKind)->toBe('binding');
    expect($broken->unresolvable)->toHaveCount(1);
});
