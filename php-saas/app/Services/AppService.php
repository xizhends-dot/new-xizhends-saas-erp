<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\Permission;
use Xizhen\Core\StoreInterface;
use Xizhen\Core\TenantFeature;

final class AppService
{

    /** @var array<int, string> */
    public const PURCHASE_STATUSES = [
        '未处理的订单',
        '国内采购-准备',
        '国内采购--问题',
        '国内采购-已采购',
        '国内采购-TB/PDD已采购',
        '发货中',
        '已到货',
        '已发货代订单',
        '已发日本',
        '已发出荷通知',
        '日本仓库存订单',
        '日本仓库已发出荷通知',
        '客人取消订单',
        '问题订单(后台处理)',
        '库存缺货订单',
        '刷单订单',
        '已取消',
    ];

    private readonly ProfitService $profitService;

    private readonly OrderFilterService $orderFilterService;

    private readonly ExportDatasetService $exportDatasetService;

    public function __construct(private readonly StoreInterface $store)
    {
        $this->orderFilterService = new OrderFilterService($store);
        $this->profitService = new ProfitService($store);
        $this->exportDatasetService = new ExportDatasetService($store, $this->orderFilterService, $this->profitService);
    }


    /** @return array<int, string> */
    public function purchaseStatuses(string $tenantKey): array
    {
        return (new PurchaseStatusService($this->store))->statusesFor($tenantKey);
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
        return $this->orderFilterService->filterOrdersForView($orders, $view, $platform, $source, $keyword, $filters);
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
                ['feature' => 'logistics.express', 'title' => 'TB/PDD 物流', 'desc' => '对应 old/plugins/express-showapi 的国内快递查询。', 'href' => "/logistics/express?tenant={$tenantKey}", 'status' => '已接 ShowAPI 占位配置'],
                ['feature' => 'logistics.jp', 'title' => '日本物流', 'desc' => '对应 jpshipinfo、sagawa-shipinfo 与 update_jpship_logistics.php。', 'href' => "/logistics/jp?tenant={$tenantKey}", 'status' => '已接 API/CLI'],
                ['feature' => 'logistics.jp', 'title' => '运单核对', 'desc' => '对应 checkyd 与 jpyd-check，核对国内/国际运单并跳转日本快递官网。', 'href' => "/logistics/waybill-check?tenant={$tenantKey}", 'status' => '已接页面'],
                ['feature' => 'mail.center', 'title' => '客服邮件中心', 'desc' => '对应 old/kefu_mail 与 cron/mail_sync.php。', 'href' => "/mail?tenant={$tenantKey}", 'status' => '已接 IMAP/SMTP'],
                ['feature' => 'media.library', 'title' => '租户图片库', 'desc' => '按公司隔离订单主图、SKU 图、上传凭证和旧图清理策略。', 'href' => "/media?tenant={$tenantKey}", 'status' => '页面已接'],
            ],
            '经营分析' => [
                ['feature' => 'analytics.profit', 'title' => '利润分析', 'desc' => '对应 old/plugins/profit-analysis。', 'href' => "/analytics/profit?tenant={$tenantKey}", 'status' => '开发数据'],
                ['feature' => 'stats.purchase', 'title' => '采购业绩统计', 'desc' => '对应 old/plugins/caigou_stats，按采购员统计完成采购、采购金额和 1688 单号。', 'href' => "/stats/purchase?tenant={$tenantKey}", 'status' => '开发数据'],
                ['feature' => 'stats.performance', 'title' => '业绩统计', 'desc' => '对应 old/performance，按日、平台、店铺聚合订单和金额。', 'href' => "/performance?tenant={$tenantKey}", 'status' => '已接页面'],
                ['feature' => 'stats.products', 'title' => '出单商品分析', 'desc' => '对应 old/performance/product_analysis，按商品编码统计热卖排名。', 'href' => "/performance/products?tenant={$tenantKey}", 'status' => '已接页面'],
                ['feature' => 'stats.shipping_anomaly', 'title' => '异常运费检测', 'desc' => '对应 old/plugins/shipping-anomaly，按商品 ID 与数量聚合对比国际运费。', 'href' => "/stats/shipping-anomaly?tenant={$tenantKey}", 'status' => '已接页面'],
                ['feature' => 'tools.price_calculator', 'title' => '核价计算器', 'desc' => '对应 old/price_calculator.php，支持多行成本核算和目标利润反推售价。', 'href' => "/price-calculator?tenant={$tenantKey}", 'status' => '已接页面'],
                ['feature' => 'import_export.center', 'title' => '导入导出', 'desc' => '对应 Excel 导入、物流导入、客户资料导出。', 'href' => "/import-export?tenant={$tenantKey}", 'status' => '页面已接'],
                ['feature' => 'management.jobs', 'title' => '定时任务状态', 'desc' => '租户只查看同步状态；频率、开关和失败重试由超管设置。', 'href' => "/jobs?tenant={$tenantKey}", 'status' => '只读'],
            ],
            '权限与体系' => [
                ['feature' => 'management.stores', 'title' => '店铺管理', 'desc' => '承接隐藏店铺、店铺扣点、店铺级 API 配置和平台状态。', 'href' => "/stores?tenant={$tenantKey}", 'status' => '已接后端'],
                ['feature' => 'management.users', 'title' => '员工管理', 'desc' => '承接管理员、采购、客服角色、首选入口、1688 配置和店铺范围。', 'href' => "/users?tenant={$tenantKey}", 'status' => '已接后端'],
                ['feature' => 'account.password_edit', 'title' => '员工自助改密码', 'desc' => '对应 old/pwdedit.php，改用 password_hash 和旧密码校验。', 'href' => "/password/edit?tenant={$tenantKey}", 'status' => '已接页面'],
                ['feature' => 'management.notices', 'title' => '通知公告', 'desc' => '对应 old/notice，租户管理员发布公告，员工在首页和订单页可见。', 'href' => "/notices?tenant={$tenantKey}", 'status' => '已接页面'],
                ['feature' => 'management.user_permission_overrides', 'title' => '细粒度权限', 'desc' => '对应 old/user_permissions.php，支持单员工 allow/deny 覆盖。', 'href' => "/users/permissions?tenant={$tenantKey}", 'status' => '已接页面'],
                ['feature' => 'management.customer_service_deductions', 'title' => '客服扣点', 'desc' => '对应 old 用户列表快捷扣点编辑，保存到租户利润设置。', 'href' => "/users/customer-service-deductions?tenant={$tenantKey}", 'status' => '已接页面'],
                ['feature' => 'management.assignments', 'title' => '店铺分配', 'desc' => '承接旧 ph_userlevel，维护采购与客服店铺关系。', 'href' => "/assignments?tenant={$tenantKey}", 'status' => '已接后端'],
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
        return $this->profitService->profitSummary($tenantKey, $user);
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<string, mixed>
     */
    public function profitSummaryForOrders(string $tenantKey, array $orders): array
    {
        return $this->profitService->profitSummaryForOrders($tenantKey, $orders);
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
                if ($type === 'express' && (($item['source_type'] ?? '') !== 'cn_purchase' || trim((string) ($item['ship_number'] ?? '')) === '')) {
                    continue;
                }
                if ($type === 'jp' && !$this->isJapanLogisticsItem($item)) {
                    continue;
                }

                $log = $this->latestLogisticsLog($item, $type);
                $trackingNo = $this->logisticsTrackingNo($item, $type);
                $status = (string) (($item['logistics'] ?? '') ?: ($item['purchase_status'] ?? ''));
                $date = $this->legacyLogisticsDate($log, $item, $order);
                $message = $this->legacyLogisticsMessage($log, $status, $trackingNo);
                $ok = $trackingNo !== '' && ($status !== '' || $this->isSuccessfulLogisticsLog($log));
                $platformName = $this->legacyPlatformName($type, (string) ($order['platform'] ?? ''));

                $rows[] = [
                    'date' => $date,
                    'user_name' => (string) (($log['user'] ?? '') ?: ($item['buyer'] ?? '')),
                    'platform' => (string) ($order['platform'] ?? ''),
                    'platform_name' => $platformName,
                    'sys_orderid' => (string) ($order['platform_order_id'] ?? ''),
                    'real_orderid' => (string) ($order['platform_order_id'] ?? ''),
                    'orderid' => $trackingNo,
                    'message' => $message,
                    'related_url' => $this->legacyRelatedUrl($item),
                    'status_code' => $ok ? 1 : 0,
                    'status_label' => $ok ? '成功' : '失败',
                    'order_no' => (string) ($order['platform_order_id'] ?? ''),
                    'item' => (string) ($item['title'] ?? ''),
                    'tracking_no' => $trackingNo,
                    'carrier' => $item['ship_company'] ?? '',
                    'status' => $status,
                    'updated_at' => $date,
                ];
            }
        }

