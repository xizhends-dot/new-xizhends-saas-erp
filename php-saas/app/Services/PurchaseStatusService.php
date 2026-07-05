<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class PurchaseStatusService
{
    /** @var array<int, string> */
    public const JP_STOCK_STATUSES = [
        '日本库存订单',
        '库存缺货订单',
        '日本仓库已发出荷通知',
        '日本仓库已处理',
    ];

    /** @var array<string, string> */
    public const SYSTEM_STATUSES = [
        '未处理的订单' => '平台同步与待处理统计依赖',
        '国内采购-准备' => '货源改判与采购事件依赖',
        '国内采购-已采购' => '1688/快递物流同步与采购事件依赖',
        '国内采购-TB/PDD已采购' => '快递物流同步与采购事件依赖',
        '发货中' => '国内快递物流自动写入与同步依赖',
        '已到货' => '国内快递签收与发日本流程依赖',
        '已发货代订单' => '日本物流同步与发货判断依赖',
        '已发日本' => '批量已发日本与日本物流同步依赖',
        '已发出荷通知' => '出荷通知与发货判断依赖',
    ];

    public function __construct(private readonly StoreInterface $store)
    {
    }

    /** @return array<int, string> */
    public static function defaultStatuses(): array
    {
        return AppService::PURCHASE_STATUSES;
    }

    /** @return array<string, string> */
    public static function systemStatuses(): array
    {
        return self::SYSTEM_STATUSES;
    }

    /** @param array<int, string> $customStatuses @return array<int, string> */
    public static function statusOptionsForSource(string $sourceType, array $customStatuses): array
    {
        if ($sourceType === 'jp_stock') {
            return self::JP_STOCK_STATUSES;
        }

        return $customStatuses;
    }

    /** @return array<int, string> */
    public function statusesFor(string $tenantKey): array
    {
        $settings = $this->store->tenantSettings($tenantKey);
        $statuses = $settings['purchase_statuses'] ?? null;
        if (!is_array($statuses) || $statuses === []) {
            return self::defaultStatuses();
        }

        $result = self::validateStatuses($statuses);
        return $result['ok'] ? $result['statuses'] : self::defaultStatuses();
    }

    /**
     * @param array<int|string, mixed> $names
     * @return array{ok: bool, message: string, statuses: array<int, string>}
     */
    public function saveStatuses(string $tenantKey, array $names): array
    {
        $result = self::validateStatuses($names);
        if (!$result['ok']) {
            return ['ok' => false, 'message' => $result['message'], 'statuses' => []];
        }

        $this->store->saveTenantSettings($tenantKey, ['purchase_statuses' => $result['statuses']]);
        return ['ok' => true, 'message' => '采购状态已保存。', 'statuses' => $result['statuses']];
    }

    /** @return array{ok: bool, message: string, statuses: array<int, string>} */
    public function resetStatuses(string $tenantKey): array
    {
        $this->store->saveTenantSettings($tenantKey, ['purchase_statuses' => []]);
        return ['ok' => true, 'message' => '采购状态已恢复默认。', 'statuses' => self::defaultStatuses()];
    }

    /**
     * @param array<int|string, mixed> $names
     * @return array{ok: bool, message: string, statuses: array<int, string>}
     */
    public static function validateStatuses(array $names): array
    {
        $statuses = [];
        $seen = [];
        foreach ($names as $name) {
            $status = trim((string) $name);
            if ($status === '') {
                return self::invalid('状态名称不能为空。', $statuses);
            }
            if (str_contains($status, "\n") || str_contains($status, "\r")) {
                return self::invalid('状态名称不能包含换行。', $statuses);
            }
            if (self::length($status) > 32) {
                return self::invalid("状态名称「{$status}」不能超过 32 个字符。", $statuses);
            }
            if (isset($seen[$status])) {
                return self::invalid("状态名称「{$status}」重复。", $statuses);
            }
            $seen[$status] = true;
            $statuses[] = $status;
        }

        if (count($statuses) > 50) {
            return self::invalid('采购状态最多保留 50 个。', $statuses);
        }

        foreach (array_keys(self::SYSTEM_STATUSES) as $systemStatus) {
            if (!isset($seen[$systemStatus])) {
                return self::invalid("系统状态「{$systemStatus}」不可删除或改名。", $statuses);
            }
        }

        return ['ok' => true, 'message' => '', 'statuses' => $statuses];
    }

    /** @param array<int, string> $statuses @return array{ok: false, message: string, statuses: array<int, string>} */
    private static function invalid(string $message, array $statuses): array
    {
        return ['ok' => false, 'message' => $message, 'statuses' => $statuses];
    }

    private static function length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        if (preg_match_all('/./us', $value, $matches) !== false) {
            return count($matches[0]);
        }

        return strlen($value);
    }
}
