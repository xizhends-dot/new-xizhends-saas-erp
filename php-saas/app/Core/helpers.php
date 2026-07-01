<?php

declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function current_tenant_key(): string
{
    $tenant = tenant_key_from_host() ?? $_GET['tenant'] ?? $_POST['tenant'] ?? 'erp';
    $tenant = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $tenant);
    return $tenant !== '' ? $tenant : 'erp';
}

function tenant_key_from_host(): ?string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1', 'saas.xizhends.com'], true)) {
        return null;
    }

    $suffix = '.xizhends.com';
    if (!str_ends_with($host, $suffix)) {
        return null;
    }

    $subdomain = substr($host, 0, -strlen($suffix));
    if ($subdomain === '' || $subdomain === 'www' || str_contains($subdomain, '.')) {
        return null;
    }

    return preg_replace('/[^a-zA-Z0-9_-]/', '', $subdomain) ?: null;
}

function is_tenant_host(): bool
{
    return tenant_key_from_host() !== null;
}

function tenant_url(string $path = '/', string $tenantKey = ''): string
{
    if (is_tenant_host()) {
        return $path;
    }

    $tenantKey = $tenantKey !== '' ? $tenantKey : current_tenant_key();
    $separator = str_contains($path, '?') ? '&' : '?';
    return $path . $separator . 'tenant=' . rawurlencode($tenantKey);
}

function redirect_to(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}
