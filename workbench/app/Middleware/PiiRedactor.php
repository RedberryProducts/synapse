<?php

namespace Workbench\App\Middleware;

use Closure;

class PiiRedactor
{
    public function handle(mixed $prompt, Closure $next): mixed
    {
        return $next($prompt);
    }
}
