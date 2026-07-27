<?php

namespace Workbench\App\Contracts;

interface UnresolvableDependency
{
    public function handle(): string;
}
