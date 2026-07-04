<?php

declare(strict_types=1);

namespace Xizhen\Core\Middleware;

final class CsrfMiddleware implements MiddlewareInterface
{
    public function handle(callable $next): mixed
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && !$this->csrfValid()) {
            $this->rejectExpiredPage();
            return null;
        }

        return $next();
    }

    private function csrfValid(): bool
    {
        return \csrf_token_matches($_POST['_token'] ?? null)
            || \csrf_token_matches($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    }

    private function rejectExpiredPage(): void
    {
        http_response_code(419);
        echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>页面已过期</title><link rel="stylesheet" href="/assets/app.css"></head><body class="auth-page"><main class="login-card"><h1>页面已过期</h1><p>页面已过期，请刷新重试</p><p><a class="btn primary" href="javascript:history.back()">返回上一页</a></p></main></body></html>';
    }
}
