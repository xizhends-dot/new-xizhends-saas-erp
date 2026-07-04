<?php

declare(strict_types=1);

namespace Xizhen\Repositories;

final class AdminRepository extends BaseRepository
{


    /** @return array<string, mixed>|null */
    public function adminByUsername(string $username): ?array
    {
        $username = trim($username);
        if ($username === '' || !$this->tableExists($this->db->master(), 'admins')) {
            return null;
        }

        $stmt = $this->db->master()->prepare('SELECT id, username, password_hash, display_name, status, created_at, last_login_at FROM admins WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        return $row ?: null;
    }



    public function touchAdminLogin(int $adminId): void
    {
        if ($adminId <= 0 || !$this->tableExists($this->db->master(), 'admins')) {
            return;
        }

        $this->db->master()->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?')->execute([$adminId]);
    }



    /** @return array<int, array<string, mixed>> */
    public function announcements(): array
    {
        $rows = $this->db->master()
            ->query('SELECT kind, title, scope, content, created_at FROM announcements ORDER BY created_at DESC LIMIT 20')
            ->fetchAll();

        return array_map(fn (array $row): array => [
            'kind' => (string) $row['kind'],
            'title' => (string) $row['title'],
            'scope' => $row['scope'] === 'global' ? '全部租户' : '指定租户',
            'date' => (string) $row['created_at'],
            'body' => (string) $row['content'],
        ], $rows);
    }



    /** @return array<string, mixed> */
    public function globalSettings(): array
    {
        $settings = $this->defaultGlobalSettings();
        if (!$this->tableExists($this->db->master(), 'global_settings')) {
            return $settings;
        }

        $rows = $this->db->master()
            ->query('SELECT setting_key, setting_value, updated_at FROM global_settings ORDER BY setting_key')
            ->fetchAll();
        foreach ($rows as $row) {
            $key = (string) ($row['setting_key'] ?? '');
            $value = json_decode((string) ($row['setting_value'] ?? ''), true);
            if ($key !== '' && is_array($value) && is_array($settings[$key] ?? null)) {
                $settings[$key] = array_replace_recursive($settings[$key], $value);
            }
            if (($row['updated_at'] ?? '') !== '') {
                $settings['updated_at'] = max((string) $settings['updated_at'], (string) $row['updated_at']);
            }
        }

        return $settings;
    }



    /** @param array<string, mixed> $data */
    public function saveGlobalSettings(array $data): void
    {
        if (!$this->tableExists($this->db->master(), 'global_settings')) {
            return;
        }

        $settings = array_replace_recursive($this->globalSettings(), $this->normalizeGlobalSettingsInput($data));
        $settings['updated_at'] = date('Y-m-d H:i:s');
        $stmt = $this->db->master()->prepare(
            'INSERT INTO global_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );
        foreach (['logistics_mapping', 'showapi', 'proxy'] as $section) {
            $stmt->execute([
                $section,
                json_encode($settings[$section] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    }



    /** @return array<string, mixed> */
    private function defaultGlobalSettings(): array
    {
        return [
            'logistics_mapping' => [
                'yahoo' => '',
                'rakuten' => '',
                'wowma' => '',
                'jp_carrier' => '',
                'tracking_query' => '',
            ],
            'showapi' => [
                'app_id' => '',
                'sign' => '',
                'baidu_enabled' => false,
                'enabled' => false,
            ],
            'proxy' => [
                'rotation_proxy' => '',
                'enabled' => false,
            ],
            'updated_at' => '',
        ];
    }



    /** @param array<string, mixed> $data */
    private function normalizeGlobalSettingsInput(array $data): array
    {
        $mapping = is_array($data['logistics_mapping'] ?? null) ? $data['logistics_mapping'] : [];
        $showapi = is_array($data['showapi'] ?? null) ? $data['showapi'] : [];
        $proxy = is_array($data['proxy'] ?? null) ? $data['proxy'] : [];

        return [
            'logistics_mapping' => [
                'yahoo' => trim((string) ($mapping['yahoo'] ?? '')),
                'rakuten' => trim((string) ($mapping['rakuten'] ?? '')),
                'wowma' => trim((string) ($mapping['wowma'] ?? '')),
                'jp_carrier' => trim((string) ($mapping['jp_carrier'] ?? '')),
                'tracking_query' => trim((string) ($mapping['tracking_query'] ?? '')),
            ],
            'showapi' => [
                'app_id' => trim((string) ($showapi['app_id'] ?? '')),
                'sign' => trim((string) ($showapi['sign'] ?? '')),
                'baidu_enabled' => !empty($showapi['baidu_enabled']),
                'enabled' => !empty($showapi['enabled']),
            ],
            'proxy' => [
                'rotation_proxy' => trim((string) ($proxy['rotation_proxy'] ?? '')),
                'enabled' => !empty($proxy['enabled']),
            ],
        ];
    }
}
