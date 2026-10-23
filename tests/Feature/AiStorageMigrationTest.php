<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Redberry\Synapse\Models\SynapseConversation;
use Symfony\Component\Process\Process;

function aiStorageMigration(): Migration
{
    return require dirname(__DIR__, 2).'/database/migrations/2026_10_23_000004_upgrade_synapse_messages_for_ai_1_3.php';
}

it('installs the new message storage contract on a fresh database', function () {
    expect(Schema::hasColumns('synapse_messages', ['steps', 'parts', 'status', 'input_tokens', 'output_tokens']))->toBeTrue()
        ->and(Schema::hasColumn('synapse_messages', 'tool_calls'))->toBeFalse()
        ->and(Schema::hasColumn('synapse_messages', 'tool_results'))->toBeFalse()
        ->and(Schema::hasColumn('synapse_messages', 'prompt_tokens'))->toBeFalse()
        ->and(Schema::hasColumn('synapse_messages', 'completion_tokens'))->toBeFalse();
});

it('upgrades an empty legacy schema', function () {
    $migration = aiStorageMigration();
    $migration->down();
    expect(Schema::hasColumn('synapse_messages', 'tool_calls'))->toBeTrue();
    $migration->up();
    expect(Schema::hasColumn('synapse_messages', 'steps'))->toBeTrue();
});

it('refuses a populated legacy schema without changing its data', function (string $table, array $row) {
    $migration = aiStorageMigration();
    $migration->down();
    if ($table === 'synapse_messages') {
        $row += ['attachments' => '[]', 'tool_calls' => '[]', 'tool_results' => '[]', 'usage' => '{}', 'meta' => '{}', 'metadata' => '{}'];
    }
    $row['created_at'] = now()->toDateTimeString();
    DB::table($table)->insert($row);
    $before = Schema::getColumnListing('synapse_messages');
    $beforeRow = DB::table($table)->where('id', $row['id'])->first();

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'php artisan synapse:clear')
        ->and(Schema::getColumnListing('synapse_messages'))->toBe($before)
        ->and(DB::table($table)->where('id', $row['id'])->first())->toEqual($beforeRow);
    expect(DB::table($table)->count())->toBe(1);
})->with([
    'conversation' => ['synapse_conversations', ['id' => 'legacy', 'agent_class' => 'LegacyAgent', 'title' => 'Keep me']],
    'orphan message' => ['synapse_messages', ['id' => 'legacy', 'conversation_id' => 'missing', 'role' => 'user', 'content' => 'Keep this message']],
    'orphan tool' => ['synapse_tool_invocations', ['id' => 'legacy', 'conversation_id' => 'missing', 'invocation_id' => 'inv', 'tool_invocation_id' => 'call', 'type' => 'tool', 'name' => 'Search', 'arguments' => '{}', 'status' => 'success']],
]);

it('refuses to discard populated new storage on rollback', function () {
    SynapseConversation::create(['agent_class' => 'A', 'title' => 'Keep me']);
    expect(fn () => aiStorageMigration()->down())->toThrow(RuntimeException::class, 'before migrating or rolling back')
        ->and(Schema::hasColumn('synapse_messages', 'steps'))->toBeTrue()
        ->and(SynapseConversation::sole()->title)->toBe('Keep me');
});

it('uses the configured storage connection for the guard and schema change', function () {
    config(['database.connections.synapse_migration' => config('database.connections.testing')]);
    $migration = aiStorageMigration();
    app('migrator')->usingConnection('synapse_migration', function (): void {
        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*create_synapse*') as $path) {
            (require $path)->up();
        }
    });
    config(['synapse.storage.connection' => 'synapse_migration']);
    DB::connection('testing')->table('synapse_conversations')->insert(['id' => 'host', 'agent_class' => 'HostAgent', 'title' => 'Host']);
    try {
        $migration->up();
        expect(Schema::connection('synapse_migration')->hasColumn('synapse_messages', 'steps'))->toBeTrue()
            ->and(DB::connection('testing')->table('synapse_conversations')->count())->toBe(1);
        $migration->down();
        DB::connection('synapse_migration')->table('synapse_conversations')->insert(['id' => 'legacy', 'agent_class' => 'A', 'title' => 'A']);
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'php artisan synapse:clear');
    } finally {
        config(['synapse.storage.connection' => null]);
        DB::purge('synapse_migration');
    }
});

