<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\Permission;
use Xizhen\Core\StoreInterface;

final class AuthService
{
    private const SESSION_KEY = 'xizhen_auth';
    private const FAILURE_WINDOW_SECONDS = 600;
    private const FAILURE_LOCK_SECONDS = 600;
    private const MAX_FAILURES = 5;

    public function __construct(private readonly StoreInterface $store)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (!headers_sent()) {
                session_name('XZSAAS');
                session_set_cookie_params([
                    'lifetime' => 0,
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            session_start();
        }
    }

    /** @return array<string, mixed>|null */
    public function currentAdmin(): ?array
    {
        $session = $_SESSION[self::SESSION_KEY]['admin'] ?? null;
        if (!is_array($session)) {
            return null;
        }

        $admin = $this->store->adminByUsername((string) ($session['username'] ?? ''));
        if (!$admin || ($admin['status'] ?? '') !== 'active') {
            unset($_SESSION[self::SESSION_KEY]['admin']);
            return null;
        }

        return array_merge($session, [
            'display_name' => (string) (($admin['display_name'] ?? '') ?: ($admin['username'] ?? '超管')),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function currentTenantUser(string $tenantKey): ?array
    {
        $session = $_SESSION[self::SESSION_KEY]['tenants'][$tenantKey] ?? null;
        if (!is_array($session)) {
            return null;
        }

        $user = $this->store->user($tenantKey, (int) ($session['id'] ?? 0));
        if (!$user || ($user['status'] ?? '') !== 'active') {
            unset($_SESSION[self::SESSION_KEY]['tenants'][$tenantKey]);
            return null;
        }

        return array_merge($session, [
            'name' => (string) (($user['name'] ?? '') ?: ($user['username'] ?? '员工')),
            'role' => (string) ($user['role'] ?? ''),
            'permissions' => $user['permissions'] ?? [],
            'stores' => $user['stores'] ?? [],
        ]);
    }

    public function requireAdmin(): void
    {
        if ($this->currentAdmin() !== null) {
            return;
        }

        \redirect_to('/admin/login?return=' . rawurlencode($this->requestUri()));
    }

    public function requireTenant(string $tenantKey): void
    {
        if (($this->store->tenant($tenantKey)['status'] ?? '') !== 'active') {
            $this->logout('tenant', $tenantKey);
            \redirect_to(\tenant_url('/login?error=' . rawurlencode('当前租户已停用，请联系 SaaS 超级管理员充值。'), $tenantKey));
        }

        if ($this->currentTenantUser($tenantKey) !== null) {
            return;
        }

        \redirect_to(\tenant_url('/login?return=' . rawurlencode($this->requestUri()), $tenantKey));
    }

    public function requireTenantPermission(string $tenantKey, string $permission): void
    {
        $this->requireTenant($tenantKey);
        if (Permission::has($this->currentTenantUser($tenantKey), $permission)) {
            return;
        }

        $this->deny($permission);
    }

    /** @param array<int, string> $permissions */
    public function requireAnyTenantPermission(string $tenantKey, array $permissions): void
    {
        $this->requireTenant($tenantKey);
        if (Permission::hasAny($this->currentTenantUser($tenantKey), $permissions)) {
            return;
        }

        $this->deny(implode(' / ', $permissions));
    }

    public function tenantCan(string $tenantKey, string $permission): bool
    {
        return Permission::has($this->currentTenantUser($tenantKey), $permission);
    }

    /** @return array{ok: bool, message: string} */
    public function loginAdmin(string $username, string $password): array
    {
        $username = trim($username);
        $lock = $this->lockRemaining('admin', $username);
        if ($lock > 0) {
            return ['ok' => false, 'message' => '登录失败次数过多，请 ' . (int) ceil($lock / 60) . ' 分钟后再试。'];
        }

        $admin = $this->store->adminByUsername($username);
        if (!$admin || ($admin['status'] ?? '') !== 'active' || !$this->verifyHash((string) ($admin['password_hash'] ?? ''), $password)) {
            $this->recordFailure('admin', $username);
            return ['ok' => false, 'message' => '账号或密码不正确。'];
        }

        $this->clearFailures('admin', $username);
        $this->renewSession();
        $_SESSION[self::SESSION_KEY]['admin'] = [
            'id' => (int) $admin['id'],
            'username' => (string) $admin['username'],
            'display_name' => (string) (($admin['display_name'] ?? '') ?: $admin['username']),
            'login_at' => date('Y-m-d H:i:s'),
        ];
        $this->store->touchAdminLogin((int) $admin['id']);

        return ['ok' => true, 'message' => '登录成功。'];
    }

    /** @return array{ok: bool, message: string, user?: array<string, mixed>} */
    public function loginTenant(string $tenantKey, string $username, string $password): array
    {
        $username = trim($username);
        $scope = 'tenant:' . $tenantKey;
        $lock = $this->lockRemaining($scope, $username);
        if ($lock > 0) {
            return ['ok' => false, 'message' => '登录失败次数过多，请 ' . (int) ceil($lock / 60) . ' 分钟后再试。'];
        }

        $tenant = $this->store->tenant($tenantKey);
        $user = $this->store->tenantUserByUsername($tenantKey, $username);
        if (($tenant['status'] ?? '') !== 'active') {
            $this->recordFailure($scope, $username);
            return ['ok' => false, 'message' => '当前租户不可登录。'];
        }
        if (!$user || ($user['status'] ?? '') !== 'active') {
            $this->recordFailure($scope, $username);
            return ['ok' => false, 'message' => '账号或密码不正确。'];
        }

        $passwordOk = $this->verifyTenantPassword($tenantKey, $user, $password);
        if (!$passwordOk) {
            $this->recordFailure($scope, $username);
            return ['ok' => false, 'message' => '账号或密码不正确。'];
        }

        $this->clearFailures($scope, $username);
        $this->renewSession();
        $_SESSION[self::SESSION_KEY]['tenants'][$tenantKey] = [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'name' => (string) (($user['name'] ?? '') ?: ($user['username'] ?? '员工')),
            'role' => (string) ($user['role'] ?? ''),
            'permissions' => $user['permissions'] ?? [],
            'stores' => $user['stores'] ?? [],
            'login_at' => date('Y-m-d H:i:s'),
        ];
        $this->store->touchTenantUserLogin($tenantKey, (int) $user['id']);

        return ['ok' => true, 'message' => '登录成功。', 'user' => $user];
    }

    public function logout(string $scope, string $tenantKey = ''): void
    {
        if ($scope === 'admin') {
            unset($_SESSION[self::SESSION_KEY]['admin']);
            return;
        }

        if ($tenantKey !== '') {
            unset($_SESSION[self::SESSION_KEY]['tenants'][$tenantKey]);
            return;
        }

        unset($_SESSION[self::SESSION_KEY]);
    }

    /** @param array<string, mixed> $user */
    private function verifyTenantPassword(string $tenantKey, array $user, string $password): bool
    {
        $hash = (string) ($user['password_hash'] ?? '');
        if ($this->verifyHash($hash, $password)) {
            if (password_needs_rehash($hash, $this->passwordAlgorithm())) {
                $this->store->updateTenantUserPassword($tenantKey, (int) $user['id'], $this->hashPassword($password));
            }
            return true;
        }

        foreach (['legacy_password', 'password_reset'] as $field) {
            $plain = (string) ($user[$field] ?? '');
            if ($plain !== '' && hash_equals($plain, $password)) {
                $this->store->updateTenantUserPassword($tenantKey, (int) $user['id'], $this->hashPassword($password));
                return true;
            }
        }

        return false;
    }

    private function verifyHash(string $hash, string $password): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    private function hashPassword(string $password): string
    {
        return password_hash($password, $this->passwordAlgorithm());
    }

    private function passwordAlgorithm(): string|int|null
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    private function renewSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private function lockRemaining(string $scope, string $identifier): int
    {
        $key = $this->failureKey($scope, $identifier);
        $row = $_SESSION[self::SESSION_KEY]['failures'][$key] ?? null;
        if (!is_array($row)) {
            return 0;
        }

        $lockedUntil = (int) ($row['locked_until'] ?? 0);
        if ($lockedUntil > time()) {
            return $lockedUntil - time();
        }

        return 0;
    }

    private function recordFailure(string $scope, string $identifier): void
    {
        $key = $this->failureKey($scope, $identifier);
        $now = time();
        $row = $_SESSION[self::SESSION_KEY]['failures'][$key] ?? [
            'count' => 0,
            'first_at' => $now,
            'locked_until' => 0,
        ];
        if (!is_array($row) || $now - (int) ($row['first_at'] ?? $now) > self::FAILURE_WINDOW_SECONDS) {
            $row = [
                'count' => 0,
                'first_at' => $now,
                'locked_until' => 0,
            ];
        }

        $row['count'] = (int) ($row['count'] ?? 0) + 1;
        if ((int) $row['count'] >= self::MAX_FAILURES) {
            $row['locked_until'] = $now + self::FAILURE_LOCK_SECONDS;
        }
        $_SESSION[self::SESSION_KEY]['failures'][$key] = $row;
    }

    private function clearFailures(string $scope, string $identifier): void
    {
        unset($_SESSION[self::SESSION_KEY]['failures'][$this->failureKey($scope, $identifier)]);
    }

    private function failureKey(string $scope, string $identifier): string
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            $identifier = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        }

        return sha1($scope . '|' . $identifier);
    }

    private function requestUri(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        return str_starts_with($uri, '/') && !str_starts_with($uri, '//') ? $uri : '/';
    }

    private function deny(string $permission): never
    {
        http_response_code(403);
        echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>无权限</title><link rel="stylesheet" href="/assets/app.css"></head><body class="auth-page"><main class="login-card"><h1>无权限访问</h1><p>当前账号缺少“' . \e($permission) . '”权限，请联系公司管理员调整员工权限。</p><p><a class="btn primary" href="javascript:history.back()">返回上一页</a></p></main></body></html>';
        exit;
    }
}
