<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Xizhen\Core\Container;

final class ContainerTestSingleton
{
}

interface ContainerTestContract
{
}

final class ContainerTestImplementation implements ContainerTestContract
{
}

final class ContainerTestDependency
{
}

final class ContainerTestConsumer
{
    public function __construct(public readonly ContainerTestDependency $dependency)
    {
    }
}

final class ContainerTestScalarConsumer
{
    public function __construct(string $name)
    {
    }
}

final class ContainerTestUntypedConsumer
{
    public function __construct($dependency)
    {
    }
}

$container = new Container();
$container->singleton(ContainerTestSingleton::class, static fn (): ContainerTestSingleton => new ContainerTestSingleton());
assert_same($container->make(ContainerTestSingleton::class), $container->make(ContainerTestSingleton::class), 'singleton returns same instance');

$container->bind(ContainerTestContract::class, static fn (): ContainerTestContract => new ContainerTestImplementation());
assert_same(ContainerTestImplementation::class, $container->make(ContainerTestContract::class)::class, 'interface binding resolves implementation');

$autowired = $container->make(ContainerTestConsumer::class);
assert_same(ContainerTestDependency::class, $autowired->dependency::class, 'reflection autowires typed dependency');

assert_throws(static fn () => $container->make(ContainerTestScalarConsumer::class), 'scalar constructor parameter throws');
assert_throws(static fn () => $container->make(ContainerTestUntypedConsumer::class), 'untyped constructor parameter throws');

echo "Container test passed.\n";

function assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function assert_throws(callable $callback, string $label): void
{
    try {
        $callback();
    } catch (Throwable) {
        return;
    }

    fwrite(STDERR, "{$label}: expected exception\n");
    exit(1);
}
