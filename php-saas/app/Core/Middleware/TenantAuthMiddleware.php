<?php

declare(strict_types=1);

namespace Xizhen\Core\Middleware;

use Xizhen\Services\AuthService;

final class TenantAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function handle(callable $next): mixed
    {
        $this->auth->requireTenant(\current_tenant_key());
        return $next();
    }
}
