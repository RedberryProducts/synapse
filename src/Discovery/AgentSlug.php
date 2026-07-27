<?php

namespace Redberry\Synapse\Discovery;

use Illuminate\Support\Str;

/**
 * Converts an agent FQCN into a URL-safe slug.
 *
 * Slugs are only ever resolved back to a class by looking one up among the
 * discovered agents (see AgentDiscovery::find) — never by transforming a slug
 * back into a class name, which would let a URL name an arbitrary class.
 */
class AgentSlug
{
    public static function make(string $class): string
    {
        return collect(explode('\\', $class))
            ->map(fn (string $segment): string => Str::kebab($segment))
            ->implode('.');
    }
}
