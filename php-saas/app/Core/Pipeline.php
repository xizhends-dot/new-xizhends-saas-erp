<?php

declare(strict_types=1);

namespace Xizhen\Core;

final class Pipeline
{
    /** @param array<int, object> $middleware */
    public function __construct(private readonly array $middleware)
    {
    }

    public function run(callable $handler): void
    {
        $next = $handler;
        foreach (array_reverse($this->middleware) as $middleware) {
            $next = static fn (): mixed => $middleware->handle($next);
        }

        $next();
    }
}
