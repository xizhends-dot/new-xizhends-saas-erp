<?php

declare(strict_types=1);

namespace Xizhen\Core;

final class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [
        'GET' => [],
        'POST' => [],
    ];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$this->normalize($path)] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$this->normalize($path)] = $handler;
    }

    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);
        $path = $this->normalize($path);

        if ($method === 'POST' && !$this->csrfValid()) {
            $this->rejectExpiredPage();
            return;
        }

        $handler = $this->routes[$method][$path] ?? null;

        if (!$handler) {
            http_response_code(404);
            echo '页面不存在';
            return;
        }

        $handler();
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

    private function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '//' ? '/' : $path;
    }
}
