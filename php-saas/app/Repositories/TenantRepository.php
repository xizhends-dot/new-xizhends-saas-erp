<?php

declare(strict_types=1);

namespace Xizhen\Repositories;

use Xizhen\Core\Db;
use Xizhen\Core\Config;
use Xizhen\Core\TenantFeature;
use Xizhen\Services\TenantProvisioningService;

final class TenantRepository extends BaseRepository
{
    public function __construct(
        Db $db,
        private readonly Config $config,
        private readonly BillingRepository $billingRepository,
        private readonly OrderRepository $orderRepository,
        private readonly AdminRepository $adminRepository
    ) {
        parent::__construct($db);
    }



    /** @return array<string, mixed> */
    public function all(): array
    {
        $orders = [];
        foreach ($this->tenants() as $tenant) {
            $orders[$tenant['key']] = $this->orderRepository->orders((string) $tenant['key']);
        }

        return [
            'platforms' => $this->platforms(),
            'tenants' => $this->tenants(),
            'announcements' => $this->adminRepository->announcements(),
            'orders' => $orders,
        ];
    }



    /** @return array<int, array<string, mixed>> */
    public function tenants(): array
    {
        $master = $this->db->master();
        $billingSelect = '0 AS billing_balance';
        $billingJoin = '';
        if ($this->tableExists($master, 'tenant_billing_accounts')) {
            $balanceColumn = $this->columnExists($master, 'tenant_billing_accounts', 'balance_points')
                ? 'COALESCE(b.balance_points, 0)'
                : 'FLOOR(COALESCE(b.balance_cents, 0) / 100)';
            $billingSelect = "{$balanceColumn} AS billing_balance";
            $billingJoin = 'LEFT JOIN tenant_billing_accounts b ON b.tenant_id = t.id';
        }

        $sql = <<<SQL
SELECT t.*, {$billingSelect}
FROM tenants t
{$billingJoin}
ORDER BY t.id DESC
SQL;
        $rows = $this->db->master()->query($sql)->fetchAll();

        return array_map(function (array $row): array {
            $key = (string) ($row['subdomain'] ?? $row['id']);

            return [
                'id' => (int) $row['id'],
                'key' => $key,
                'company_name' => (string) ($row['company_name'] ?? ''),
                'short_name' => (string) (($row['company_short_name'] ?? '') ?: ($row['company_name'] ?? '')),
                'subdomain' => $key,
                'db_name' => (string) ($row['db_dsn_enc'] ?? ''),
                'plan' => (string) ($row['plan'] ?? 'basic'),
                'status' => (string) ($row['status'] ?? 'active'),
                'staff_count' => (int) ($row['staff_count'] ?? 0),
                'balance' => (int) ($row['billing_balance'] ?? 0),
                'contact' => (string) ($row['contact_name'] ?? ''),
                'phone' => (string) ($row['contact_phone'] ?? ''),
                'platforms' => $this->tenantPlatforms($key),
                'features' => $this->tenantFeatures($key),
            ];
        }, $rows);
    }



    /** @param array<string, mixed> $data @return array{ok: bool, message: string} */
    public function createTenant(array $data): array
    {
        $service = new TenantProvisioningService(
            $this->db->master(),
            $this->config,
            function (int $tenantId): void {
                $this->billingRepository->ensureBillingAccount($tenantId);
            },
            function (string $tenantKey, int $amount, string $type, string $note, string $operator): void {
                $this->billingRepository->adjustTenantPoints($tenantKey, $amount, $type, $note, $operator);
            }
        );

        $result = $service->createTenant($data);
        $tenantKey = (string) (TenantProvisioningService::normalizeInput($data)['subdomain'] ?? '');
        if (($result['ok'] ?? false) && $tenantKey !== '') {
            $this->db->clearTenantConnectionMiss($tenantKey);
        }

        return $result;
    }



    /** @return array<string, mixed> */
    public function tenant(string $key): array
    {
        foreach ($this->tenants() as $tenant) {
            if (($tenant['key'] ?? '') === $key) {
                return $tenant;
            }
        }

        return $this->tenants()[0] ?? [
            'key' => $key,
            'company_name' => $key,
            'plan' => 'basic',
            'platforms' => [],
        ];
    }



