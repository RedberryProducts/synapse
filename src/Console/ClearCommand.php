<?php

namespace Redberry\Synapse\Console;

use Illuminate\Console\Command;
use Redberry\Synapse\Repositories\ConversationRepository;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'synapse:clear')]
class ClearCommand extends Command
{
    protected $signature = 'synapse:clear';

    protected $description = 'Clear all Synapse conversation history, including stored attachments';

    public function handle(ConversationRepository $repository): int
    {
        $count = $repository->clear();

        $this->components->info("Cleared {$count} Synapse conversation(s).");

        return self::SUCCESS;
    }
}
