<?php

declare(strict_types=1);

namespace Xizhen\Repositories;

use Xizhen\Core\Db;
use Xizhen\Core\Permission;

abstract class BaseRepository
{
    protected const STORE_ADD_FEE = 50;
    protected const STORE_MONTHLY_FEE = 50;
    protected const DEBT_SUSPEND_THRESHOLD = -300;
    protected const PURCHASE_EVENT_STATUSES = [
        '国内采购-准备' => 'enter_prepare',
        '国内采购-已采购' => 'complete_purchase',
        '国内采购-TB/PDD已采购' => 'complete_purchase',
    ];
    protected const MAIL_TABLES = [
        'ph_mail_account',
        'ph_mail_folder',
        'ph_mail_message',
        'ph_mail_reply',
        'ph_mail_rule',
        'ph_mail_rule_account',
    ];

    public function __construct(protected readonly Db $db)
    {
    }



    protected function tenantId(string $tenantKey): ?int
    {
        $stmt = $this->db->master()->prepare('SELECT id FROM tenants WHERE subdomain = ? LIMIT 1');
        $stmt->execute([$tenantKey]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }



    protected function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }



    protected function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() > 0;
    }



    protected function ensurePurchaseStatusEventTable(\PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'purchase_status_events')) {
            throw new \RuntimeException('采购事件 MySQL 表未建：purchase_status_events。请先执行 migrations/tenant/0007_create_purchase_status_events.sql。');
        }
    }



    protected function shortText(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }



    /** @return array<string, mixed> */
    protected function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) ($value ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }



    /** @param array<int, string> $keys */
    protected function firstExtra(array $extra, array $keys, mixed $default = ''): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $extra) && $extra[$key] !== null && $extra[$key] !== '') {
                return $extra[$key];
            }
        }

        return $default;
    }



    protected function moneyValue(mixed $value, float $default = 0.0): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = preg_replace('/[^\d.\-]+/', '', str_replace(',', '', (string) ($value ?? '')));
        return is_numeric($normalized) ? (float) $normalized : $default;
    }



    /** @param array<int, string> $keys */
    protected function firstMoney(array $extra, array $keys, float $default = 0.0): float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $extra) || $extra[$key] === null || $extra[$key] === '') {
                continue;
            }

            return $this->moneyValue($extra[$key], $default);
        }

        return $default;
    }



    protected function sqlDateTime(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '' || strtotime($raw) === false) {
            return null;
        }

        return date('Y-m-d H:i:s', strtotime($raw));
    }



    protected function jsonValue(array $value): ?string
    {
        return $value ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    }



    protected function ensureChildRow(\PDO $pdo, string $table, int $itemId): void
    {
        $count = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE order_item_id = ?");
        $count->execute([$itemId]);
        if ((int) $count->fetchColumn() > 0) {
            return;
        }

        $insert = $pdo->prepare("INSERT INTO {$table} (order_item_id) VALUES (?)");
        $insert->execute([$itemId]);
    }



    protected function insertItemLog(\PDO $pdo, int $orderId, ?int $itemId, string $action, string $field, string $oldValue, string $newValue, string $operator = '系统管理员'): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO order_logs (order_id, order_item_id, operator, action_type, field_name, old_value, new_value, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $orderId,
            $itemId,
            $operator,
            $action,
            $field,
            $oldValue,
            $newValue,
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);
    }
}
