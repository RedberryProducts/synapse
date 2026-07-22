<?php

namespace Redberry\Synapse\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Redberry\Synapse\Synapse;
use Symfony\Component\HttpFoundation\Response;

class Authorize
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return Synapse::check($request)
            ? $next($request)
            : abort(403);
    }
}
