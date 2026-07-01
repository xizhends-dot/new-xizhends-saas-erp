<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\Permission;
use Xizhen\Core\StoreInterface;
use Xizhen\Core\TenantFeature;

final class AppService
{
    public function __construct(private readonly StoreInterface $store)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function platformMenu(string $tenantKey): array
    {
        if (!$this->tenantFeatureEnabled($tenantKey, 'orders.platform')) {
            return [];
        }

        $tenant = $this->store->tenant($tenantKey);
        $auth = [];
        foreach ($tenant['platforms'] ?? [] as $item) {
            $auth[$item['code']] = $item;
        }

        $menu = [];
        foreach ($this->store->platforms() as $platform) {
            $state = $auth[$platform['code']] ?? ['enabled' => false, 'locked' => false];
            if (!($state['enabled'] ?? false)) {
                continue;
            }

            $menu[] = array_merge($platform, [
                'locked' => ($state['locked'] ?? false) || (($tenant['status'] ?? '') === 'suspended'),
                'enabled' => true,
            ]);
        }

        return $menu;
    }

    public function tenantFeatureEnabled(string $tenantKey, string $featureKey): bool
    {
        $tenant = $this->store->tenant($tenantKey);
        if (($tenant['status'] ?? '') === 'suspended') {
            return false;
        }

        return TenantFeature::mapFromRows($this->store->tenantFeatures($tenantKey))[$featureKey] ?? false;
    }

    /** @return array<string, bool> */
    public function tenantFeatureMap(string $tenantKey): array
    {
        $features = TenantFeature::mapFromRows($this->store->tenantFeatures($tenantKey));
        if (($this->store->tenant($tenantKey)['status'] ?? '') === 'suspended') {
            return array_map(static fn (): bool => false, $features);
        }

        return $features;
    }

    public function platformEnabled(string $tenantKey, string $platformCode): bool
    {
        if ($platformCode === '') {
            return true;
        }

        return in_array($platformCode, $this->enabledPlatformCodes($tenantKey), true);
    }

    /** @return array<int, string> */
    public function enabledPlatformCodes(string $tenantKey, bool $includeLocked = false): array
    {
        $tenant = $this->store->tenant($tenantKey);
        if (($tenant['status'] ?? '') === 'suspended') {
            return [];
        }

        $codes = [];
        foreach ($tenant['platforms'] ?? [] as $item) {
            if (!($item['enabled'] ?? false)) {
                continue;
            }
            if (!$includeLocked && ($item['locked'] ?? false)) {
                continue;
            }

            $codes[] = (string) ($item['code'] ?? '');
        }

        return array_values(array_unique(array_filter($codes)));
    }

    /** @return array<string, string> */
    public function tenantPlatformNames(string $tenantKey, bool $includeLocked = false): array
    {
        $allowed = array_flip($this->enabledPlatformCodes($tenantKey, $includeLocked));
        if (!$allowed) {
            return [];
        }

        $names = [];
        foreach ($this->store->platforms() as $platform) {
            $code = (string) ($platform['code'] ?? '');
            if (isset($allowed[$code])) {
                $names[$code] = (string) ($platform['name'] ?? $code);
            }
        }

        return $names;
    }

    /** @return array<int, array<string, mixed>> */
    public function storesForTenant(string $tenantKey): array
    {
        $allowed = array_flip($this->enabledPlatformCodes($tenantKey));
        if (!$allowed) {
            return [];
        }

        return array_values(array_filter(
            $this->store->stores($tenantKey),
            static fn (array $store): bool => isset($allowed[(string) ($store['platform'] ?? '')])
        ));
    }

    /** @return array<string, mixed> */
    /** @param array<string, mixed>|null $user */
    public function dashboard(string $tenantKey, ?array $user = null): array
    {
        $orders = $this->ordersForUser($tenantKey, $user);
        $items = $this->flattenItems($orders);

        return [
            'pending_orders' => count(array_filter($orders, fn (array $order): bool => ($order['status'] ?? '') === '待处理' || ($order['status'] ?? '') === '未处理的订单')),
            'purchase_items' => count(array_filter($items, fn (array $item): bool => ($item['source_type'] ?? '') === 'cn_purchase')),
            'jp_stock_items' => count(array_filter($items, fn (array $item): bool => ($item['source_type'] ?? '') === 'jp_stock')),
            'pending_source_items' => count(array_filter($items, fn (array $item): bool => ($item['source_type'] ?? '') === 'pending')),
            'today_amount' => array_sum(array_map(fn (array $order): int|float => $order['total'] ?? 0, $orders)),
            'recent_orders' => array_slice($orders, 0, 5),
        ];
    }

