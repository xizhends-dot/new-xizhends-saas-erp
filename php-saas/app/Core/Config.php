<?php

declare(strict_types=1);

namespace Xizhen\Core;

final class Config
{
    /** @param array<string, mixed> $values */
    private function __construct(private readonly string $basePath, private readonly array $values)
    {
    }

    public static function load(string $basePath): self
    {
        $file = $basePath . '/config/app.php';
        $values = is_file($file) ? require $file : [];

        return new self($basePath, is_array($values) ? $values : []);
    }

    public function requestedDriver(): string
    {
        $driver = strtolower((string) $this->env('DATA_DRIVER', $this->get('data_driver', 'json')));
        return in_array($driver, ['json', 'mysql'], true) ? $driver : 'json';
    }

    public function effectiveDriver(): string
    {
        return $this->fallbackReason() === null ? $this->requestedDriver() : 'json';
    }

    public function fallbackReason(): ?string
    {
        if ($this->requestedDriver() !== 'mysql') {
            return null;
        }

        if (!extension_loaded('pdo_mysql')) {
            return '当前 PHP 环境缺少 pdo_mysql，暂时回退到 JSON 开发数据。';
        }

        if ($this->mysqlDsn() === '') {
            return '已请求 MySQL，但未配置 MYSQL_DSN 主库连接。';
        }

        return null;
    }

    public function jsonPath(): string
    {
        return (string) $this->get('json_path', $this->basePath . '/storage/data/app.json');
    }

    public function mysqlDsn(): string
    {
        return (string) $this->env('MYSQL_DSN', $this->get('mysql.dsn', ''));
    }

    public function mysqlUser(): string
    {
        return (string) $this->env('MYSQL_USER', $this->get('mysql.user', ''));
    }

    public function mysqlPassword(): string
    {
        return (string) $this->env('MYSQL_PASSWORD', $this->get('mysql.password', ''));
    }

    public function tenantDsn(string $tenantKey): string
    {
        $envKey = 'MYSQL_TENANT_DSN_' . preg_replace('/[^A-Z0-9]+/', '_', strtoupper($tenantKey));
        return (string) $this->env($envKey, $this->get('mysql.tenant_dsn.' . $tenantKey, ''));
    }

    /** @return array<string, mixed> */
    public function diagnostics(): array
    {
        return [
            'requested_driver' => $this->requestedDriver(),
            'effective_driver' => $this->effectiveDriver(),
            'fallback_reason' => $this->fallbackReason(),
            'json_path' => $this->jsonPath(),
            'mysql_dsn' => $this->maskDsn($this->mysqlDsn()),
            'mysql_user' => $this->mysqlUser() !== '' ? $this->mysqlUser() : '未配置',
            'extensions' => [
                'PDO' => class_exists(\PDO::class),
                'pdo_mysql' => extension_loaded('pdo_mysql'),
                'mysqli' => extension_loaded('mysqli'),
                'imap' => extension_loaded('imap'),
                'mbstring' => extension_loaded('mbstring'),
            ],
            'schema_sources' => [
                '主库' => 'migrations/master/*.sql',
                '租户库' => 'migrations/tenant/*.sql',
                '接口说明' => '物流、导入导出、订单同步由当前 SaaS 服务维护',
            ],
        ];
    }

    private function env(string $key, mixed $default): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return $value;
    }

    private function get(string $path, mixed $default): mixed
    {
        $value = $this->values;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private function maskDsn(string $dsn): string
    {
        if ($dsn === '') {
            return '未配置';
        }

        return preg_replace('/(password|pwd)=([^;]+)/i', '$1=***', $dsn) ?? $dsn;
    }
}