    /** @return array<int, array<string, mixed>> */
    public function platforms(): array
    {
        $rows = $this->db->master()
            ->query('SELECT code, name, sort_order, default_enabled FROM platforms ORDER BY sort_order, code')
            ->fetchAll();

        return array_map(fn (array $row): array => [
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'short' => (string) $row['name'],
            'color' => $this->platformColor((string) $row['code']),
            'default_enabled' => (bool) $row['default_enabled'],
        ], $rows);
    }



    /** @return array<int, array<string, mixed>> */
    public function tenantPlatforms(string $tenantKey): array
    {
        $tenantId = $this->tenantId($tenantKey);
        if ($tenantId === null) {
            return [];
        }

        $stmt = $this->db->master()->prepare(
            'SELECT platform_code AS code, enabled, locked FROM tenant_platform WHERE tenant_id = ? ORDER BY platform_code'
        );
        $stmt->execute([$tenantId]);

        return array_map(fn (array $row): array => [
            'code' => (string) $row['code'],
            'enabled' => (bool) $row['enabled'],
            'locked' => (bool) $row['locked'],
        ], $stmt->fetchAll());
    }



    /** @return array<int, array<string, mixed>> */
    public function tenantFeatures(string $tenantKey): array
    {
        $tenantId = $this->tenantId($tenantKey);
        if ($tenantId === null || !$this->tableExists($this->db->master(), 'tenant_features')) {
            return TenantFeature::defaultRows();
        }

        $insert = $this->db->master()->prepare(
            'INSERT IGNORE INTO tenant_features (tenant_id, feature_key, enabled) VALUES (?, ?, ?)'
        );
        foreach (TenantFeature::defaultMap() as $featureKey => $enabled) {
            $insert->execute([$tenantId, $featureKey, (int) $enabled]);
        }

        $stmt = $this->db->master()->prepare(
            'SELECT feature_key AS `key`, enabled FROM tenant_features WHERE tenant_id = ? ORDER BY feature_key'
        );
        $stmt->execute([$tenantId]);
        $rows = array_map(fn (array $row): array => [
            'key' => (string) $row['key'],
            'enabled' => (bool) $row['enabled'],
        ], $stmt->fetchAll());

        return TenantFeature::normalizeRows($rows);
    }



    public function togglePlatform(string $tenantKey, string $platformCode, string $field): void
    {
        if (!in_array($field, ['enabled', 'locked'], true)) {
            return;
        }

        $tenantId = $this->tenantId($tenantKey);
        if ($tenantId === null) {
            return;
        }

        $sql = "UPDATE tenant_platform SET {$field} = CASE WHEN {$field} = 1 THEN 0 ELSE 1 END WHERE tenant_id = ? AND platform_code = ?";
        $stmt = $this->db->master()->prepare($sql);
        $stmt->execute([$tenantId, $platformCode]);
    }



    public function toggleTenantFeature(string $tenantKey, string $featureKey): void
    {
        if (!TenantFeature::isKnown($featureKey) || !$this->tableExists($this->db->master(), 'tenant_features')) {
            return;
        }

        $tenantId = $this->tenantId($tenantKey);
        if ($tenantId === null) {
            return;
        }

        $insert = $this->db->master()->prepare(
            'INSERT IGNORE INTO tenant_features (tenant_id, feature_key, enabled) VALUES (?, ?, ?)'
        );
        $insert->execute([$tenantId, $featureKey, (int) (TenantFeature::defaultMap()[$featureKey] ?? false)]);

        $stmt = $this->db->master()->prepare(
            'UPDATE tenant_features SET enabled = CASE WHEN enabled = 1 THEN 0 ELSE 1 END WHERE tenant_id = ? AND feature_key = ?'
        );
        $stmt->execute([$tenantId, $featureKey]);
    }



    private function platformColor(string $code): string
    {
        return [
            'y' => '#ef4444',
            'r' => '#2563eb',
            'w' => '#14b8a6',
            'm' => '#06b6d4',
            'q' => '#8b5cf6',
            'yp' => '#64748b',
        ][$code] ?? '#64748b';
    }
}
