<?php

declare(strict_types=1);

namespace Xizhen\Core;

final class TenantFeature
{
    /** @return array<int, array{key: string, title: string, desc: string, items: array<int, array{key: string, name: string, desc: string, default: bool}>}> */
    public static function groups(): array
    {
        return [
            [
                'key' => 'orders',
                'title' => '订单能力',
                'desc' => '订单列表、搜索、采购、日本仓与编辑操作。',
                'items' => [
                    ['key' => 'orders.platform', 'name' => '平台订单', 'desc' => '平台订单列表、平台筛选和订单详情入口。', 'default' => true],
                    ['key' => 'orders.search', 'name' => '全局搜索', 'desc' => '跨平台按订单、客户、商品、1688 单号搜索。', 'default' => true],
                    ['key' => 'orders.purchase', 'name' => '采购订单', 'desc' => '国内采购订单、采购状态和采购人处理。', 'default' => true],
                    ['key' => 'orders.jp', 'name' => '日本仓发货', 'desc' => '日本仓订单、分配发货员和出库状态。', 'default' => true],
                    ['key' => 'orders.edit', 'name' => '订单编辑', 'desc' => '详情页保存、编辑抽屉、货源改判和批量更新。', 'default' => true],
                ],
            ],
            [
                'key' => 'logistics',
                'title' => '物流能力',
                'desc' => '1688、TB/PDD 和日本国际物流查询与更新。',
                'items' => [
                    ['key' => 'logistics.1688', 'name' => '1688 物流', 'desc' => '1688 国内采购物流查看与同步触发。', 'default' => true],
                    ['key' => 'logistics.express', 'name' => 'TB/PDD 物流', 'desc' => '淘宝、拼多多等国内快递物流更新入口。', 'default' => true],
                    ['key' => 'logistics.jp', 'name' => '日本物流', 'desc' => '日本国际物流状态查看与同步触发。', 'default' => true],
                ],
            ],
            [
                'key' => 'business',
                'title' => '经营分析',
                'desc' => '利润核算、采购统计和后续绩效统计。',
                'items' => [
                    ['key' => 'analytics.profit', 'name' => '利润核算', 'desc' => '汇率、扣点、国际运费和利润分析。', 'default' => true],
                    ['key' => 'stats.purchase', 'name' => '采购统计', 'desc' => '采购员和采购状态统计。', 'default' => true],
                    ['key' => 'stats.performance', 'name' => '业绩统计', 'desc' => '销售业绩统计预留模块。', 'default' => false],
                    ['key' => 'stats.products', 'name' => '出单商品统计', 'desc' => '商品出单排名和趋势预留模块。', 'default' => false],
                    ['key' => 'stats.shipping_anomaly', 'name' => '异常运费统计', 'desc' => '异常运费分析预留模块。', 'default' => false],
                    ['key' => 'tools.price_calculator', 'name' => '核价计算器', 'desc' => '按成本、扣点、汇率和目标利润批量核价。', 'default' => true],
                ],
            ],
            [
                'key' => 'import_export',
                'title' => '导入导出',
                'desc' => '平台订单、采购、发货、财务、客户和物流 CSV/Excel。',
                'items' => [
                    ['key' => 'import_export.center', 'name' => '导入导出中心', 'desc' => 'CSV 导入预览、导出任务和日志。', 'default' => true],
                    ['key' => 'import_export.platform', 'name' => '平台订单导入导出', 'desc' => '平台订单表、发货表、通知表等。', 'default' => true],
                    ['key' => 'import_export.purchase', 'name' => '采购导入导出', 'desc' => '待采购单、采购表导入和采购导出。', 'default' => true],
                    ['key' => 'import_export.finance', 'name' => '财务核算导出', 'desc' => '财务核算表与利润明细导出。', 'default' => true],
                    ['key' => 'import_export.logistics', 'name' => '物流导入导出', 'desc' => '国际运单、国内物流和物流状态表。', 'default' => true],
                    ['key' => 'import_export.platform_special', 'name' => '平台专用 CSV', 'desc' => '日亚、盛欣、万达、Qoo10、Wowma 等专用 CSV。', 'default' => true],
                    ['key' => 'import_export.finance_import', 'name' => '财务导入', 'desc' => '按运单号模糊匹配导入财务重量和运费。', 'default' => true],
                    ['key' => 'import_export.shipping_modes', 'name' => '国际运单导入模式', 'desc' => '支持追加或覆盖国际运单号。', 'default' => true],
                    ['key' => 'import_export.jp_warehouse_import', 'name' => '日本仓 YD 导入', 'desc' => '按 YD 表更新日本仓出库与国际运费字段。', 'default' => true],
                ],
            ],
            [
                'key' => 'customer',
                'title' => '客服邮件',
                'desc' => '邮件收发、客户资料和订单关联。',
                'items' => [
                    ['key' => 'mail.center', 'name' => '邮件中心', 'desc' => '客服邮件 IMAP/SMTP 同步和回复入口。', 'default' => true],
                    ['key' => 'customers.data', 'name' => '客户资料', 'desc' => '客户资料查看与导出。', 'default' => true],
                ],
            ],
            [
                'key' => 'media',
                'title' => '图片附件',
                'desc' => '订单图片、SKU 图、凭证附件和旧图清理。',
                'items' => [
                    ['key' => 'media.library', 'name' => '租户图片库', 'desc' => '租户隔离的订单图片和附件库。', 'default' => true],
                    ['key' => 'media.upload', 'name' => '图片上传', 'desc' => '主图、SKU 图和订单附件上传。', 'default' => true],
                    ['key' => 'media.delete', 'name' => '图片删除', 'desc' => '附件删除和后续图片字段清空。', 'default' => true],
                ],
            ],
            [
                'key' => 'management',
                'title' => '租户管理',
                'desc' => '店铺、员工、店铺分配和系统设置。',
                'items' => [
                    ['key' => 'management.stores', 'name' => '店铺管理', 'desc' => '店铺资料、平台、API 配置和扣点。', 'default' => true],
                    ['key' => 'management.users', 'name' => '员工管理', 'desc' => '员工、角色、权限和店铺范围。', 'default' => true],
                    ['key' => 'management.assignments', 'name' => '店铺分配', 'desc' => '采购与客服的店铺关系。', 'default' => true],
                    ['key' => 'management.settings', 'name' => '系统设置', 'desc' => '公司资料、订单参数、利润和物流映射。', 'default' => true],
                    ['key' => 'management.logs', 'name' => '操作日志', 'desc' => '订单修改、导入导出和同步操作日志。', 'default' => true],
                    ['key' => 'management.jobs', 'name' => '定时任务状态', 'desc' => '租户只读查看任务状态。', 'default' => true],
                    ['key' => 'account.password_edit', 'name' => '员工自助改密', 'desc' => '员工登录后自行修改安全密码。', 'default' => true],
                    ['key' => 'management.notices', 'name' => '租户公告', 'desc' => '租户管理员发布面向员工的通知公告。', 'default' => true],
                    ['key' => 'management.user_permission_overrides', 'name' => '单用户权限覆盖', 'desc' => '按员工维护 allow/deny 权限覆盖。', 'default' => true],
                    ['key' => 'management.customer_service_deductions', 'name' => '客服扣点', 'desc' => '快捷维护客服账号利润扣点。', 'default' => true],
                ],
            ],
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        $keys = [];
        foreach (self::groups() as $group) {
            foreach ($group['items'] as $item) {
                $keys[] = $item['key'];
            }
        }

        return $keys;
    }

    /** @return array<string, bool> */
    public static function defaultMap(): array
    {
        $map = [];
        foreach (self::groups() as $group) {
            foreach ($group['items'] as $item) {
                $map[$item['key']] = (bool) $item['default'];
            }
        }

        return $map;
    }

    /** @return array<int, array{key: string, enabled: bool}> */
    public static function defaultRows(): array
    {
        $rows = [];
        foreach (self::defaultMap() as $key => $enabled) {
            $rows[] = ['key' => $key, 'enabled' => $enabled];
        }

        return $rows;
    }

    /** @param array<int, array<string, mixed>> $rows */
    public static function normalizeRows(array $rows): array
    {
        $map = self::defaultMap();
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if (!array_key_exists($key, $map)) {
                continue;
            }
            $map[$key] = (bool) ($row['enabled'] ?? false);
        }

        $normalized = [];
        foreach ($map as $key => $enabled) {
            $normalized[] = ['key' => $key, 'enabled' => $enabled];
        }

        return $normalized;
    }

    /** @param array<int, array<string, mixed>> $rows */
    public static function mapFromRows(array $rows): array
    {
        $map = self::defaultMap();
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if (array_key_exists($key, $map)) {
                $map[$key] = (bool) ($row['enabled'] ?? false);
            }
        }

        return $map;
    }

    public static function isKnown(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }
}
