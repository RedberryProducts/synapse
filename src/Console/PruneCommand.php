<?php

namespace Redberry\Synapse\Console;

use Illuminate\Console\Command;
use Redberry\Synapse\Repositories\ConversationRepository;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'synapse:prune')]
class PruneCommand extends Command
{
    protected $signature = 'synapse:prune {--days= : The number of days of history to retain}';

    protected $description = 'Prune Synapse conversations older than the retention window';

    public function handle(ConversationRepository $repository): int
    {
        $days = (int) ($this->option('days') ?? config('synapse.retention.days', 7));

        $count = $repository->prune(now()->subDays($days));

        $this->components->info("Pruned {$count} conversation(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
