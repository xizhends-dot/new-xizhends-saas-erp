<?php

declare(strict_types=1);

namespace Xizhen\Core\Middleware;

use Xizhen\Services\AuthService;

final class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function handle(callable $next): mixed
    {
        $this->auth->requireAdmin();
        return $next();
    }
}