        usort($rows, static fn (array $left, array $right): int => strcmp((string) ($right['date'] ?? ''), (string) ($left['date'] ?? '')));

        return $rows;
    }

    /** @param array<string, mixed> $item */
    private function isJapanLogisticsItem(array $item): bool
    {
        if (($item['source_type'] ?? '') === 'jp_stock') {
            return true;
        }

        if (trim((string) ($item['intl_number'] ?? '')) !== '') {
            return true;
        }

        return in_array((string) ($item['purchase_status'] ?? ''), ['已发货代订单', '已发日本', '已发出荷通知', '日本仓库已处理'], true);
    }

    /** @param array<string, mixed> $item */
    private function logisticsTrackingNo(array $item, string $type): string
    {
        return match ($type) {
            '1688' => (string) ($item['tabaono'] ?? ''),
            'express' => (string) ($item['ship_number'] ?? ''),
            default => (string) (($item['intl_number'] ?? '') ?: ($item['ship_number'] ?? '')),
        };
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function latestLogisticsLog(array $item, string $type): array
    {
        foreach (array_reverse((array) ($item['logs'] ?? [])) as $log) {
            if (!is_array($log) || !$this->logMatchesLogisticsType($log, $type)) {
                continue;
            }

            return $log;
        }

        return [];
    }

    /** @param array<string, mixed> $log */
    private function logMatchesLogisticsType(array $log, string $type): bool
    {
        $text = implode(' ', [
            $log['action'] ?? '',
            $log['field'] ?? '',
            $log['old'] ?? '',
            $log['new'] ?? '',
        ]);

        return match ($type) {
            '1688' => str_contains($text, '1688') || str_contains($text, 'logistics') || str_contains($text, '物流'),
            'express' => str_contains($text, 'ShowAPI') || str_contains($text, '国内物流') || str_contains($text, 'TB/PDD') || str_contains($text, 'logistics'),
            default => str_contains($text, '日本物流') || str_contains($text, '国际运单') || str_contains($text, 'logistics'),
        };
    }

    /** @param array<string, mixed> $log @param array<string, mixed> $item @param array<string, mixed> $order */
    private function legacyLogisticsDate(array $log, array $item, array $order): string
    {
        $value = trim((string) ($log['time'] ?? ''));
        if (preg_match('/^\d{2}-\d{2}\s+\d{2}:\d{2}$/', $value) === 1) {
            return date('Y') . '-' . $value;
        }

        return (string) ($value ?: (($item['purchase_time'] ?? '') ?: ($order['imported_at'] ?? $order['order_date'] ?? '')));
    }

    /** @param array<string, mixed> $log */
    private function legacyLogisticsMessage(array $log, string $status, string $trackingNo): string
    {
        $action = trim((string) ($log['action'] ?? ''));
        if ($action !== '') {
            $old = trim((string) ($log['old'] ?? ''));
            $new = trim((string) ($log['new'] ?? ''));
            if ($old !== '' || $new !== '') {
                return $action . '：' . ($old !== '' ? $old : '-') . ' → ' . ($new !== '' ? $new : '-');
            }

            return $action;
        }

        if ($trackingNo === '') {
            return '单号为空，跳过';
        }

        if ($status !== '') {
            return '当前状态：' . $status;
        }

        return '待查询';
    }

    /** @param array<string, mixed> $log */
    private function isSuccessfulLogisticsLog(array $log): bool
    {
        $new = trim((string) ($log['new'] ?? ''));
        $action = trim((string) ($log['action'] ?? ''));

        return $new !== '' || $action !== '';
    }

    private function legacyPlatformName(string $type, string $platform): string
    {
        $maps = [
            '1688' => ['w' => 'Wowma', 'm' => 'Mercari', 'r' => 'Rakuten', 'y' => 'Yahoo'],
            'jp' => ['w' => 'Wowma', 'm' => 'Mercari', 'r' => '乐天', 'y' => '雅虎'],
        ];

        return $maps[$type][$platform] ?? $platform;
    }

    /** @param array<string, mixed> $item */
    private function legacyRelatedUrl(array $item): string
    {
        if (preg_match('/https?:\/\/\S+/u', (string) ($item['logistic_trace'] ?? ''), $matches) === 1) {
            return rtrim($matches[0], " \t\r\n。；;，,");
        }

        return '';
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
            ['key' => 'purchase_export', 'name' => '采购表导出', 'source' => 'old/*/caigou_export.php', 'status' => '已接 XLSX 图片导出', 'scope' => '国内采购子商品、采购链接、1688 单号', 'direction' => 'export'],
            ['key' => 'purchase_import', 'name' => '采购表导入', 'source' => 'old/*/caigou_import.php', 'status' => '已接 XLSX/CSV 更新', 'scope' => '采购状态、采购金额、采购人、采购时间', 'direction' => 'import'],
            ['key' => 'shipping_import', 'name' => '国际运单导入', 'source' => 'old/*/shipping_import.php', 'status' => '已接 CSV 更新', 'scope' => '国际运单号、运费、重量、件数', 'direction' => 'import'],
            ['key' => 'shipment_export', 'name' => '发货表导出', 'source' => 'old/*/outexcel.php', 'status' => '已接 CSV 导出', 'scope' => '平台发货通知 / 已发日本', 'direction' => 'export'],
            ['key' => 'finance_export', 'name' => '财务表导出', 'source' => 'old/*/outcwexcel.php', 'status' => '已接 XLSX 图片导出', 'scope' => '销售额、采购额、运费、扣点、利润', 'direction' => 'export'],
            ['key' => 'customers_export', 'name' => '客户资料导出', 'source' => 'old/*/custinfo_export.php', 'status' => '已接 XLSX 样式导出', 'scope' => '按平台 / 店铺 / 日期', 'direction' => 'export'],
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
        return $this->exportDatasetService->exportDataset($tenantKey, $type, $user, $criteria);
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
                ['label' => '上传附件', 'value' => '0', 'note' => '采购、客服上传凭证后进入当前租户附件区'],
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
                    'scope' => '采购凭证、客服沟通截图、日本仓发货照片',
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
            ['group' => '安全与权限', 'items' => ['登录会话', '管理员', '采购', '客服', '操作权限']],
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
        return $this->orderFilterService->ordersForExport($tenantKey, $user, $criteria);
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
        return $this->orderFilterService->itemIdsForLogisticsUpdate($tenantKey, $user, $type, $criteria, $explicitItemIds, $explicitOrderIds);
    }


    public function logisticsUpdateStatus(string $type): string
    {
        return match ($type) {
            '1688' => '已触发1688物流同步',
            'express' => '已触发TB/PDD物流同步，等待接口回写',
            'jp' => '已触发国际物流同步',
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
}