it('round trips SDK steps separately from ordered display parts', function () {
    $conversation = SynapseConversation::create(['agent_class' => 'A', 'title' => 'A']);
    $step = new Step(
        text: 'Let me search.',
        toolCalls: [new ToolCall('call_1', 'Search', ['query' => 'hoodie'])],
        toolResults: [new ToolResult('call_1', 'Search', ['query' => 'hoodie'], 'Found it')],
        finishReason: FinishReason::ToolCalls,
        usage: new TextUsage(inputTokens: 100, outputTokens: 20, cacheReadInputTokens: 40, reasoningTokens: 5),
        meta: new Meta('anthropic', 'claude-test'),
        reasoning: 'Check availability.',
        replayBlocks: [['type' => 'thinking', 'signature' => 'private-provider-signature']],
    );
    $parts = [
        ['id' => 'text-0', 'type' => 'text', 'text' => 'Let me search.'],
        ['id' => 'tool-0', 'type' => 'tool', 'tool_invocation_id' => 'observation-1'],
        ['id' => 'text-1', 'type' => 'text', 'text' => 'Found it.'],
    ];
    $message = $conversation->messages()->create([
        'role' => 'assistant', 'status' => 'completed', 'steps' => [$step->toArray()], 'parts' => $parts,
        'input_tokens' => 100, 'output_tokens' => 20, 'usage' => $step->usage->toArray(),
        'attachments' => [], 'meta' => [], 'metadata' => [],
    ])->fresh();

    expect($message->steps)->toBe(json_decode(json_encode([$step], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
        ->and($message->parts)->toBe($parts)
        ->and($message->status)->toBe('completed')
        ->and($message->input_tokens)->toBe(100)
        ->and($message->output_tokens)->toBe(20)
        ->and($message->usage['cache_write_input_tokens'])->toBeNull();
});

it('clears legacy attachments before allowing the format change', function () {
    $migration = aiStorageMigration();
    $migration->down();
    Storage::fake('local');
    Storage::disk('local')->put('synapse/legacy.txt', 'Keep until explicit clear');
    DB::table('synapse_conversations')->insert(['id' => 'legacy', 'agent_class' => 'A', 'title' => 'A']);
    DB::table('synapse_messages')->insert([
        'id' => 'message', 'conversation_id' => 'legacy', 'role' => 'user',
        'tool_calls' => '[]', 'tool_results' => '[]', 'usage' => '{}', 'meta' => '{}', 'metadata' => '{}', 'created_at' => now(),
        'attachments' => json_encode([['path' => 'synapse/legacy.txt', 'disk' => 'local']]),
    ]);
    expect(fn () => $migration->up())->toThrow(RuntimeException::class);
    Storage::disk('local')->assertExists('synapse/legacy.txt');

    $this->artisan('synapse:clear')->assertSuccessful();
    Storage::disk('local')->assertMissing('synapse/legacy.txt');
    $migration->up();
    expect(Schema::hasColumn('synapse_messages', 'steps'))->toBeTrue();
});

it('prunes attachments with conversations in the new storage format', function () {
    Storage::fake('local');
    Storage::disk('local')->put('synapse/expired.txt', 'Expired');
    $conversation = SynapseConversation::create(['agent_class' => 'A', 'title' => 'A']);
    $conversation->messages()->create([
        'role' => 'user', 'steps' => [], 'parts' => [], 'usage' => [], 'meta' => [], 'metadata' => [],
        'attachments' => [['path' => 'synapse/expired.txt', 'disk' => 'local']],
    ]);
    SynapseConversation::whereKey($conversation->id)->update(['updated_at' => now()->subDays(30)]);
    $this->artisan('synapse:prune', ['--days' => 7])->assertSuccessful();
    Storage::disk('local')->assertMissing('synapse/expired.txt');
    expect($conversation->messages()->count())->toBe(0);
});

it('executes published migrations without autoloading Synapse or the SDK', function () {
    $root = dirname(__DIR__, 2);
    $script = <<<'SCRIPT'
    $loader = require $argv[1].'/vendor/autoload.php';
    spl_autoload_register(function ($class) {
        if (str_starts_with($class, 'Redberry\\Synapse\\') || str_starts_with($class, 'Laravel\\Ai\\')) {
            throw new RuntimeException('Migration depended on '.$class);
        }
    }, true, true);
    $app = new Illuminate\Container\Container;
    Illuminate\Container\Container::setInstance($app);
    $app->instance('config', new Illuminate\Config\Repository(['database' => ['default' => 'default']]));
    $capsule = new Illuminate\Database\Capsule\Manager($app);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $app->instance('db', $capsule->getDatabaseManager());
    $app->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
    Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    $migrations = [];
    foreach (glob($argv[1].'/database/migrations/*.php') as $path) {
        $migration = require $path;
        $migration->up();
        $migrations[] = $migration;
    }
    if (! $capsule->getConnection()->getSchemaBuilder()->hasColumn('synapse_messages', 'steps')) {
        throw new RuntimeException('New schema missing');
    }
    foreach (array_reverse($migrations) as $migration) {
        $migration->down();
    }
    echo 'migrated and rolled back';
    SCRIPT;
    $process = new Process([PHP_BINARY, '-r', $script, $root]);
    $process->mustRun();
    expect($process->getOutput())->toBe('migrated and rolled back');
});
