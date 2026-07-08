<?php

declare(strict_types=1);

namespace App\Core\Container;

use Closure;
use ReflectionClass;
use RuntimeException;

final class Container
{
    /** @var array<string, Closure(self):mixed> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public function bind(string $id, Closure $factory): void
    {
        $this->bindings[$id] = $factory;
    }

    public function singleton(string $id, Closure $factory): void
    {
        $this->bindings[$id] = function (self $container) use ($factory, $id): mixed {
            if (!array_key_exists($id, $this->instances)) {
                $this->instances[$id] = $factory($container);
            }

            return $this->instances[$id];
        };
    }

    public function has(string $id): bool
    {
        return isset($this->bindings[$id]) || isset($this->instances[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (isset($this->bindings[$id])) {
            $resolved = ($this->bindings[$id])($this);
            if (array_key_exists($id, $this->instances)) {
                return $this->instances[$id];
            }
            return $resolved;
        }

        if (class_exists($id)) {
            return $this->build($id);
        }

        throw new RuntimeException(sprintf('Service not resolvable: %s', $id));
    }

    public function build(string $class): object
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return new $class();
        }

        $dependencies = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type === null || $type->isBuiltin()) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                    continue;
                }

                throw new RuntimeException(sprintf(
                    'Unresolvable dependency [%s] in class %s',
                    $parameter->getName(),
                    $class
                ));
            }

            $dependencies[] = $this->get($type->getName());
        }

        return $reflection->newInstanceArgs($dependencies);
    }
}
