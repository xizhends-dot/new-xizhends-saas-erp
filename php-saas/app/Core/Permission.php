<?php

declare(strict_types=1);

namespace Xizhen\Core;

final class Permission
{
    /** @param array<string, mixed>|null $user */
    public static function has(?array $user, string $permission): bool
    {
        $permission = self::normalizeName($permission);
        if ($permission === '' || $user === null) {
            return false;
        }

        if (($user['role'] ?? '') === '公司管理员' || ($user['is_company_admin'] ?? false)) {
            return true;
        }

        $overrides = self::normalizeOverrides($user['permission_overrides'] ?? []);
        if (in_array($permission, $overrides['deny'], true)) {
            return false;
        }
        if (in_array($permission, $overrides['allow'], true)) {
            return true;
        }

        $permissions = array_map(
            self::normalizeName(...),
            array_filter((array) ($user['permissions'] ?? []), static fn (mixed $item): bool => trim((string) $item) !== '')
        );

        if (in_array($permission, $permissions, true)) {
            return true;
        }

        $legacyMap = self::legacyMap();
        return isset($legacyMap[$permission]) && in_array($legacyMap[$permission], $permissions, true);
    }

    /**
     * @param array<string, mixed>|null $user
     * @param array<int, string> $permissions
     */
    public static function hasAny(?array $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::has($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed>|null $user */
    public static function canAccessStore(?array $user, string $storeName): bool
    {
        if ($user === null) {
            return false;
        }

        if (($user['role'] ?? '') === '公司管理员' || ($user['is_company_admin'] ?? false)) {
            return true;
        }

        $stores = array_values(array_filter(array_map('trim', (array) ($user['stores'] ?? []))));
        if (in_array('全部店铺', $stores, true)) {
            return true;
        }

        return in_array(trim($storeName), $stores, true);
    }

    /** @return array<string, array<int, string>> */
    public static function roleDefaults(): array
    {
        return [
            '公司管理员' => ['订单查看', '订单编辑', '货源改判', '批量操作', '店铺新增', '员工管理', '店铺分配', '公司设置', '系统设置', '导入导出', '订单日志', '1688物流', '1688物流日志', '日本物流日志', '物流查看', '业绩统计', '出单商品统计', '采购统计', '利润分析', '异常运费', 'Wowma批量同步', '邮件中心', '客户资料', '图片管理', '图片上传', '图片删除', '公告管理', '通知查看', '权限覆盖', '客服扣点', '核价计算器', '财务导入', '财务导出'],
            '采购' => ['订单查看', '采购订单', '采购状态', '1688物流', '1688物流日志', '采购导入导出', '订单日志', '图片上传'],
            '客服' => ['订单查看', '订单编辑', '货源改判', '客户资料', '邮件中心', '图片上传', '订单日志', '通知查看'],
            '品检' => ['订单查看', '日本仓发货', '物流查看', '日本物流日志', '图片管理', '图片上传', '图片删除'],
        ];
    }

    /** @return array<string, string> */
    public static function legacyMap(): array
    {
        return [
            'system_settings' => '系统设置',
            'order_log' => '订单日志',
            '1688_log' => '1688物流日志',
            'jpshipinfo_log' => '日本物流日志',
            'showapi_log' => '物流查看',
            'performance_view' => '业绩统计',
            'product_statistics' => '出单商品统计',
            'purchase_statistics' => '采购统计',
            'profit_analysis' => '利润分析',
            'shipping_anomaly' => '异常运费',
            'wowma_batch_sync' => 'Wowma批量同步',
            'kefu_mail' => '邮件中心',
            'image_upload' => '图片上传',
            'image_delete' => '图片删除',
            'shop_assign' => '店铺分配',
            'tenant_notice_manage' => '公告管理',
            'tenant_notice_view' => '通知查看',
            'user_permission_override' => '权限覆盖',
            'customer_service_deduction' => '客服扣点',
            'price_calculator' => '核价计算器',
            'finance_import' => '财务导入',
            'finance_export' => '财务导出',
        ];
    }

    private static function normalizeName(mixed $permission): string
    {
        return trim((string) $permission);
    }

    /**
     * @param mixed $raw
     * @return array{allow: array<int, string>, deny: array<int, string>}
     */
    private static function normalizeOverrides(mixed $raw): array
    {
        if (!is_array($raw)) {
            return ['allow' => [], 'deny' => []];
        }

        return [
            'allow' => array_values(array_filter(array_map(self::normalizeName(...), (array) ($raw['allow'] ?? [])))),
            'deny' => array_values(array_filter(array_map(self::normalizeName(...), (array) ($raw['deny'] ?? [])))),
        ];
    }
}
