<?php

declare(strict_types=1);

namespace Xizhen\Core;

final class Db
{
    private ?\PDO $master = null;

    /** @var array<string, \PDO> */
    private array $tenantConnections = [];

    /** @var array<string, bool> */
    private array $tenantConnectionMisses = [];

    public function __construct(private readonly Config $config)
    {
    }



    public function master(): \PDO
    {
        if ($this->master instanceof \PDO) {
            return $this->master;
        }

        $this->master = $this->connect($this->config->mysqlDsn());
        return $this->master;
    }



    public function tenantPdo(string $tenantKey): ?\PDO
    {
        if (isset($this->tenantConnections[$tenantKey])) {
            return $this->tenantConnections[$tenantKey];
        }

        $dsn = $this->config->tenantDsn($tenantKey);
        if ($dsn === '') {
            if (isset($this->tenantConnectionMisses[$tenantKey])) {
                return null;
            }
            $stmt = $this->master()->prepare('SELECT db_dsn_enc FROM tenants WHERE subdomain = ? LIMIT 1');
            $stmt->execute([$tenantKey]);
            $dsn = trim((string) ($stmt->fetchColumn() ?: ''));
            if ($dsn === '' || !str_starts_with($dsn, 'mysql:')) {
                $this->tenantConnectionMisses[$tenantKey] = true;
                return null;
            }
        }

        $this->tenantConnections[$tenantKey] = $this->connect($dsn);
        return $this->tenantConnections[$tenantKey];
    }

    public function clearTenantConnectionMiss(string $tenantKey): void
    {
        unset($this->tenantConnectionMisses[$tenantKey]);
    }



    private function connect(string $dsn): \PDO
    {
        $pdo = new \PDO($dsn, $this->config->mysqlUser(), $this->config->mysqlPassword(), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('SET NAMES utf8mb4');

        return $pdo;
    }
}
