<?php

declare(strict_types=1);

namespace Xizhen\Repositories;

use Xizhen\Core\Db;

final class StoreRepository extends BaseRepository
{
    public function __construct(Db $db, private readonly BillingRepository $billingRepository)
    {
        parent::__construct($db);
    }



    /** @return array<int, array<string, mixed>> */
    public function stores(string $tenantKey): array
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo) {
            return [];
        }

        $select = ['id', 'platform', 'dpqz', 'dpquancheng', 'is_hidden', 'created_at'];
        foreach ([
            'legacy_dpid',
            'api_config',
            'profit_deduction',
            'hidden_reason',
            'rms_service_secret',
            'rms_license_key',
            'rms_credentials_updated_at',
            'last_sync_at',
            'last_sync_status',
            'last_sync_message',
        ] as $column) {
            if ($this->columnExists($tenantPdo, 'stores', $column)) {
                $select[] = $column;
            }
        }

        $rows = $tenantPdo->query('SELECT ' . implode(', ', $select) . ' FROM stores ORDER BY platform, id')->fetchAll();
        return array_map(function (array $row): array {
            $apiConfig = $this->jsonArray($row['api_config'] ?? null);
            if (($row['rms_service_secret'] ?? '') !== '') {
                $apiConfig['Secret'] = (string) $row['rms_service_secret'];
            }
            if (($row['rms_license_key'] ?? '') !== '') {
                $apiConfig['Key'] = (string) $row['rms_license_key'];
            }

            $apiConfigJson = $apiConfig ? json_encode($apiConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
            $lastStatus = (string) ($row['last_sync_status'] ?? '');

            return [
                'id' => (int) $row['id'],
                'legacy_dpid' => (string) (($row['legacy_dpid'] ?? '') ?: $row['id']),
                'platform' => (string) $row['platform'],
                'short' => (string) $row['dpqz'],
                'name' => (string) $row['dpquancheng'],
                'status' => (bool) $row['is_hidden'] ? 'hidden' : 'visible',
                'api_status' => $lastStatus !== '' ? $lastStatus : ($apiConfig ? '已配置' : '未配置'),
                'api_config' => (string) ($apiConfigJson ?: ''),
                'profit_deduction' => (float) ($row['profit_deduction'] ?? 70),
                'hidden_reason' => (string) (($row['hidden_reason'] ?? '') ?: ((bool) $row['is_hidden'] ? '旧系统隐藏店铺' : '')),
                'last_sync_at' => (string) ($row['last_sync_at'] ?? ''),
                'last_sync_status' => $lastStatus,
                'last_sync_message' => (string) ($row['last_sync_message'] ?? ''),
                'created_by' => '租户管理员',
                'created_at' => (string) $row['created_at'],
            ];
        }, $rows);

        $rows = $tenantPdo->query('SELECT id, platform, dpqz, dpquancheng, is_hidden, created_at FROM stores ORDER BY platform, id')->fetchAll();
        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'legacy_dpid' => (string) $row['id'],
            'platform' => (string) $row['platform'],
            'short' => (string) $row['dpqz'],
            'name' => (string) $row['dpquancheng'],
            'status' => (bool) $row['is_hidden'] ? 'hidden' : 'visible',
            'api_status' => '待配置',
            'api_config' => '',
            'profit_deduction' => 70,
            'hidden_reason' => (bool) $row['is_hidden'] ? '旧系统隐藏店铺' : '',
            'created_by' => '租户管理员',
            'created_at' => (string) $row['created_at'],
        ], $rows);
    }



    /** @return array<string, mixed>|null */
    public function store(string $tenantKey, int $storeId): ?array
    {
        foreach ($this->stores($tenantKey) as $store) {
            if ((int) ($store['id'] ?? 0) === $storeId) {
                return $store;
            }
        }

        return null;
    }



    /** @param array<string, mixed> $data */
    public function addStore(string $tenantKey, array $data): bool
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo) {
            return false;
        }

        $short = trim((string) ($data['short'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($short === '' || $name === '') {
            return false;
        }

        $apiConfig = $this->normalizeStoreApiConfig($data['api_config'] ?? null);
        $columns = ['platform', 'dpqz', 'dpquancheng', 'is_hidden'];
        $placeholders = ['?', '?', '?', '?'];
        $params = [
            preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($data['platform'] ?? 'y')) ?: 'y',
            $short,
            $name,
            ($data['status'] ?? 'visible') === 'hidden' ? 1 : 0,
        ];

        foreach ([
            'legacy_dpid' => trim((string) ($data['legacy_dpid'] ?? '')),
            'api_config' => $apiConfig ? json_encode($apiConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'profit_deduction' => $this->percentValue($data['profit_deduction'] ?? 70),
            'hidden_reason' => trim((string) ($data['hidden_reason'] ?? '')),
            'rms_service_secret' => $apiConfig['Secret'] ?? null,
            'rms_license_key' => $apiConfig['Key'] ?? null,
            'rms_credentials_updated_at' => ($apiConfig['Secret'] ?? '') !== '' || ($apiConfig['Key'] ?? '') !== '' ? date('Y-m-d H:i:s') : null,
        ] as $column => $value) {
            if (!$this->columnExists($tenantPdo, 'stores', $column)) {
                continue;
            }

            $columns[] = $column;
            $placeholders[] = '?';
            $params[] = $value;
        }

        $stmt = $tenantPdo->prepare('INSERT INTO stores (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
        $stmt->execute($params);
        $storeId = (int) $tenantPdo->lastInsertId();
        if ($storeId > 0) {
            $this->billingRepository->createStoreBillingSubscription($tenantKey, $storeId, $name);
        }
        return true;

        $stmt = $tenantPdo->prepare('INSERT INTO stores (platform, dpqz, dpquancheng, is_hidden) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($data['platform'] ?? 'y')) ?: 'y',
            $short,
            $name,
            ($data['status'] ?? 'visible') === 'hidden' ? 1 : 0,
        ]);
        $storeId = (int) $tenantPdo->lastInsertId();
        if ($storeId > 0) {
            $this->billingRepository->createStoreBillingSubscription($tenantKey, $storeId, $name);
        }
        return true;
    }



    /** @param array<string, mixed> $data */
    public function updateStore(string $tenantKey, int $storeId, array $data): void
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo || $storeId <= 0) {
            return;
        }

        $short = trim((string) ($data['short'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($short === '' || $name === '') {
            return;
        }

        $apiConfig = $this->normalizeStoreApiConfig($data['api_config'] ?? null);
        $sets = ['platform = ?', 'dpqz = ?', 'dpquancheng = ?', 'is_hidden = ?'];
        $params = [
            preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($data['platform'] ?? 'y')) ?: 'y',
            $short,
            $name,
            ($data['status'] ?? 'visible') === 'hidden' ? 1 : 0,
        ];

        foreach ([
            'legacy_dpid' => trim((string) ($data['legacy_dpid'] ?? '')),
            'api_config' => $apiConfig ? json_encode($apiConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'profit_deduction' => $this->percentValue($data['profit_deduction'] ?? 70),
            'hidden_reason' => trim((string) ($data['hidden_reason'] ?? '')),
            'rms_service_secret' => $apiConfig['Secret'] ?? null,
            'rms_license_key' => $apiConfig['Key'] ?? null,
            'rms_credentials_updated_at' => ($apiConfig['Secret'] ?? '') !== '' || ($apiConfig['Key'] ?? '') !== '' ? date('Y-m-d H:i:s') : null,
        ] as $column => $value) {
            if (!$this->columnExists($tenantPdo, 'stores', $column)) {
                continue;
            }

            $sets[] = "{$column} = ?";
            $params[] = $value;
        }

        $params[] = $storeId;
        $stmt = $tenantPdo->prepare('UPDATE stores SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
        return;

        $stmt = $tenantPdo->prepare('UPDATE stores SET platform = ?, dpqz = ?, dpquancheng = ?, is_hidden = ? WHERE id = ?');
        $stmt->execute([
            preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($data['platform'] ?? 'y')) ?: 'y',
            $short,
            $name,
            ($data['status'] ?? 'visible') === 'hidden' ? 1 : 0,
            $storeId,
        ]);
    }



    /** @param array<string, mixed> $patch */
    public function mergeStoreApiConfig(string $tenantKey, int $storeId, array $patch, string $apiStatus = '已配置'): void
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo || $storeId <= 0 || !$patch || !$this->columnExists($tenantPdo, 'stores', 'api_config')) {
            return;
        }

        $stmt = $tenantPdo->prepare('SELECT api_config FROM stores WHERE id = ? LIMIT 1');
        $stmt->execute([$storeId]);
        $config = $this->normalizeStoreApiConfig($stmt->fetchColumn() ?: null);
        foreach ($patch as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            $config[$key] = is_scalar($value) ? (string) $value : $value;
        }

        $sets = ['api_config = ?'];
        $params = [json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        if ($this->columnExists($tenantPdo, 'stores', 'last_sync_status')) {
            $sets[] = 'last_sync_status = ?';
            $params[] = $apiStatus !== '' ? $apiStatus : '已配置';
        }
        if ($this->columnExists($tenantPdo, 'stores', 'rms_credentials_updated_at')) {
            $sets[] = 'rms_credentials_updated_at = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        $params[] = $storeId;

        $tenantPdo->prepare('UPDATE stores SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }



    public function markStoreSync(string $tenantKey, int $storeId, string $status, string $message): void
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo || $storeId <= 0) {
            return;
        }

        $sets = [];
        $params = [];
        foreach ([
            'last_sync_at' => date('Y-m-d H:i:s'),
            'last_sync_status' => $this->shortText($status, 64),
            'last_sync_message' => $this->shortText($message, 1024),
        ] as $column => $value) {
            if (!$this->columnExists($tenantPdo, 'stores', $column)) {
                continue;
            }
            $sets[] = "{$column} = ?";
            $params[] = $value;
        }

        if (!$sets) {
            return;
        }

        $params[] = $storeId;
        $tenantPdo->prepare('UPDATE stores SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }



    /** @return array<string, mixed> */
    private function normalizeStoreApiConfig(mixed $value): array
    {
        $config = $this->jsonArray($value);
        if (!$config && is_string($value)) {
            foreach (preg_split('/\r\n|\r|\n/', $value) ?: [] as $line) {
                if (!str_contains($line, '=')) {
                    continue;
                }
                [$key, $raw] = explode('=', $line, 2);
                $key = trim($key);
                if ($key !== '') {
                    $config[$key] = trim($raw);
                }
            }
        }

        foreach (['serviceSecret' => 'Secret', 'service_secret' => 'Secret', 'licenseKey' => 'Key', 'license_key' => 'Key'] as $from => $to) {
            if (($config[$to] ?? '') === '' && ($config[$from] ?? '') !== '') {
                $config[$to] = $config[$from];
            }
        }

        return array_filter($config, static fn (mixed $item): bool => $item !== null && $item !== '');
    }



    private function percentValue(mixed $value): float
    {
        $number = $this->moneyValue($value, 70.0);
        return max(0.0, min(100.0, $number));
    }
}
