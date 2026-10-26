<?php

namespace Redberry\Synapse\Tests\Fixtures;

use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\CacheToolDefinitions;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasSkills;
use Laravel\Ai\Skills\Skill;
use Laravel\Ai\ToolChoice;
use Workbench\App\Agents\SupportAgent;
use Workbench\App\Middleware\PiiRedactor;

#[CacheInstructions('1h')]
#[CacheToolDefinitions('1h')]
#[MaxSteps(3)]
#[ToolChoice(ToolChoice::required)]
class CachedSkillAgent extends SupportAgent implements HasMiddleware, HasSkills
{
    public array $stepMiddleware = [PiiRedactor::class];

    public function skills(): iterable
    {
        return [new Skill('catalog', 'Catalog search guidance', 'Check availability before answering.')];
    }

    public function middleware(): array
    {
        return $this->stepMiddleware;
    }
}