    /**
     * @param array<string, mixed>|null $user
     * @return array<int, array<string, mixed>>
     */
    public function ordersForUser(string $tenantKey, ?array $user): array
    {
        if ($user === null || ($user['role'] ?? '') === '公司管理员' || ($user['is_company_admin'] ?? false)) {
            return $this->filterOrdersByTenantPlatforms($tenantKey, $this->store->orders($tenantKey));
        }

        return $this->filterOrdersByTenantPlatforms($tenantKey, $this->store->ordersForStores($tenantKey, (array) ($user['stores'] ?? [])));
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    public function filterOrdersForView(array $orders, string $view, ?string $platform = null, ?string $source = null, ?string $keyword = null, array $filters = []): array
    {
        $result = [];
        foreach ($orders as $order) {
            if ($platform && ($order['platform'] ?? '') !== $platform) {
                continue;
            }

            if (!$this->orderMatchesFilters($order, $filters)) {
                continue;
            }

            $copy = $order;
            $copy['items'] = array_values(array_filter($order['items'] ?? [], function (array $item) use ($view, $source, $filters): bool {
                $itemSource = $item['source_type'] ?? 'pending';
                if ($view === 'purchase' && $itemSource !== 'cn_purchase') {
                    return false;
                }
                if ($view === 'jp' && $itemSource !== 'jp_stock') {
                    return false;
                }
                if ($view === 'platform' && $source && $source !== 'all' && $itemSource !== $source) {
                    return false;
                }
                return $this->itemMatchesFilters($item, $view, $filters);
            }));

            if (!$copy['items']) {
                continue;
            }

            if ($keyword) {
                $haystack = implode(' ', [
                    $order['platform_order_id'] ?? '',
                    $order['customer']['name'] ?? '',
                    $order['customer']['phone'] ?? '',
                    $order['store'] ?? '',
                    implode(' ', array_map(fn (array $item): string => implode(' ', [
                        $item['item_code'] ?? '',
                        $item['title'] ?? '',
                        $item['tabaono'] ?? '',
                    ]), $copy['items'])),
                ]);
                if (!str_contains(strtolower($haystack), strtolower($keyword))) {
                    continue;
                }
            }

            $result[] = $copy;
        }

        return $result;
    }

    /** @return array<string, string> */
    public function platformNames(): array
    {
        $names = [];
        foreach ($this->store->platforms() as $platform) {
            $names[$platform['code']] = $platform['name'];
        }
        return $names;
    }

    /** @return array<int, array<string, mixed>> */
    public function tenantsWithPlatformLabels(): array
    {
        $platforms = $this->platformNames();
        return array_map(function (array $tenant) use ($platforms): array {
            $labels = [];
            foreach ($tenant['platforms'] ?? [] as $auth) {
                if (!($auth['enabled'] ?? false)) {
                    continue;
                }
                $name = $platforms[$auth['code']] ?? $auth['code'];
                $labels[] = ($auth['locked'] ?? false) ? "{$name}(锁定)" : $name;
            }
            $tenant['platform_labels'] = $labels;
            return $tenant;
        }, $this->store->tenants());
    }

    /** @return array<string, array<int, array<string, string>>> */
    public function featureGroups(string $tenantKey): array
    {
        return $this->filterFeatureGroups($tenantKey, [
            '订单工作台' => [
                ['feature' => 'orders.search', 'title' => '全局搜索', 'desc' => '跨 6 平台按订单号、客户、商品、1688 号检索。', 'href' => "/search?tenant={$tenantKey}", 'status' => '已接页面'],
                ['feature' => 'orders.platform', 'title' => '平台订单', 'desc' => '按平台查看订单，支持货源改判、批量删除和进入详情保存。', 'href' => "/orders?tenant={$tenantKey}&view=platform", 'status' => '已接后端'],
                ['feature' => 'orders.purchase', 'title' => '采购订单', 'desc' => '只显示国内采购子商品，支持采购状态和采购人批量更新。', 'href' => "/orders?tenant={$tenantKey}&view=purchase", 'status' => '已接后端'],
                ['feature' => 'orders.jp', 'title' => '日本仓发货', 'desc' => '只显示日本仓子商品，支持批量分配与出库状态更新。', 'href' => "/orders?tenant={$tenantKey}&view=jp", 'status' => '已接后端'],
                ['feature' => 'orders.edit', 'title' => '订单详情', 'desc' => '从订单号进入，保存采购、物流、日本仓和日志字段。', 'href' => "/orders?tenant={$tenantKey}&view=platform", 'status' => '已接后端'],
            ],
            '旧系统插件' => [
                ['feature' => 'logistics.1688', 'title' => '1688 物流', 'desc' => '对应 old/plugins/1688api 与 cron/update_1688_logistics.php。', 'href' => "/logistics/1688?tenant={$tenantKey}", 'status' => '已接 API/CLI'],
                ['feature' => 'logistics.jp', 'title' => '日本物流', 'desc' => '对应 jpshipinfo、sagawa-shipinfo 与 update_jpship_logistics.php。', 'href' => "/logistics/jp?tenant={$tenantKey}", 'status' => '待接 API'],
                ['feature' => 'mail.center', 'title' => '客服邮件中心', 'desc' => '对应 old/kefu_mail 与 cron/mail_sync.php。', 'href' => "/mail?tenant={$tenantKey}", 'status' => '已接 IMAP/SMTP'],
                ['feature' => 'media.library', 'title' => '租户图片库', 'desc' => '按公司隔离订单主图、SKU 图、上传凭证和旧图清理策略。', 'href' => "/media?tenant={$tenantKey}", 'status' => '页面已接'],
            ],
            '经营分析' => [
                ['feature' => 'analytics.profit', 'title' => '利润分析', 'desc' => '对应 old/plugins/profit-analysis。', 'href' => "/analytics/profit?tenant={$tenantKey}", 'status' => '开发数据'],
                ['feature' => 'stats.purchase', 'title' => '采购统计', 'desc' => '对应 caigou_status / caigou_stats。', 'href' => "/stats/purchase?tenant={$tenantKey}", 'status' => '开发数据'],
                ['feature' => 'import_export.center', 'title' => '导入导出', 'desc' => '对应 Excel 导入、物流导入、客户资料导出。', 'href' => "/import-export?tenant={$tenantKey}", 'status' => '页面已接'],
                ['feature' => 'management.jobs', 'title' => '定时任务状态', 'desc' => '租户只查看同步状态；频率、开关和失败重试由超管设置。', 'href' => "/jobs?tenant={$tenantKey}", 'status' => '只读'],
            ],
            '权限与体系' => [
                ['feature' => 'management.stores', 'title' => '店铺管理', 'desc' => '承接隐藏店铺、店铺扣点、店铺级 API 配置和平台状态。', 'href' => "/stores?tenant={$tenantKey}", 'status' => '已接后端'],
                ['feature' => 'management.users', 'title' => '员工管理', 'desc' => '承接管理员、采购、客服、品检角色、首选入口、1688 配置和店铺范围。', 'href' => "/users?tenant={$tenantKey}", 'status' => '已接后端'],
                ['feature' => 'management.assignments', 'title' => '店铺分配', 'desc' => '承接旧 ph_userlevel，维护采购/品检与客服店铺关系。', 'href' => "/assignments?tenant={$tenantKey}", 'status' => '已接后端'],
                ['feature' => 'management.settings', 'title' => '系统设置', 'desc' => '读取 old/setting.ini，区分可迁入参数和敏感密钥。', 'href' => "/settings?tenant={$tenantKey}", 'status' => '已接配置'],
                ['feature' => 'management.logs', 'title' => '操作日志', 'desc' => '展示货源改判、批量更新、详情保存等审计记录。', 'href' => "/logs?tenant={$tenantKey}", 'status' => '已接后端'],
            ],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    /** @param array<string, mixed>|null $user */
    public function globalSearchResults(string $tenantKey, string $keyword, ?array $user = null): array
    {
        if ($keyword === '') {
            return [];
        }

        $orders = $this->ordersForUser($tenantKey, $user);
        $matches = $this->filterOrdersForView($orders, 'platform', null, 'all', $keyword);
        $platformNames = $this->platformNames();

        return array_map(fn (array $order): array => [
            'platform' => $platformNames[$order['platform']] ?? $order['platform'],
            'order_no' => $order['platform_order_id'] ?? '',
            'store' => $order['store'] ?? '',
            'customer' => $order['customer']['name'] ?? '',
            'status' => $order['status'] ?? '',
            'amount' => $order['total'] ?? 0,
            'items' => count($order['items'] ?? []),
        ], $matches);
    }

    /** @return array<string, mixed> */
    /** @param array<string, mixed>|null $user */
    public function profitSummary(string $tenantKey, ?array $user = null): array
    {
        return $this->profitSummaryForOrders($tenantKey, $this->ordersForUser($tenantKey, $user));
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<string, mixed>
     */
    public function profitSummaryForOrders(string $tenantKey, array $orders): array
    {
        $settings = $this->store->tenantSettings($tenantKey);
        $profitSettings = is_array($settings['profit'] ?? null) ? $settings['profit'] : [];
        $exchangeRate = $this->positiveFloat($profitSettings['exchange_rate'] ?? 0.046, 0.046);
        $defaultIntlFee = $this->positiveFloat($profitSettings['default_intl_fee'] ?? 820, 820);
        $platformDeductions = is_array($profitSettings['platform_deductions'] ?? null) ? $profitSettings['platform_deductions'] : [];
        $storeDeductionEnabled = (bool) ($profitSettings['store_deduction_enabled'] ?? true);
        $stores = [];
        foreach ($this->store->stores($tenantKey) as $store) {
            $stores[(string) ($store['name'] ?? '')] = $store;
        }

        $rows = [];
        foreach ($orders as $order) {
            $sales = (float) ($order['total'] ?? 0);
            $purchaseCost = array_sum(array_map(
                fn (array $item): float => (float) ($item['amount'] ?? 0) * max(1, (int) ($item['quantity'] ?? 1)),
                $order['items'] ?? []
            ));
            $intlFee = array_sum(array_map(
                fn (array $item): float => $defaultIntlFee * max(1, (int) ($item['quantity'] ?? 1)),
                $order['items'] ?? []
            ));
            $store = $stores[(string) ($order['store'] ?? '')] ?? [];
            $deduction = $platformDeductions[(string) ($order['platform'] ?? '')] ?? 70;
            $deductionSource = '平台扣点';
            if ($storeDeductionEnabled && isset($store['profit_deduction']) && is_numeric($store['profit_deduction'])) {
                $deduction = $store['profit_deduction'];
                $deductionSource = '店铺扣点';
            }
            $feeRatio = $this->deductionFeeRatio($deduction);
            $platformFee = round($sales * $feeRatio, 2);
            $profit = (float) ($order['total'] ?? 0) - $purchaseCost - $intlFee - $platformFee;
            $rows[] = [
                'order_no' => $order['platform_order_id'] ?? '',
                'store' => $order['store'] ?? '',
                'platform' => $order['platform'] ?? '',
                'sales' => $sales,
                'purchase_cost' => $purchaseCost,
                'intl_fee' => $intlFee,
                'platform_fee' => $platformFee,
                'deduction' => round((float) $deduction, 2),
                'deduction_source' => $deductionSource,
                'exchange_rate' => $exchangeRate,
                'sales_converted' => round($sales * $exchangeRate, 2),
                'profit' => $profit,
                'margin' => (float) ($order['total'] ?? 0) > 0 ? round($profit / (float) $order['total'] * 100, 1) : 0,
            ];
        }

        return [
            'rows' => $rows,
            'settings' => [
                'exchange_rate' => $exchangeRate,
                'default_intl_fee' => $defaultIntlFee,
                'store_deduction_enabled' => $storeDeductionEnabled,
                'platform_deductions' => $platformDeductions,
            ],
            'order_count' => count($rows),
            'sales' => array_sum(array_column($rows, 'sales')),
            'sales_converted' => array_sum(array_column($rows, 'sales_converted')),
            'purchase_cost' => array_sum(array_column($rows, 'purchase_cost')),
            'intl_fee' => array_sum(array_column($rows, 'intl_fee')),
            'platform_fee' => array_sum(array_column($rows, 'platform_fee')),
            'profit' => array_sum(array_column($rows, 'profit')),
        ];
    }

    /** @return array<string, mixed> */
    /** @param array<string, mixed>|null $user */
    public function purchaseStats(string $tenantKey, ?array $user = null): array
    {
        $buyers = [];
        $statuses = [];
        foreach ($this->flattenItems($this->ordersForUser($tenantKey, $user)) as $item) {
            if (($item['source_type'] ?? '') !== 'cn_purchase') {
                continue;
            }

            $buyer = (string) (($item['buyer'] ?? '') ?: '未分配');
            $status = (string) (($item['purchase_status'] ?? '') ?: '待处理');
            $buyers[$buyer] = ($buyers[$buyer] ?? 0) + 1;
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
        }

        arsort($buyers);
        arsort($statuses);

        return [
            'buyers' => $buyers,
            'statuses' => $statuses,
            'total' => array_sum($buyers),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    /** @param array<string, mixed>|null $user */
    public function logisticsRows(string $tenantKey, string $type, ?array $user = null): array
    {
        $rows = [];
        foreach ($this->ordersForUser($tenantKey, $user) as $order) {
            foreach ($order['items'] ?? [] as $item) {
                if ($type === '1688' && ($item['source_type'] ?? '') !== 'cn_purchase') {
                    continue;
                }
                if ($type === 'jp' && ($item['source_type'] ?? '') !== 'jp_stock') {
                    continue;
                }

                $rows[] = [
                    'order_no' => $order['platform_order_id'] ?? '',
                    'item' => $item['title'] ?? '',
                    'tracking_no' => $type === '1688' ? ($item['tabaono'] ?? '') : (($item['intl_number'] ?? '') ?: ($item['ship_number'] ?? '')),
                    'carrier' => $item['ship_company'] ?? '',
                    'status' => ($item['logistics'] ?? '') ?: ($item['purchase_status'] ?? ''),
                    'updated_at' => $item['purchase_time'] ?: ($order['order_date'] ?? ''),
                ];
            }
        }

        return $rows;
    }

    /** @return array<int, array<string, string|int>> */
    public function mailAccounts(): array
    {
        return [
            ['store' => 'Yahoo 一号店', 'address' => 'support-y@example.jp', 'folder' => 'INBOX', 'unread' => 8, 'status' => '待配置 IMAP'],
            ['store' => '乐天旗舰店', 'address' => 'rakuten@example.jp', 'folder' => 'INBOX', 'unread' => 3, 'status' => '待配置 SMTP'],
            ['store' => 'Wowma 综合店', 'address' => 'wowma@example.jp', 'folder' => 'Customer', 'unread' => 5, 'status' => '待同步'],
        ];
    }

    /** @return array<int, array<string, string>> */
    public function importExportJobs(): array
    {
        return [
            ['key' => 'platform_orders_import', 'name' => '平台订单 CSV 导入', 'source' => 'old/*/orderinsert.php', 'status' => '已接 CSV 写入', 'scope' => '乐天 / Yahoo / Wowma / Mercari / Qoo10 / 雅虎拍卖', 'direction' => 'import'],
            ['key' => 'platform_export', 'name' => '平台订单表导出', 'source' => 'old/*/sendxizhends.php', 'status' => '已接当前筛选导出', 'scope' => '按当前平台 / 店铺 / 筛选条件', 'direction' => 'export'],
            ['key' => 'delivery_notice_export', 'name' => '发货通知表导出', 'source' => 'old/*/outexcel3.php', 'status' => '已接当前筛选导出', 'scope' => '客户邮件、订单号、国际运单状态', 'direction' => 'export'],
            ['key' => 'purchase_export', 'name' => '采购表导出', 'source' => 'old/*/caigou_export.php', 'status' => '已接 CSV 导出', 'scope' => '国内采购子商品、采购链接、1688 单号', 'direction' => 'export'],
            ['key' => 'purchase_import', 'name' => '采购表导入', 'source' => 'old/*/caigou_import.php', 'status' => '已接 CSV 更新', 'scope' => '采购状态、采购金额、采购人、采购时间', 'direction' => 'import'],
            ['key' => 'shipping_import', 'name' => '国际运单导入', 'source' => 'old/*/shipping_import.php', 'status' => '已接 CSV 更新', 'scope' => '国际运单号、运费、重量、件数', 'direction' => 'import'],
            ['key' => 'shipment_export', 'name' => '发货表导出', 'source' => 'old/*/outexcel.php', 'status' => '已接 CSV 导出', 'scope' => '平台发货通知 / 已发日本', 'direction' => 'export'],
            ['key' => 'finance_export', 'name' => '财务表导出', 'source' => 'old/*/outcwexcel.php', 'status' => '已接 CSV 导出', 'scope' => '销售额、采购额、运费、扣点、利润', 'direction' => 'export'],
            ['key' => 'customers_export', 'name' => '客户资料导出', 'source' => 'old/*/custinfo_export.php', 'status' => '已接 CSV 导出', 'scope' => '按平台 / 店铺 / 日期', 'direction' => 'export'],
            ['key' => 'logistics_export', 'name' => '物流表导出', 'source' => 'old/*/wuliu_export.php', 'status' => '已接 CSV 导出', 'scope' => '国内/国际运单与状态', 'direction' => 'export'],
        ];
    }

    /** @return array<int, array<string, string>> */
    public function importExportJobsForTenant(string $tenantKey): array
    {
        $features = $this->tenantFeatureMap($tenantKey);

        return array_values(array_filter(
            $this->importExportJobs(),
            fn (array $job): bool => ($features[$this->importExportJobFeature((string) ($job['key'] ?? ''))] ?? false)
        ));
    }

    /**
     * @param array<string, mixed>|null $user
     * @return array{name: string, filename: string, headers: array<int, string>, rows: array<int, array<int, mixed>>}
     */
    public function exportDataset(string $tenantKey, string $type, ?array $user, array $criteria = []): array
    {
        $orders = $this->ordersForExport($tenantKey, $user, $criteria);
        $platformNames = $this->platformNames();
        $platformCode = (string) ($criteria['platform'] ?? '');
        $today = date('Ymd-His');

        if ($type === 'finance') {
            $summary = $this->profitSummaryForOrders($tenantKey, $orders);
            return [
                'name' => '财务利润表',
                'filename' => "finance-{$tenantKey}-{$today}.csv",
                'headers' => ['订单号', '店铺', '平台', '销售额', '采购成本', '国际运费', '扣点来源', '扣点', '平台费', '毛利', '毛利率', '汇率', '折算销售额'],
                'rows' => array_map(fn (array $row): array => [
                    $row['order_no'],
                    $row['store'],
                    $platformNames[(string) ($row['platform'] ?? '')] ?? ($row['platform'] ?? ''),
                    $row['sales'],
                    $row['purchase_cost'],
                    $row['intl_fee'],
                    $row['deduction_source'],
                    $row['deduction'],
                    $row['platform_fee'],
                    $row['profit'],
                    $row['margin'] . '%',
                    $row['exchange_rate'],
                    $row['sales_converted'],
                ], $summary['rows']),
            ];
        }

        if ($type === 'platform') {
            $name = $platformCode !== ''
                ? (($platformNames[$platformCode] ?? $platformCode) . '订单表')
                : '平台订单表';

            return [
                'name' => $name,
                'filename' => "platform-{$tenantKey}-{$today}.csv",
                'headers' => ['店铺番号', '平台', '订单编号', '订单日期', '产品ID', '产品信息', '变量1', '变量2', '数量', '送付先 氏名', '郵便番号', '住所', '箱号', '信封大小', '配送方式', '客人邮箱', '电话号码'],
                'rows' => $this->platformExportRows($orders, $platformNames),
            ];
        }

        if ($type === 'delivery_notice') {
            return [
                'name' => '发货通知表',
                'filename' => "delivery-notice-{$tenantKey}-{$today}.csv",
                'headers' => ['邮件地址', '姓名', '性别', '生日', '手机', '地区', '变量1', '变量2', '变量3', '变量4', '变量5', '变量6', '变量7', '变量8', '变量9', '变量10'],
                'rows' => $this->deliveryNoticeRows($orders),
            ];
        }

        if ($type === 'customers') {
            return [
                'name' => '客户资料',
                'filename' => "customers-{$tenantKey}-{$today}.csv",
                'headers' => ['平台', '店铺', '订单号', '姓名', '电话', '邮编', '地址', '邮箱', '下单时间', '订单金额'],
                'rows' => array_map(fn (array $order): array => [
                    $platformNames[(string) ($order['platform'] ?? '')] ?? ($order['platform'] ?? ''),
                    $order['store'] ?? '',
                    $order['platform_order_id'] ?? '',
                    $order['customer']['name'] ?? '',
                    $order['customer']['phone'] ?? '',
                    $order['customer']['zip'] ?? '',
                    $order['customer']['address'] ?? '',
                    $order['customer']['mail'] ?? '',
                    $order['order_date'] ?? '',
                    $order['total'] ?? 0,
                ], $orders),
            ];
        }

        if ($type === 'shipment' || $type === 'logistics') {
            $rows = [];
            foreach ($orders as $order) {
                foreach ($order['items'] ?? [] as $item) {
                    $rows[] = [
                        $platformNames[(string) ($order['platform'] ?? '')] ?? ($order['platform'] ?? ''),
                        $order['store'] ?? '',
                        $order['platform_order_id'] ?? '',
                        $item['item_code'] ?? '',
                        $item['title'] ?? '',
                        $item['source_type'] ?? '',
                        $item['ship_company'] ?? '',
                        $item['ship_number'] ?? '',
                        $item['tabaono'] ?? '',
                        $item['logistics'] ?? '',
                        $item['out_status'] ?? '',
                    ];
                }
            }

            return [
                'name' => $type === 'shipment' ? '发货表' : '物流表',
                'filename' => "{$type}-{$tenantKey}-{$today}.csv",
                'headers' => ['平台', '店铺', '订单号', 'ItemId', '商品名', '货源地', '物流公司', '国内运单号', '1688订单号', '物流状态', '出库状态'],
                'rows' => $rows,
            ];
        }

        $rows = [];
        foreach ($orders as $order) {
            foreach ($order['items'] ?? [] as $item) {
                if ($type === 'purchase' && ($item['source_type'] ?? '') !== 'cn_purchase') {
                    continue;
                }

                $rows[] = [
                    $platformNames[(string) ($order['platform'] ?? '')] ?? ($order['platform'] ?? ''),
                    $order['store'] ?? '',
                    $order['platform_order_id'] ?? '',
                    $order['order_date'] ?? '',
                    $item['item_code'] ?? '',
                    $item['title'] ?? '',
                    $item['option'] ?? '',
                    $item['quantity'] ?? 0,
                    $item['source_type'] ?? '',
                    $item['purchase_status'] ?? '',
                    $item['buyer'] ?? '',
                    $item['purchase_time'] ?? '',
                    $item['purchase_link'] ?? '',
                    $item['amount'] ?? 0,
                    $item['tabaono'] ?? '',
                    $item['ship_company'] ?? '',
                    $item['ship_number'] ?? '',
                ];
            }
        }

        return [
            'name' => '采购表',
            'filename' => "purchase-{$tenantKey}-{$today}.csv",
            'headers' => ['平台', '店铺', '订单号', '订单时间', 'ItemId', '商品名', '规格', '数量', '货源地', '采购状态', '采购人', '采购时间', '采购链接', '采购金额', '1688订单号', '物流公司', '国内运单号'],
            'rows' => $rows,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @param array<string, string> $platformNames
     * @return array<int, array<int, mixed>>
     */
    private function platformExportRows(array $orders, array $platformNames): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
            foreach ($order['items'] ?? [] as $item) {
                $rows[] = [
                    $order['store'] ?? '',
                    $platformNames[(string) ($order['platform'] ?? '')] ?? ($order['platform'] ?? ''),
                    $order['platform_order_id'] ?? '',
                    $order['order_date'] ?? '',
                    ($item['item_code'] ?? '') ?: ($item['lot_number'] ?? ''),
                    $item['title'] ?? '',
                    $item['option'] ?? '',
                    $item['chinese_option'] ?? '',
                    $item['quantity'] ?? 0,
                    $customer['name'] ?? '',
                    $customer['zip'] ?? '',
                    $customer['address'] ?? '',
                    '',
                    '',
                    $order['ship_method'] ?? '',
                    $customer['mail'] ?? '',
                    $customer['phone'] ?? '',
                ];
            }
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array<int, mixed>>
     */
    private function deliveryNoticeRows(array $orders): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
            $shipNumbers = array_values(array_filter(array_map(
                static fn (array $item): string => trim((string) (($item['intl_number'] ?? '') ?: ($item['ship_number'] ?? ''))),
                $order['items'] ?? []
            )));
            $rows[] = [
                $customer['mail'] ?? '',
                '',
                '',
                '',
                '',
                '',
                $customer['name'] ?? '',
                $order['platform_order_id'] ?? '',
                implode(' / ', array_unique($shipNumbers)),
                $order['store'] ?? '',
                '',
                '',
                '',
                '',
                '',
                '',
            ];
        }

        return $rows;
    }

    /** @return array<int, array<string, string>> */
    public function mediaTasks(): array
    {
        return [
            ['name' => '主图下载', 'path' => 'old/cron/zhutu_downloader.php', 'status' => '待接任务队列', 'note' => '把平台图片缓存到租户文件区'],
            ['name' => '图片上传', 'path' => 'old/orderr/ajax/image_upload.php 等', 'status' => '待接上传接口', 'note' => '统一替换 6 平台重复实现'],
            ['name' => '旧图清理', 'path' => 'old/cron/cleanup_old_images.php', 'status' => '待接保留策略', 'note' => '按订单归档时间清理缓存'],
        ];
    }

    /** @return array<string, mixed> */
    /** @param array<string, mixed>|null $user */
    public function tenantMediaLibrary(string $tenantKey, ?array $user = null): array
    {
        $orders = $this->ordersForUser($tenantKey, $user);
        $items = $this->flattenItems($orders);
        $imageItems = array_values(array_filter($items, fn (array $item): bool => trim((string) ($item['image'] ?? '')) !== ''));
        $base = "storage/tenants/{$tenantKey}/images";

        return [
            'base' => $base,
            'summary' => [
                ['label' => '订单图片', 'value' => (string) count($imageItems), 'note' => '来自平台订单主图与 SKU 图缓存'],
                ['label' => '上传附件', 'value' => '0', 'note' => '采购、客服、品检上传凭证后进入当前租户附件区'],
                ['label' => '隔离范围', 'value' => $tenantKey, 'note' => '不与其他公司共用目录或索引'],
                ['label' => '清理策略', 'value' => '按归档', 'note' => '订单归档或超管保留策略触发清理'],
            ],
            'buckets' => [
                [
                    'name' => '订单主图缓存',
                    'path' => "{$base}/orders/",
                    'scope' => '平台订单商品主图、SKU 图',
                    'owner' => '平台同步 / 主图下载任务',
                    'status' => count($imageItems) > 0 ? '已有引用' : '待同步',
                    'policy' => '订单未归档时保留；订单归档后按超管策略清理。',
                ],
                [
                    'name' => '员工上传附件',
                    'path' => "{$base}/uploads/",
                    'scope' => '采购凭证、客服沟通截图、品检照片',
                    'owner' => '租户员工',
                    'status' => '待接上传接口',
                    'policy' => '按订单号、子商品 ID 和上传人记录，禁止跨租户读取。',
                ],
                [
                    'name' => '临时处理缓存',
                    'path' => "{$base}/cache/",
                    'scope' => '图片压缩、格式转换、导入预览',
                    'owner' => '后台任务',
                    'status' => '待接任务队列',
                    'policy' => '临时文件按小时或任务完成状态清理。',
                ],
                [
                    'name' => '归档图片',
                    'path' => "{$base}/archive/",
                    'scope' => '已归档订单的保留图片',
                    'owner' => '订单归档任务',
                    'status' => '待接归档',
                    'policy' => '只保留业务要求的图片，过期后由超管策略统一清理。',
                ],
            ],
            'references' => [
                ['name' => '主图下载', 'old' => 'old/cron/zhutu_downloader.php', 'new' => "{$base}/orders/{order_id}/{item_id}/main.*"],
                ['name' => '图片上传', 'old' => 'old/orderr/ajax/image_upload.php', 'new' => "{$base}/uploads/{order_id}/{item_id}/"],
                ['name' => '旧图清理', 'old' => 'old/cron/cleanup_old_images.php', 'new' => "{$base}/archive/ + {$base}/cache/"],
            ],
        ];
    }

    /** @return array<int, array<string, string>> */
    public function jobDefinitions(): array
    {
        $cronJobs = array_map(static fn (array $job): array => [
            'name' => (string) ($job['name'] ?? ''),
            'old' => (string) ($job['old'] ?? $job['old_source'] ?? ''),
            'schedule' => (string) ($job['schedule'] ?? ''),
            'status' => '已接 CLI 任务',
        ], (new CronTaskRegistry($this->store))->definitions());

        return array_merge($cronJobs, [
            ['name' => '订单监控', 'old' => 'cron/order_monitor.php', 'schedule' => '每 5 分钟', 'status' => '待接平台同步'],
            ['name' => '主图下载', 'old' => 'cron/zhutu_downloader.php', 'schedule' => '每天夜间', 'status' => '待接任务队列'],
            ['name' => '订单归档', 'old' => 'cron/order_archive.php', 'schedule' => '每月', 'status' => 'MySQL 后启用'],
            ['name' => '邮件同步', 'old' => 'cron/mail_sync.php', 'schedule' => '每 10 分钟', 'status' => '待接 imap 扩展'],
        ]);
    }

    /** @return array<int, array<string, string>> */
    /** @param array<string, mixed>|null $user */
    public function auditLogs(string $tenantKey, ?array $user = null): array
    {
        $logs = [];
        foreach ($this->ordersForUser($tenantKey, $user) as $order) {
            foreach ($order['items'] ?? [] as $item) {
                foreach ($item['logs'] ?? [] as $log) {
                    $logs[] = [
                        'time' => (string) ($log['time'] ?? ''),
                        'order_no' => (string) ($order['platform_order_id'] ?? ''),
                        'user' => (string) ($log['user'] ?? ''),
                        'action' => (string) ($log['action'] ?? ''),
                        'field' => (string) ($log['field'] ?? ''),
                        'change' => (string) (($log['old'] ?? '-') . ' → ' . ($log['new'] ?? '-')),
                        'ip' => (string) ($log['ip'] ?? ''),
                    ];
                }
            }
        }

        return array_slice(array_reverse($logs), 0, 50);
    }

    /** @return array<int, array<string, mixed>> */
    public function settingsGroups(): array
    {
        return [
            ['group' => '公司资料', 'items' => ['公司名', '简称', '联系人', '电话', '地址', '业务备注']],
            ['group' => '安全与权限', 'items' => ['登录会话', '两步验证重置', '管理员', '采购', '客服', '品检', '操作权限']],
            ['group' => '店铺范围', 'items' => ['隐藏店铺', '员工店铺分配', '平台授权', '店铺级 API 状态']],
            ['group' => '店铺级 API 凭证', 'items' => ['1688', 'Yahoo 店铺', 'Rakuten RMS 店铺', 'Wowma 店铺', 'Mercari 店铺', 'Qoo10 店铺', '物流查询账号']],
            ['group' => '订单参数', 'items' => ['售价预警指数', '默认查询天数', '默认分页', '归档周期', '状态字典']],
            ['group' => '利润与物流', 'items' => ['汇率', '平台扣点', '默认运费', '国内签收地', '承运商映射', '运单前缀映射']],
        ];
    }

    /** @return array<string, array<int, string>> */
    public function rolePermissionMatrix(): array
    {
        return Permission::roleDefaults();
    }

    /**
     * @param array<string, array<int, array<string, string>>> $groups
     * @return array<string, array<int, array<string, string>>>
     */
    private function filterFeatureGroups(string $tenantKey, array $groups): array
    {
        $features = $this->tenantFeatureMap($tenantKey);
        $filtered = [];
        foreach ($groups as $groupName => $items) {
            $visibleItems = array_values(array_filter(
                $items,
                static fn (array $item): bool => ($features[(string) ($item['feature'] ?? '')] ?? true)
            ));
            if ($visibleItems) {
                $filtered[$groupName] = $visibleItems;
            }
        }

        return $filtered;
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private function filterOrdersByTenantPlatforms(string $tenantKey, array $orders): array
    {
        $allowed = array_flip($this->enabledPlatformCodes($tenantKey));
        if (!$allowed) {
            return [];
        }

        return array_values(array_filter(
            $orders,
            static fn (array $order): bool => isset($allowed[(string) ($order['platform'] ?? '')])
        ));
    }

    private function importExportJobFeature(string $jobKey): string
    {
        return match ($jobKey) {
            'platform_orders_import', 'platform_export', 'delivery_notice_export', 'shipment_export' => 'import_export.platform',
            'purchase_export', 'purchase_import' => 'import_export.purchase',
            'finance_export' => 'import_export.finance',
            'shipping_import', 'logistics_export' => 'import_export.logistics',
            'customers_export' => 'customers.data',
            default => 'import_export.center',
        };
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private function flattenItems(array $orders): array
    {
        $items = [];
        foreach ($orders as $order) {
            foreach ($order['items'] ?? [] as $item) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * @param array<string, mixed>|null $user
     * @param array<string, mixed> $criteria
     * @return array<int, array<string, mixed>>
     */
    public function ordersForExport(string $tenantKey, ?array $user, array $criteria): array
    {
        $view = (string) ($criteria['view'] ?? 'platform');
        $view = in_array($view, ['platform', 'purchase', 'jp'], true) ? $view : 'platform';
        $source = (string) ($criteria['source'] ?? 'all');
        $platform = trim((string) ($criteria['platform'] ?? ''));
        $keyword = trim((string) ($criteria['keyword'] ?? ''));
        $filters = is_array($criteria['filters'] ?? null) ? $criteria['filters'] : [];

        $orders = $this->filterOrdersForView(
            $this->ordersForUser($tenantKey, $user),
            $view,
            $platform !== '' ? $platform : null,
            $source,
            $keyword !== '' ? $keyword : null,
            $filters
        );

        return $this->restrictOrdersToSelection(
            $orders,
            $this->intList($criteria['item_ids'] ?? []),
            $this->intList($criteria['order_ids'] ?? [])
        );
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @param array<int, int> $itemIds
     * @param array<int, int> $orderIds
     * @return array<int, array<string, mixed>>
     */
    private function restrictOrdersToSelection(array $orders, array $itemIds, array $orderIds): array
    {
        if (!$itemIds && !$orderIds) {
            return $orders;
        }

        $result = [];
        foreach ($orders as $order) {
            $orderSelected = in_array((int) ($order['id'] ?? 0), $orderIds, true);
            $copy = $order;
            $copy['items'] = array_values(array_filter(
                $order['items'] ?? [],
                static fn (array $item): bool => $orderSelected || in_array((int) ($item['id'] ?? 0), $itemIds, true)
            ));
            if ($copy['items']) {
                $result[] = $copy;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed>|null $user
     * @param array<string, mixed> $criteria
     * @param array<int, int> $explicitItemIds
     * @param array<int, int> $explicitOrderIds
     * @return array<int, int>
     */
    public function itemIdsForLogisticsUpdate(string $tenantKey, ?array $user, string $type, array $criteria, array $explicitItemIds = [], array $explicitOrderIds = []): array
    {
        $explicitItemIds = array_values(array_unique(array_filter(array_map('intval', $explicitItemIds))));
        $explicitOrderIds = array_values(array_unique(array_filter(array_map('intval', $explicitOrderIds))));
        $candidateOrders = $explicitItemIds || $explicitOrderIds
            ? $this->ordersForUser($tenantKey, $user)
            : $this->ordersForExport($tenantKey, $user, $criteria);

        $ids = [];
        foreach ($candidateOrders as $order) {
            $orderSelected = !$explicitOrderIds || in_array((int) ($order['id'] ?? 0), $explicitOrderIds, true);
            foreach ($order['items'] ?? [] as $item) {
                $itemId = (int) ($item['id'] ?? 0);
                if ($explicitItemIds && !in_array($itemId, $explicitItemIds, true)) {
                    continue;
                }
                if (!$orderSelected && !$explicitItemIds) {
                    continue;
                }
                if (!$this->itemMatchesLogisticsType($item, $type)) {
                    continue;
                }
                $ids[] = $itemId;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    public function logisticsUpdateStatus(string $type): string
    {
        return match ($type) {
            '1688' => '已触发1688物流同步',
            'express' => '已触发TB/PDD物流同步，等待接口回写',
            'jp' => '已触发国际物流同步，等待接口回写',
            default => '已触发物流同步，等待接口回写',
        };
    }

    public function logisticsUpdateName(string $type): string
    {
        return match ($type) {
            '1688' => '更新物流信息(1688)',
            'express' => '更新物流信息(TB/PDD)',
            'jp' => '更新国际物流状态',
            default => '更新物流信息',
        };
    }

    /** @param array<string, mixed> $order */
    private function orderMatchesFilters(array $order, array $filters): bool
    {
        $checks = [
            'store' => $order['store'] ?? '',
            'customer_name' => $order['customer']['name'] ?? '',
            'mail' => $order['customer']['mail'] ?? '',
            'phone' => $order['customer']['phone'] ?? '',
        ];
        foreach ($checks as $key => $value) {
            $needle = trim((string) ($filters[$key] ?? ''));
            if ($needle !== '' && !str_contains(strtolower((string) $value), strtolower($needle))) {
                return false;
            }
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        $orderDate = substr((string) ($order['order_date'] ?? ''), 0, 10);
        if ($dateFrom !== '' && $orderDate !== '' && $orderDate < $dateFrom) {
            return false;
        }
        if ($dateTo !== '' && $orderDate !== '' && $orderDate > $dateTo) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $item */
    private function itemMatchesFilters(array $item, string $view, array $filters): bool
    {
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== '__ALL__') {
            $currentStatus = $view === 'jp'
                ? (string) ($item['out_status'] ?? '')
                : (string) ($item['purchase_status'] ?? '');
            if ($currentStatus !== $status) {
                return false;
            }
        }

        $checks = [
            'tabaono' => $item['tabaono'] ?? '',
            'item_id' => implode(' ', [$item['item_code'] ?? '', $item['title'] ?? '', $item['jp_warehouse_id'] ?? '']),
            'product_name' => implode(' ', [$item['title'] ?? '', $item['option'] ?? '']),
            'buyer' => $view === 'jp' ? ($item['assignee'] ?? '') : ($item['buyer'] ?? ''),
            'cn_ship_no' => $item['ship_number'] ?? '',
            'intl_ship_no' => ($item['intl_number'] ?? '') ?: ($item['ship_number'] ?? ''),
            'carrier' => $item['ship_company'] ?? '',
            'location' => ($item['location'] ?? '') ?: ($item['jp_warehouse_id'] ?? ''),
        ];
        foreach ($checks as $key => $value) {
            $needle = trim((string) ($filters[$key] ?? ''));
            if ($needle !== '' && !str_contains(strtolower((string) $value), strtolower($needle))) {
                return false;
            }
        }

        if (!empty($filters['late_ship']) && !$this->isLateShipItem($item)) {
            return false;
        }
        if (!empty($filters['in_delivery']) && !str_contains((string) ($item['logistics'] ?? ''), '配達中')) {
            return false;
        }
        if (!empty($filters['delivered']) && !str_contains((string) ($item['logistics'] ?? ''), '配達完了')) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $item */
    private function itemMatchesLogisticsType(array $item, string $type): bool
    {
        if ($type === '1688') {
            return ($item['source_type'] ?? '') === 'cn_purchase'
                && trim((string) ($item['tabaono'] ?? '')) !== '';
        }
        if ($type === 'express') {
            return ($item['source_type'] ?? '') === 'cn_purchase'
                && trim((string) ($item['ship_number'] ?? '')) !== '';
        }
        if ($type === 'jp') {
            return in_array((string) ($item['purchase_status'] ?? ''), ['已发货代订单', '已发日本', '已发出荷通知'], true)
                || trim((string) (($item['intl_number'] ?? '') ?: ($item['ship_number'] ?? ''))) !== '';
        }

        return false;
    }

    /** @param array<string, mixed> $item */
    private function isLateShipItem(array $item): bool
    {
        $status = (string) ($item['purchase_status'] ?? '');
        if (!in_array($status, ['国内采购-已采购', '国内采购-TB/PDD已采购', '发货中'], true)) {
            return false;
        }

        $purchaseTime = strtotime((string) ($item['purchase_time'] ?? ''));
        return $purchaseTime !== false && $purchaseTime < strtotime('-3 days');
    }

    private function positiveFloat(mixed $value, float $default): float
    {
        $number = is_numeric($value) ? (float) $value : $default;
        return $number > 0 ? $number : $default;
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function intList(mixed $value): array
    {
        if (is_string($value) && str_contains($value, ',')) {
            $value = explode(',', $value);
        }

        $values = is_array($value) ? $value : [$value];
        return array_values(array_unique(array_filter(array_map('intval', $values))));
    }

    private function deductionFeeRatio(mixed $deduction): float
    {
        $number = is_numeric($deduction) ? (float) $deduction : 70.0;
        $number = max(0.0, min(100.0, $number));

        return $number > 30 ? round((100.0 - $number) / 100.0, 4) : round($number / 100.0, 4);
    }
}
