<?php

declare(strict_types=1);

namespace Xizhen\Repositories;

use Xizhen\Core\Db;

final class SettingsRepository extends BaseRepository
{
    public function __construct(Db $db, private readonly TenantRepository $tenantRepository)
    {
        parent::__construct($db);
    }



    /** @return array<string, mixed> */
    public function tenantSettings(string $tenantKey): array
    {
        $settings = $this->defaultTenantSettings($this->tenantRepository->tenant($tenantKey));
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo || !$this->tableExists($tenantPdo, 'tenant_settings')) {
            return $settings;
        }

        $rows = $tenantPdo->query('SELECT setting_key, setting_value FROM tenant_settings')->fetchAll();
        foreach ($rows as $row) {
            $key = (string) ($row['setting_key'] ?? '');
            $value = json_decode((string) ($row['setting_value'] ?? ''), true);
            if ($key !== '' && is_array($value)) {
                $settings[$key] = array_replace_recursive(is_array($settings[$key] ?? null) ? $settings[$key] : [], $value);
            }
        }

        return $settings;
    }



    /** @param array<string, mixed> $data */
    public function saveTenantSettings(string $tenantKey, array $data): void
    {
        $settings = array_replace_recursive($this->tenantSettings($tenantKey), $data);
        if (array_key_exists('export_templates', $data)) {
            $settings['export_templates'] = $data['export_templates'];
        }
        if (array_key_exists('purchase_statuses', $data)) {
            $settings['purchase_statuses'] = $data['purchase_statuses'];
        }
        if (array_key_exists('jp_stock_purchase_statuses', $data)) {
            $settings['jp_stock_purchase_statuses'] = $data['jp_stock_purchase_statuses'];
        }
        if (array_key_exists('order_export_tools', $data)) {
            $settings['order_export_tools'] = $data['order_export_tools'];
        }
        $settings['updated_at'] = date('Y-m-d H:i:s');

        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if ($tenantPdo && $this->tableExists($tenantPdo, 'tenant_settings')) {
            $stmt = $tenantPdo->prepare(
                'INSERT INTO tenant_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
            );
            foreach (['company', 'orders', 'profit', 'logistics', 'api_1688', 'notices', 'export_templates', 'purchase_statuses', 'jp_stock_purchase_statuses', 'order_export_tools'] as $section) {
                $stmt->execute([
                    $section,
                    // INVALID_UTF8_SUBSTITUTE：个别非法字节替换为 U+FFFD，避免 json_encode
                    // 返回 false 导致空串写入 JSON 列而整次保存失败
                    json_encode($settings[$section] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                ]);
            }
        }

        $this->updateTenantProfile($tenantKey, is_array($settings['company'] ?? null) ? $settings['company'] : []);
    }



    /** @return array<int, array<string, mixed>> */
    public function tenantNotices(string $tenantKey): array
    {
        $settings = $this->tenantSettings($tenantKey);
        $rows = is_array($settings['notices']['items'] ?? null) ? $settings['notices']['items'] : [];
        usort($rows, static function (array $left, array $right): int {
            if (!empty($left['is_pinned']) !== !empty($right['is_pinned'])) {
                return !empty($left['is_pinned']) ? -1 : 1;
            }

            return strcmp((string) ($right['published_at'] ?? ''), (string) ($left['published_at'] ?? ''));
        });

        return $rows;
    }



    /** @return array<string, mixed>|null */
    public function tenantNotice(string $tenantKey, int $noticeId): ?array
    {
        foreach ($this->tenantNotices($tenantKey) as $notice) {
            if ((int) ($notice['id'] ?? 0) === $noticeId) {
                return $notice;
            }
        }

        return null;
    }



    /** @param array<string, mixed> $data */
    public function saveTenantNotice(string $tenantKey, array $data): int
    {
        $settings = $this->tenantSettings($tenantKey);
        $notices = is_array($settings['notices']['items'] ?? null) ? $settings['notices']['items'] : [];
        $noticeId = (int) ($data['id'] ?? 0);
        if ($noticeId <= 0) {
            $noticeId = $this->nextId($notices);
            $data['id'] = $noticeId;
            $data['created_at'] = date('Y-m-d H:i:s');
        }
        $data['tenant_key'] = $tenantKey;
        $data['updated_at'] = date('Y-m-d H:i:s');

        $updated = false;
        foreach ($notices as &$notice) {
            if ((int) ($notice['id'] ?? 0) !== $noticeId) {
                continue;
            }
            $notice = array_replace($notice, $data);
            $updated = true;
            break;
        }
        unset($notice);
        if (!$updated) {
            $notices[] = $data;
        }

        $this->saveTenantSettings($tenantKey, ['notices' => ['items' => $notices]]);

        return $noticeId;
    }

    /**
     * 列表内自增 id（与 JsonStore::nextId 同口径：max(id)+1）。
     * 原 MysqlStore 从 JsonStore 移植公告逻辑时漏抄了本辅助方法，
     * 导致 MySQL 模式下新建公告调用未定义方法而 500。
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function nextId(array $rows): int
    {
        $ids = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows);
        return ($ids ? max($ids) : 0) + 1;
    }



    public function deleteTenantNotice(string $tenantKey, int $noticeId): void
    {
        if ($noticeId <= 0) {
            return;
        }
        $settings = $this->tenantSettings($tenantKey);
        $notices = is_array($settings['notices']['items'] ?? null) ? $settings['notices']['items'] : [];
        $notices = array_values(array_filter(
            $notices,
            static fn (array $notice): bool => (int) ($notice['id'] ?? 0) !== $noticeId
        ));
        $this->saveTenantSettings($tenantKey, ['notices' => ['items' => $notices]]);
    }



    public function toggleTenantNoticePinned(string $tenantKey, int $noticeId, bool $pinned): void
    {
        if ($noticeId <= 0) {
            return;
        }
        $settings = $this->tenantSettings($tenantKey);
        $notices = is_array($settings['notices']['items'] ?? null) ? $settings['notices']['items'] : [];
        foreach ($notices as &$notice) {
            if ((int) ($notice['id'] ?? 0) !== $noticeId) {
                continue;
            }
            $notice['is_pinned'] = $pinned;
            $notice['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
        unset($notice);
        $this->saveTenantSettings($tenantKey, ['notices' => ['items' => $notices]]);
    }



    /** @return array<int, array<string, mixed>> */
    public function importExportLogs(string $tenantKey): array
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo || !$this->tableExists($tenantPdo, 'import_export_logs')) {
            return [];
        }

        $rows = $tenantPdo->query('SELECT * FROM import_export_logs ORDER BY created_at DESC, id DESC LIMIT 30')->fetchAll();
        return array_map(fn (array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'type' => (string) ($row['job_type'] ?? ''),
            'name' => (string) ($row['job_name'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'file_name' => (string) ($row['file_name'] ?? ''),
            'rows' => (int) ($row['row_count'] ?? 0),
            'message' => (string) ($row['message'] ?? ''),
            'preview' => json_decode((string) ($row['preview_json'] ?? '[]'), true) ?: [],
            'created_by' => (string) ($row['created_by'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ], $rows);
    }



    /** @param array<string, mixed> $data */
    public function addImportExportLog(string $tenantKey, array $data): void
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo || !$this->tableExists($tenantPdo, 'import_export_logs')) {
            return;
        }

        $stmt = $tenantPdo->prepare(
            'INSERT INTO import_export_logs (job_type, job_name, status, file_name, row_count, message, preview_json, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            trim((string) ($data['type'] ?? 'import')),
            trim((string) ($data['name'] ?? '')),
            trim((string) ($data['status'] ?? '已记录')),
            trim((string) ($data['file_name'] ?? '')),
            (int) ($data['rows'] ?? 0),
            trim((string) ($data['message'] ?? '')),
            json_encode(array_slice(is_array($data['preview'] ?? null) ? $data['preview'] : [], 0, 5), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            trim((string) ($data['created_by'] ?? '系统')),
        ]);
    }



    /** @param array<string, mixed> $company */
    private function updateTenantProfile(string $tenantKey, array $company): void
    {
        $tenantId = $this->tenantId($tenantKey);
        if ($tenantId === null) {
            return;
        }

        $columns = [];
        $params = [];
        $columnMap = [
            'company_name' => 'company_name',
            'short_name' => 'company_short_name',
            'contact' => 'contact_name',
            'phone' => 'contact_phone',
            'address' => 'address',
            'note' => 'remark',
        ];

        foreach ($columnMap as $key => $column) {
            if (!$this->columnExists($this->db->master(), 'tenants', $column)) {
                continue;
            }
            $columns[] = "{$column} = ?";
            $params[] = trim((string) ($company[$key] ?? ''));
        }

        if (!$columns) {
            return;
        }

        $params[] = $tenantId;
        $stmt = $this->db->master()->prepare('UPDATE tenants SET ' . implode(', ', $columns) . ' WHERE id = ?');
        $stmt->execute($params);
    }



    /** @param array<string, mixed> $tenant */
    private function defaultTenantSettings(array $tenant): array
    {
        return [
            'company' => [
                'company_name' => (string) ($tenant['company_name'] ?? ''),
                'short_name' => (string) ($tenant['short_name'] ?? ''),
                'contact' => (string) ($tenant['contact'] ?? ''),
                'phone' => (string) ($tenant['phone'] ?? ''),
                'address' => (string) ($tenant['address'] ?? ''),
                'note' => (string) ($tenant['remark'] ?? ''),
            ],
            'orders' => [
                'default_page_size' => 200,
                'default_query_days' => 30,
                'archive_days' => 180,
                'price_warning_index' => 0,
                'platform_sync_default_days' => 7,
            ],
            'profit' => [
                'exchange_rate' => 0.046,
                'exchange_rate_mode' => 'fixed',
                'fixed_exchange_rate' => 0.046,
                'default_intl_fee' => 820,
                'platform_deductions' => [
                    'y' => 70,
                    'r' => 70,
                    'w' => 70,
                    'm' => 70,
                    'q' => 70,
                    'yp' => 70,
                ],
                'store_deduction_enabled' => true,
                'excluded_purchase_statuses' => ['已取消', '客人取消订单'],
            ],
            'logistics' => [
                'domestic_receive_places' => '',
                'carrier_mapping' => '',
                'tracking_prefix_mapping' => '',
            ],
            'notices' => [
                'items' => [],
            ],
            'api_1688' => [
                'enabled' => false,
                'config_file' => 'storage/tenants/' . $this->tenantStorageKey((string) ($tenant['key'] ?? '')) . '/config/1688/apikeys.conf',
                'config_content' => '',
            ],
            'jp_stock_purchase_statuses' => [],
            'updated_at' => '',
        ];
    }



    private function tenantStorageKey(string $tenantKey): string
    {
        $tenantKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $tenantKey) ?? '';
        return $tenantKey !== '' ? $tenantKey : 'erp';
    }
}
