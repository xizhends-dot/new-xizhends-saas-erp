<?php

declare(strict_types=1);

namespace Xizhen\Core\Middleware;

interface MiddlewareInterface
{
    public function handle(callable $next): mixed;
}
