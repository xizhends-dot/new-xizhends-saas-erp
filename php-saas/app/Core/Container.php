<?php

declare(strict_types=1);

namespace Xizhen\Core;

use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

final class Container
{
    /** @var array<string, callable(self): mixed> */
    private array $bindings = [];

    /** @var array<string, callable(self): mixed> */
    private array $singletons = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public function bind(string $id, callable $factory): void
    {
        $this->bindings[$id] = $factory;
        unset($this->instances[$id], $this->singletons[$id]);
    }

    public function singleton(string $id, callable $factory): void
    {
        $this->singletons[$id] = $factory;
        unset($this->instances[$id], $this->bindings[$id]);
    }

    public function make(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (isset($this->singletons[$id])) {
            return $this->instances[$id] = ($this->singletons[$id])($this);
        }

        if (isset($this->bindings[$id])) {
            return ($this->bindings[$id])($this);
        }

        return $this->build($id);
    }

    private function build(string $id): object
    {
        if (!class_exists($id)) {
            throw new RuntimeException("Container cannot resolve {$id}.");
        }

        $class = new ReflectionClass($id);
        if (!$class->isInstantiable()) {
            throw new RuntimeException("Container cannot instantiate {$id}.");
        }

        $constructor = $class->getConstructor();
        if ($constructor === null) {
            return $class->newInstance();
        }

        $dependencies = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                throw new RuntimeException("Container cannot resolve parameter {$parameter->getName()} for {$id}.");
            }

            $dependencies[] = $this->make($type->getName());
        }

        return $class->newInstanceArgs($dependencies);
    }
}
