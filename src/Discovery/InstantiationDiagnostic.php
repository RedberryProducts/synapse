<?php

namespace Redberry\Synapse\Discovery;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

/**
 * Explains why the container could not build an agent.
 *
 * The container's exception message is human-readable but awkward to parse, so
 * the constructor is inspected directly instead. That yields structured reasons
 * the dashboard can turn into actionable advice.
 */
class InstantiationDiagnostic
{
    /**
     * Required constructor parameters the container cannot resolve.
     *
     * An empty list means the failure came from somewhere else (usually the
     * constructor body throwing), so the raw exception message is all we have.
     *
     * @param  class-string  $class
     * @return array<int, array{parameter: string, type: string|null, reason: string}>
     */
    public function unresolvable(string $class): array
    {
        try {
            $constructor = (new ReflectionClass($class))->getConstructor();
        } catch (Throwable) {
            return [];
        }

        if ($constructor === null) {
            return [];
        }

        $unresolvable = [];

        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isOptional()) {
                continue;
            }

            $reason = $this->reasonFor($parameter);

            if ($reason !== null) {
                $type = $parameter->getType();

                $unresolvable[] = [
                    'parameter' => $parameter->getName(),
                    'type' => $type instanceof ReflectionNamedType ? $type->getName() : null,
                    'reason' => $reason,
                ];
            }
        }

        return $unresolvable;
    }

    /**
     * Why this parameter cannot be autowired, or null when it can.
     */
    protected function reasonFor(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType) {
            // Union / intersection types cannot be autowired.
            return $type === null ? 'untyped' : 'unsupported_type';
        }

        if ($type->isBuiltin()) {
            return 'primitive';
        }

        $name = $type->getName();

        if (! class_exists($name) && ! interface_exists($name)) {
            return 'missing_class';
        }

        // An explicit binding always wins, whatever the target is.
        if (app()->bound($name)) {
            return null;
        }

        if (interface_exists($name)) {
            return 'unbound_interface';
        }

        return (new ReflectionClass($name))->isAbstract() ? 'unbound_abstract' : null;
    }
}
