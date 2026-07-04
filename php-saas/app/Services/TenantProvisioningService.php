<?php

declare(strict_types=1);

namespace Xizhen\Services;

use PDO;
use Throwable;
use Xizhen\Core\Config;

final class TenantProvisioningService
{
    private const RESERVED_SUBDOMAINS = ['saas', 'www', 'admin', 'mail', 'api', 'static'];

    public function __construct(
        private readonly PDO $master,
        private readonly Config $config,
        private readonly \Closure $ensureBillingAccount,
        private readonly \Closure $adjustTenantPoints
    ) {
    }

    /** @param array<string, mixed> $data @return array{ok: bool, message: string} */
    public function createTenant(array $data): array
    {
        $data = self::normalizeInput($data, $this->defaultDbHost());
        $errors = self::validateInput($data);
        if ($errors) {
            return ['ok' => false, 'message' => implode('；', $errors)];
        }

        $subdomain = (string) $data['subdomain'];
        $dbName = (string) $data['db_name'];
        $dbHost = (string) $data['db_host'];
        $dsn = self::buildTenantDsn($dbHost, $dbName);
        $createdDatabase = false;

        try {
            if ($this->tenantExists($subdomain)) {
                return ['ok' => false, 'message' => '子域名已存在，请更换后重试。'];
            }

            $databaseExisted = $this->databaseExists($dbName);
            if ($databaseExisted) {
                return ['ok' => false, 'message' => '目标数据库已存在，请更换数据库名。'];
            }

            $this->createDatabase($dbName);
            $createdDatabase = true;
            $tenantPdo = $this->connectTenant($dsn);
            $this->runTenantMigrations($tenantPdo);

            $this->master->beginTransaction();
            try {
                $tenantId = $this->insertTenant($data, $dsn);
                $this->insertDefaultPlatforms($tenantId);
                ($this->ensureBillingAccount)($tenantId);
                $initialPoints = max(0, (int) $data['initial_points']);
                if ($initialPoints > 0) {
                    ($this->adjustTenantPoints)($subdomain, $initialPoints, 'recharge', '开通初始积分', (string) $data['operator']);
                }
                $this->insertInitialAdmin($tenantPdo, $data);
                $this->master->commit();
            } catch (Throwable $error) {
                if ($this->master->inTransaction()) {
                    $this->master->rollBack();
                }
                throw $error;
            }

            return ['ok' => true, 'message' => '租户已开通：' . $subdomain];
        } catch (Throwable $error) {
            if ($createdDatabase) {
                $this->dropDatabase($dbName);
            }

            return ['ok' => false, 'message' => '开通失败：' . $error->getMessage()];
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function normalizeInput(array $data, string $defaultDbHost = '127.0.0.1'): array
    {
        $subdomain = strtolower(trim((string) ($data['subdomain'] ?? '')));
        $dbName = strtolower(trim((string) ($data['db_name'] ?? '')));
        if ($dbName === '' && $subdomain !== '') {
            $dbName = self::defaultDbName($subdomain);
        }

        $plan = strtolower(trim((string) ($data['plan'] ?? 'basic')));
        if (!in_array($plan, ['basic', 'pro', 'ent'], true)) {
            $plan = 'basic';
        }

        return [
            'company_name' => trim((string) ($data['company_name'] ?? '')),
            'company_short_name' => trim((string) ($data['company_short_name'] ?? '')),
            'subdomain' => $subdomain,
            'db_name' => $dbName,
            'db_host' => trim((string) ($data['db_host'] ?? '')) ?: $defaultDbHost,
            'plan' => $plan,
            'contact_name' => trim((string) ($data['contact_name'] ?? ($data['contact'] ?? ''))),
            'contact_phone' => trim((string) ($data['contact_phone'] ?? ($data['phone'] ?? ''))),
            'contact_email' => trim((string) ($data['contact_email'] ?? '')),
            'contact_wechat' => trim((string) ($data['contact_wechat'] ?? '')),
            'address' => trim((string) ($data['address'] ?? '')),
            'remark' => trim((string) ($data['remark'] ?? ($data['note'] ?? ''))),
            'admin_username' => trim((string) ($data['admin_username'] ?? '')),
            'admin_password' => (string) ($data['admin_password'] ?? ''),
            'initial_points' => max(0, (int) ($data['initial_points'] ?? 0)),
            'operator' => trim((string) ($data['operator'] ?? 'superadmin')) ?: 'superadmin',
        ];
    }

    /** @param array<string, mixed> $data @return array<int, string> */
    public static function validateInput(array $data): array
    {
        $errors = [];
        $companyName = trim((string) ($data['company_name'] ?? ''));
        if ($companyName === '' || self::length($companyName) > 128) {
            $errors[] = '公司名必填且不能超过 128 个字符';
        }
        if (self::length((string) ($data['company_short_name'] ?? '')) > 64) {
            $errors[] = '公司简称不能超过 64 个字符';
        }
        $subdomainError = self::validateSubdomain((string) ($data['subdomain'] ?? ''));
        if ($subdomainError !== null) {
            $errors[] = $subdomainError;
        }
        $dbNameError = self::validateDbName((string) ($data['db_name'] ?? ''));
        if ($dbNameError !== null) {
            $errors[] = $dbNameError;
        }
        if (trim((string) ($data['db_host'] ?? '')) === '' || self::length((string) ($data['db_host'] ?? '')) > 255) {
            $errors[] = '数据库主机必填且不能超过 255 个字符';
        }
        if (!in_array((string) ($data['plan'] ?? 'basic'), ['basic', 'pro', 'ent'], true)) {
            $errors[] = '套餐只能选择 basic、pro 或 ent';
        }
        foreach ([
            'contact_name' => ['联系人', 64],
            'contact_phone' => ['联系电话', 32],
            'contact_email' => ['联系邮箱', 128],
            'contact_wechat' => ['联系人微信', 64],
            'address' => ['地址', 255],
            'remark' => ['备注', 1000],
        ] as $field => [$label, $limit]) {
            if (self::length((string) ($data[$field] ?? '')) > $limit) {
                $errors[] = $label . '不能超过 ' . $limit . ' 个字符';
            }
        }
        if (trim((string) ($data['admin_username'] ?? '')) === '' || self::length((string) ($data['admin_username'] ?? '')) > 128) {
            $errors[] = '初始管理员用户名必填且不能超过 128 个字符';
        }
        if (self::length((string) ($data['admin_password'] ?? '')) < 8) {
            $errors[] = '初始管理员密码至少 8 位';
        }
        if ((int) ($data['initial_points'] ?? 0) < 0) {
            $errors[] = '初始积分不能小于 0';
        }

        return $errors;
    }

    public static function validateSubdomain(string $subdomain): ?string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $subdomain)) {
            return '子域名只能包含小写字母、数字、短横线，且必须以字母或数字开头';
        }
        if (in_array($subdomain, self::RESERVED_SUBDOMAINS, true)) {
            return '该子域名为系统保留字';
        }

