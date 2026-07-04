<?php

declare(strict_types=1);

namespace Xizhen\Repositories;

use Xizhen\Core\Permission;

final class UserRepository extends BaseRepository
{


    /** @return array<int, array<string, mixed>> */
    public function users(string $tenantKey): array
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo) {
            return [];
        }

        $select = ['id', 'username', 'password_hash', 'legacy_password', 'is_company_admin', 'role', 'permissions', 'dpquancheng', 'is_active', 'created_at'];
        foreach (['display_name', 'preference_module', 'api_1688_config', 'password_reset_at', 'last_login_at', 'permission_overrides', 'profit_deduction'] as $column) {
            if ($this->columnExists($tenantPdo, 'users', $column)) {
                $select[] = $column;
            }
        }

        $rows = $tenantPdo->query('SELECT ' . implode(', ', $select) . ' FROM users ORDER BY id')->fetchAll();
        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) (($row['display_name'] ?? '') ?: $row['username']),
            'username' => (string) $row['username'],
            'role' => (bool) $row['is_company_admin'] ? '公司管理员' : Permission::normalizeRole($row['role'] ?? '客服'),
            'password_hash' => (string) ($row['password_hash'] ?? ''),
            'legacy_password' => (string) ($row['legacy_password'] ?? ''),
            'password_reset' => '',
            'password_reset_at' => (string) ($row['password_reset_at'] ?? ''),
            'last_login_at' => (string) ($row['last_login_at'] ?? ''),
            'preference_module' => (string) ($row['preference_module'] ?? ''),
            'api_1688_config' => is_string($row['api_1688_config'] ?? null) ? (string) $row['api_1688_config'] : json_encode($row['api_1688_config'] ?? [], JSON_UNESCAPED_UNICODE),
            'is_company_admin' => (bool) $row['is_company_admin'],
            'permissions' => json_decode((string) ($row['permissions'] ?? '[]'), true) ?: [],
            'permission_overrides' => $this->jsonArray($row['permission_overrides'] ?? null),
            'profit_deduction' => isset($row['profit_deduction']) ? (float) $row['profit_deduction'] : null,
            'stores' => array_filter(array_map('trim', explode(',', (string) ($row['dpquancheng'] ?? '')))),
            'status' => (bool) $row['is_active'] ? 'active' : 'disabled',
            'created_at' => (string) $row['created_at'],
        ], $rows);
    }



    /** @return array<string, mixed>|null */
    public function user(string $tenantKey, int $userId): ?array
    {
        foreach ($this->users($tenantKey) as $user) {
            if ((int) ($user['id'] ?? 0) === $userId) {
                return $user;
            }
        }

        return null;
    }



    /** @return array<string, mixed>|null */
    public function tenantUserByUsername(string $tenantKey, string $username): ?array
    {
        $username = trim($username);
        if ($username === '') {
            return null;
        }

        foreach ($this->users($tenantKey) as $user) {
            if (hash_equals((string) ($user['username'] ?? ''), $username)) {
                return $user;
            }
        }

        return null;
    }



    public function updateTenantUserPassword(string $tenantKey, int $userId, string $passwordHash): void
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo || $userId <= 0 || $passwordHash === '') {
            return;
        }

        $assignments = [
            'password_hash = ?',
            'legacy_password = NULL',
        ];
        $params = [$passwordHash];
        if ($this->columnExists($tenantPdo, 'users', 'password_reset_at')) {
            $assignments[] = 'password_reset_at = NOW()';
        }
        $params[] = $userId;

        $stmt = $tenantPdo->prepare('UPDATE users SET ' . implode(', ', $assignments) . ' WHERE id = ?');
        $stmt->execute($params);
    }



    /** @param array{allow?: array<int, string>, deny?: array<int, string>} $overrides */
    public function updateUserPermissionOverrides(string $tenantKey, int $userId, array $overrides, string $operator): void
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo || $userId <= 0) {
            return;
        }

        $user = $this->user($tenantKey, $userId);
        if (!$user) {
            return;
        }

        $normalized = $this->normalizePermissionOverrides($overrides);
        $role = (string) ($user['role'] ?? '客服');
        $flat = array_values(array_unique(array_merge(
            Permission::roleDefaults()[Permission::normalizeRole($role)] ?? Permission::roleDefaults()['客服'],
            $normalized['allow']
        )));
        $flat = array_values(array_diff($flat, $normalized['deny']));

        $assignments = ['permissions = ?'];
        $params = [json_encode($flat, JSON_UNESCAPED_UNICODE)];
        if ($this->columnExists($tenantPdo, 'users', 'permission_overrides')) {
            $assignments[] = 'permission_overrides = ?';
            $params[] = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($this->columnExists($tenantPdo, 'users', 'updated_at')) {
            $assignments[] = 'updated_at = NOW()';
        }
        $params[] = $userId;

        $stmt = $tenantPdo->prepare('UPDATE users SET ' . implode(', ', $assignments) . ' WHERE id = ?');
        $stmt->execute($params);
    }



    public function touchTenantUserLogin(string $tenantKey, int $userId): void
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo || $userId <= 0 || !$this->columnExists($tenantPdo, 'users', 'last_login_at')) {
            return;
        }

        $tenantPdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$userId]);
    }



    /** @param array<string, mixed> $data */
    public function addUser(string $tenantKey, array $data): void
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo) {
            return;
        }

        $username = trim((string) ($data['username'] ?? ''));
        if ($username === '') {
            return;
        }

        $role = Permission::normalizeRole($data['role'] ?? '客服');
        $permissions = $this->permissionsForRole($role, $data['permissions'] ?? []);
        $stores = implode(',', array_values(array_filter(array_map('trim', (array) ($data['stores'] ?? [])))));
        $password = trim((string) ($data['password_reset'] ?? '')) ?: 'Tenant@2026';
        $columns = ['username', 'password_hash', 'is_company_admin', 'role', 'permissions', 'dpquancheng', 'is_active'];
        $values = ['?', '?', '?', '?', '?', '?', '?'];
        $params = [
            $username,
            $this->hashPassword($password),
            $role === '公司管理员' ? 1 : 0,
            $role,
            json_encode($permissions, JSON_UNESCAPED_UNICODE),
            $stores,
            ($data['status'] ?? 'active') === 'disabled' ? 0 : 1,
        ];
        if ($this->columnExists($tenantPdo, 'users', 'display_name')) {
            $columns[] = 'display_name';
            $values[] = '?';
            $params[] = trim((string) ($data['name'] ?? ''));
        }
        if ($this->columnExists($tenantPdo, 'users', 'preference_module')) {
            $columns[] = 'preference_module';
            $values[] = '?';
            $params[] = trim((string) ($data['preference_module'] ?? ''));
        }
        if (array_key_exists('api_1688_config', $data) && $this->columnExists($tenantPdo, 'users', 'api_1688_config')) {
            $columns[] = 'api_1688_config';
            $values[] = '?';
            $params[] = trim((string) $data['api_1688_config']) ?: null;
        }
        if ($this->columnExists($tenantPdo, 'users', 'password_reset_at')) {
            $columns[] = 'password_reset_at';
            $values[] = 'NOW()';
        }

        $stmt = $tenantPdo->prepare(
            'INSERT INTO users (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')'
        );
        $stmt->execute($params);
    }



    /** @param array<string, mixed> $data */
    public function updateUser(string $tenantKey, int $userId, array $data): void
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo || $userId <= 0) {
            return;
        }

        $username = trim((string) ($data['username'] ?? ''));
        if ($username === '') {
            return;
        }

        $role = Permission::normalizeRole($data['role'] ?? '客服');
        $permissions = $this->permissionsForRole($role, $data['permissions'] ?? []);
        $stores = array_values(array_filter(array_map('trim', (array) ($data['stores'] ?? []))));

        $assignments = [
            'username = ?',
            'is_company_admin = ?',
            'role = ?',
            'permissions = ?',
            'dpqz = ?',
            'dpquancheng = ?',
            'is_active = ?',
        ];
        $params = [
            $username,
            $role === '公司管理员' ? 1 : 0,
            $role,
            json_encode($permissions, JSON_UNESCAPED_UNICODE),
            implode(',', $stores),
            implode(',', $stores),
            ($data['status'] ?? 'active') === 'disabled' ? 0 : 1,
        ];
        if ($this->columnExists($tenantPdo, 'users', 'display_name')) {
            $assignments[] = 'display_name = ?';
            $params[] = trim((string) ($data['name'] ?? ''));
        }
        if ($this->columnExists($tenantPdo, 'users', 'preference_module')) {
            $assignments[] = 'preference_module = ?';
            $params[] = trim((string) ($data['preference_module'] ?? ''));
        }
        if (array_key_exists('api_1688_config', $data) && $this->columnExists($tenantPdo, 'users', 'api_1688_config')) {
            $assignments[] = 'api_1688_config = ?';
            $params[] = trim((string) $data['api_1688_config']) ?: null;
        }
        if (trim((string) ($data['password_reset'] ?? '')) !== '') {
            $assignments[] = 'password_hash = ?';
            $assignments[] = 'legacy_password = NULL';
            $params[] = $this->hashPassword(trim((string) $data['password_reset']));
            if ($this->columnExists($tenantPdo, 'users', 'password_reset_at')) {
                $assignments[] = 'password_reset_at = NOW()';
            }
        }
        $params[] = $userId;

        $stmt = $tenantPdo->prepare('UPDATE users SET ' . implode(', ', $assignments) . ' WHERE id = ?');
        $stmt->execute($params);
    }



    /** @return array<int, array<string, mixed>> */
    public function assignments(string $tenantKey): array
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo || !$this->tableExists($tenantPdo, 'buyer_support_assignments')) {
            return [];
        }

        $rows = $tenantPdo->query(<<<'SQL'
SELECT a.id, a.buyer_user_id, a.support_user_id,
       b.username AS buyer_name, b.role AS buyer_role,
       s.username AS support_name, s.username AS support_username, s.dpquancheng AS support_stores,
       a.created_at
FROM buyer_support_assignments a
LEFT JOIN users b ON b.id = a.buyer_user_id
LEFT JOIN users s ON s.id = a.support_user_id
ORDER BY a.buyer_user_id, a.support_user_id
SQL)->fetchAll();

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'buyer_user_id' => (int) $row['buyer_user_id'],
            'buyer_name' => (string) ($row['buyer_name'] ?? ''),
            'buyer_role' => (string) ($row['buyer_role'] ?? ''),
            'support_user_id' => (int) $row['support_user_id'],
            'support_name' => (string) ($row['support_name'] ?? ''),
            'support_username' => (string) ($row['support_username'] ?? ''),
            'support_stores' => array_filter(array_map('trim', explode(',', (string) ($row['support_stores'] ?? '')))),
            'created_at' => (string) $row['created_at'],
        ], $rows);
    }



    /** @param array<int, int> $supportUserIds */
    public function saveAssignmentByBuyer(string $tenantKey, int $buyerUserId, array $supportUserIds): void
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo || !$this->tableExists($tenantPdo, 'buyer_support_assignments') || $buyerUserId <= 0) {
            return;
        }

        $tenantPdo->prepare('DELETE FROM buyer_support_assignments WHERE buyer_user_id = ?')->execute([$buyerUserId]);
        $insert = $tenantPdo->prepare('INSERT INTO buyer_support_assignments (buyer_user_id, support_user_id) VALUES (?, ?)');
        foreach (array_values(array_unique(array_map('intval', $supportUserIds))) as $supportUserId) {
            if ($supportUserId > 0) {
                $insert->execute([$buyerUserId, $supportUserId]);
            }
        }
    }



    /** @param array<int, int> $buyerUserIds */
    public function saveAssignmentBySupport(string $tenantKey, int $supportUserId, array $buyerUserIds): void
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo || !$this->tableExists($tenantPdo, 'buyer_support_assignments') || $supportUserId <= 0) {
            return;
        }

        $tenantPdo->prepare('DELETE FROM buyer_support_assignments WHERE support_user_id = ?')->execute([$supportUserId]);
        $insert = $tenantPdo->prepare('INSERT INTO buyer_support_assignments (buyer_user_id, support_user_id) VALUES (?, ?)');
        foreach (array_values(array_unique(array_map('intval', $buyerUserIds))) as $buyerUserId) {
            if ($buyerUserId > 0) {
                $insert->execute([$buyerUserId, $supportUserId]);
            }
        }
    }



    private function hashPassword(string $password): string
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        return password_hash($password, $algorithm);
    }



    /**
     * @param mixed $overrides
     * @return array<int, string>
     */
    private function permissionsForRole(string $role, mixed $overrides = []): array
    {
        $defaults = Permission::roleDefaults();

        $permissions = $defaults[Permission::normalizeRole($role)] ?? $defaults['客服'];
        $extra = array_values(array_filter(array_map('trim', (array) $overrides)));
        return array_values(array_unique(array_merge($permissions, $extra)));
    }



    /**
     * @param array{allow?: array<int, string>, deny?: array<int, string>} $overrides
     * @return array{allow: array<int, string>, deny: array<int, string>}
     */
    private function normalizePermissionOverrides(array $overrides): array
    {
        return [
            'allow' => array_values(array_unique(array_filter(array_map('trim', (array) ($overrides['allow'] ?? []))))),
            'deny' => array_values(array_unique(array_filter(array_map('trim', (array) ($overrides['deny'] ?? []))))),
        ];
    }
}
