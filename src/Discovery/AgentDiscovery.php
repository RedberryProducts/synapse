<?php

namespace Redberry\Synapse\Discovery;

use Composer\Autoload\ClassLoader;
use Laravel\Ai\Contracts\Agent;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Finds the agent classes in the host application.
 *
 * Results are cached for the lifetime of the instance (bound as a singleton, so
 * effectively per request). There is deliberately no persistent cache: in
 * development classes change constantly and a stale dashboard is worse than a
 * rescan.
 */
class AgentDiscovery
{
    /** @var array<int, DiscoveredAgent>|null */
    protected ?array $agents = null;

    public function __construct(protected AgentMetadata $metadata) {}

    /**
     * All discovered agents, sorted by name.
     *
     * @return array<int, DiscoveredAgent>
     */
    public function all(): array
    {
        if ($this->agents !== null) {
            return $this->agents;
        }

        $agents = array_map(
            fn (string $class): DiscoveredAgent => $this->metadata->for($class),
            $this->classes(),
        );

        usort($agents, fn (DiscoveredAgent $a, DiscoveredAgent $b): int => strcmp($a->name, $b->name));

        return $this->agents = $agents;
    }

    /**
     * Resolve a discovered agent by its slug.
     */
    public function find(string $slug): ?DiscoveredAgent
    {
        foreach ($this->all() as $agent) {
            if ($agent->slug === $slug) {
                return $agent;
            }
        }

        return null;
    }

    /**
     * Resolve a slug to a live agent instance.
     */
    public function resolve(string $slug): ?object
    {
        $agent = $this->find($slug);

        return $agent && $agent->available ? app($agent->class) : null;
    }

    /**
     * The agent class names found in the configured paths.
     *
     * @return array<int, class-string>
     */
    protected function classes(): array
    {
        $paths = array_filter(
            (array) config('synapse.discovery.paths', []),
            fn (string $path): bool => is_dir($path),
        );

        if ($paths === []) {
            return [];
        }

        $ignored = (array) config('synapse.discovery.ignore', []);
        $classes = [];

        foreach (Finder::create()->files()->in($paths)->name('*.php') as $file) {
            $class = $this->classFor($file->getRealPath() ?: $file->getPathname());

            if ($class !== null && ! in_array($class, $ignored, true) && $this->isAgent($class)) {
                $classes[] = $class;
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * @param  class-string  $class
     */
    protected function isAgent(string $class): bool
    {
        // classFor() only returns names that passed class_exists(), so
        // reflecting on them cannot fail here.
        $reflection = new ReflectionClass($class);

        return $reflection->isInstantiable() && $reflection->implementsInterface(Agent::class);
    }

    /**
     * Map a file path to its class name using Composer's PSR-4 prefixes, falling
     * back to parsing the file when no prefix matches.
     *
     * @return class-string|null
     */
    protected function classFor(string $path): ?string
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            foreach ($loader->getPrefixesPsr4() as $prefix => $directories) {
                foreach ($directories as $directory) {
                    $directory = realpath($directory);

                    if ($directory === false || ! str_starts_with($path, $directory.DIRECTORY_SEPARATOR)) {
                        continue;
                    }

                    $relative = substr($path, strlen($directory) + 1);
                    $class = $prefix.str_replace(['/', '.php'], ['\\', ''], $relative);

                    if (class_exists($class)) {
                        return $class;
                    }
                }
            }
        }

        return $this->classFromSource($path);
    }

    /**
     * Parse `namespace` + `class` out of a file when PSR-4 can't tell us.
     *
     * @return class-string|null
     */
    protected function classFromSource(string $path): ?string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        preg_match('/^\s*namespace\s+([^;{\s]+)/m', $contents, $namespace);
        preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $contents, $name);

        if (! isset($name[1])) {
            return null;
        }

        $class = isset($namespace[1]) ? $namespace[1].'\\'.$name[1] : $name[1];

        return class_exists($class) ? $class : null;
    }
}