        return null;
    }

    public static function isValidSubdomain(string $subdomain): bool
    {
        return self::validateSubdomain($subdomain) === null;
    }

    public static function validateDbName(string $dbName): ?string
    {
        return preg_match('/^[a-z0-9_]{1,64}$/', $dbName)
            ? null
            : '数据库名只能包含小写字母、数字、下划线，长度 1-64';
    }

    public static function isValidDbName(string $dbName): bool
    {
        return self::validateDbName($dbName) === null;
    }

    public static function defaultDbName(string $subdomain): string
    {
        $subdomain = strtolower(trim($subdomain));
        $subdomain = preg_replace('/[^a-z0-9-]/', '', $subdomain) ?? '';
        return 'xizhen_tenant_' . str_replace('-', '_', $subdomain);
    }

    public static function buildTenantDsn(string $dbHost, string $dbName): string
    {
        return 'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4';
    }

    /** @return array<int, string> */
    public static function splitSqlStatements(string $sql): array
    {
        $sql = self::stripSqlLineComments($sql);
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $prev = $i > 0 ? $sql[$i - 1] : '';
            if (($char === "'" || $char === '"') && $prev !== '\\') {
                if ($quote === null) {
                    $quote = $char;
                } elseif ($quote === $char) {
                    $quote = null;
                }
            }
            if ($char === ';' && $quote === null) {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }

        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }

    private static function stripSqlLineComments(string $sql): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $sql);
        $result = [];
        foreach ($lines === false ? [] : $lines as $line) {
            $quote = null;
            $clean = '';
            $length = strlen($line);
            for ($i = 0; $i < $length; $i++) {
                $char = $line[$i];
                $next = $i + 1 < $length ? $line[$i + 1] : '';
                $prev = $i > 0 ? $line[$i - 1] : '';
                if (($char === "'" || $char === '"') && $prev !== '\\') {
                    if ($quote === null) {
                        $quote = $char;
                    } elseif ($quote === $char) {
                        $quote = null;
                    }
                }
                if ($char === '-' && $next === '-' && $quote === null) {
                    break;
                }
                $clean .= $char;
            }
            $result[] = $clean;
        }

        return implode("\n", $result);
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function defaultDbHost(): string
    {
        if (preg_match('/(?:^|;)host=([^;]+)/', $this->config->mysqlDsn(), $matches)) {
            return $matches[1];
        }

        return '127.0.0.1';
    }

    private function tenantExists(string $subdomain): bool
    {
        $stmt = $this->master->prepare('SELECT COUNT(*) FROM tenants WHERE subdomain = ?');
        $stmt->execute([$subdomain]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function databaseExists(string $dbName): bool
    {
        $stmt = $this->master->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1');
        $stmt->execute([$dbName]);

        return $stmt->fetchColumn() !== false;
    }

    private function createDatabase(string $dbName): void
    {
        if (!self::isValidDbName($dbName)) {
            throw new \InvalidArgumentException('数据库名非法。');
        }

        $this->master->exec('CREATE DATABASE IF NOT EXISTS `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    private function dropDatabase(string $dbName): void
    {
        if (!self::isValidDbName($dbName)) {
            return;
        }

        try {
            $this->master->exec('DROP DATABASE `' . $dbName . '`');
        } catch (Throwable) {
        }
    }

    private function connectTenant(string $dsn): PDO
    {
        $pdo = new PDO($dsn, $this->config->mysqlUser(), $this->config->mysqlPassword(), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('SET NAMES utf8mb4');

        return $pdo;
    }

    private function runTenantMigrations(PDO $tenantPdo): void
    {
        $files = glob(BASE_PATH . '/../migrations/tenant/*.sql');
        if (!is_array($files) || !$files) {
            throw new \RuntimeException('未找到租户迁移文件。');
        }
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException('无法读取迁移文件：' . basename($file));
            }
            foreach (self::splitSqlStatements($sql) as $statement) {
                $tenantPdo->exec($statement);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function insertTenant(array $data, string $dsn): int
    {
        $columns = ['company_name', 'subdomain', 'db_dsn_enc', 'plan', 'status', 'staff_count'];
        $values = ['?', '?', '?', '?', "'active'", '0'];
        $params = [
            $data['company_name'],
            $data['subdomain'],
            $dsn,
            $data['plan'],
        ];

        foreach ([
            'company_short_name',
            'contact_name',
            'contact_phone',
            'contact_email',
            'contact_wechat',
            'address',
            'remark',
        ] as $column) {
            if ($this->columnExists('tenants', $column)) {
                $columns[] = $column;
                $values[] = '?';
                $params[] = (string) ($data[$column] ?? '');
            }
        }

        $this->master
            ->prepare('INSERT INTO tenants (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')')
            ->execute($params);

        return (int) $this->master->lastInsertId();
    }

    private function insertDefaultPlatforms(int $tenantId): void
    {
        if ($tenantId <= 0 || !$this->tableExists('tenant_platform') || !$this->tableExists('platforms')) {
            return;
        }

        $codes = $this->master->query('SELECT code FROM platforms WHERE default_enabled = 1 ORDER BY sort_order, code')->fetchAll(PDO::FETCH_COLUMN);
        $insert = $this->master->prepare('INSERT IGNORE INTO tenant_platform (tenant_id, platform_code, enabled, locked) VALUES (?, ?, 1, 0)');
        foreach ($codes as $code) {
            $insert->execute([$tenantId, (string) $code]);
        }
    }

    /** @param array<string, mixed> $data */
    private function insertInitialAdmin(PDO $tenantPdo, array $data): void
    {
        $columns = ['username', 'password_hash', 'is_company_admin', 'role', 'permissions', 'dpquancheng', 'is_active'];
        $values = ['?', '?', '1', '?', '?', '?', '1'];
        $params = [
            $data['admin_username'],
            AuthService::makePasswordHash((string) $data['admin_password']),
            '公司管理员',
            json_encode([], JSON_UNESCAPED_UNICODE),
            '全部店铺',
        ];

        foreach ([
            'display_name' => (string) (($data['contact_name'] ?? '') ?: '公司管理员'),
            'preference_module' => 'dashboard',
        ] as $column => $value) {
            if ($this->tenantColumnExists($tenantPdo, 'users', $column)) {
                $columns[] = $column;
                $values[] = '?';
                $params[] = $value;
            }
        }
        if ($this->tenantColumnExists($tenantPdo, 'users', 'password_reset_at')) {
            $columns[] = 'password_reset_at';
            $values[] = 'NOW()';
        }

        $tenantPdo
            ->prepare('INSERT INTO users (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')')
            ->execute($params);
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->master->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);

        return $stmt->fetchColumn() !== false;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->master->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function tenantColumnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
