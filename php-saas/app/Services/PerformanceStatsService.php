<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class PerformanceStatsService
{
    /** @var array<int, string> */
    private const CANCELLED_STATUSES = ['已取消', '客人取消订单', '刷单订单已发出荷'];

    /** @var array<string, array<string, mixed>> */
    private const PRODUCT_PLATFORM_CONFIG = [
        'w' => ['product_url' => 'https://wowma.jp/item/', 'needs_dpid' => false],
        'y' => ['product_url' => 'https://store.shopping.yahoo.co.jp/', 'needs_dpid' => true],
        'r' => ['product_url' => 'https://item.rakuten.co.jp/', 'needs_dpid' => true],
        'm' => ['product_url' => 'https://jp.mercari.com/shops/product/', 'needs_dpid' => false],
        'q' => ['product_url' => '', 'needs_dpid' => false],
        'yp' => ['product_url' => '', 'needs_dpid' => false],
    ];

    /** @var array<int, string> */
    private const PREMIUM_STATUSES = ['日本库存订单', '库存缺货订单', '日本仓库已发出荷通知', '日本仓库已处理'];

    public function __construct(private readonly StoreInterface $store)
    {
    }

    /**
     * 业绩汇总：按平台和店铺聚合订单数、销售金额。
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function summary(string $tenantKey, ?array $user = null, array $filters = []): array
    {
        [$startDate, $endDate] = $this->dateRange($filters);
        $platformNames = $this->tenantPlatformNames($tenantKey);
        $rowsByKey = [];
        $seenOrders = [];

        foreach ($this->ordersForUser($tenantKey, $user) as $order) {
            if (!$this->orderInScope($order, $platformNames, $startDate, $endDate)) {
                continue;
            }

            $identity = $this->orderIdentity($order);
            if (isset($seenOrders[$identity])) {
                continue;
            }
            $seenOrders[$identity] = true;

            $platform = (string) ($order['platform'] ?? '');
            $store = (string) (($order['store'] ?? '') ?: '未命名店铺');
            $key = $platform . '|' . $store;
            if (!isset($rowsByKey[$key])) {
                $rowsByKey[$key] = [
                    'platform_code' => $platform,
                    'platform' => $platformNames[$platform] ?? $platform,
                    'store' => $store,
                    'order_count' => 0,
                    'total_amount' => 0.0,
                ];
            }

            $rowsByKey[$key]['order_count']++;
            $rowsByKey[$key]['total_amount'] += $this->orderAmount($order);
        }

        $rows = array_values($rowsByKey);
        usort($rows, static fn (array $a, array $b): int => [$a['platform'], $a['store']] <=> [$b['platform'], $b['store']]);

        $platforms = [];
        foreach ($platformNames as $code => $name) {
            $platforms[$code] = [
                'code' => $code,
                'name' => $name,
                'order_count' => 0,
                'total_amount' => 0.0,
                'stores' => [],
            ];
        }

        foreach ($rows as $row) {
            $code = (string) $row['platform_code'];
            if (!isset($platforms[$code])) {
                $platforms[$code] = [
                    'code' => $code,
                    'name' => (string) $row['platform'],
                    'order_count' => 0,
                    'total_amount' => 0.0,
                    'stores' => [],
                ];
            }
            $platforms[$code]['order_count'] += (int) $row['order_count'];
            $platforms[$code]['total_amount'] += (float) $row['total_amount'];
            $platforms[$code]['stores'][] = $row;
        }

        $platforms = array_values(array_filter(
            $platforms,
            static fn (array $platform): bool => (int) $platform['order_count'] > 0
        ));

        return [
            'filters' => ['start_date' => $startDate, 'end_date' => $endDate],
            'rows' => $rows,
            'platforms' => $platforms,
            'totals' => [
                'order_count' => array_sum(array_column($rows, 'order_count')),
                'total_amount' => array_sum(array_column($rows, 'total_amount')),
                'platform_count' => count($platforms),
                'store_count' => count(array_unique(array_column($rows, 'store'))),
            ],
            'chart' => [
                'labels' => array_map(static fn (array $row): string => (string) $row['name'], $platforms),
                'orders' => array_map(static fn (array $row): int => (int) $row['order_count'], $platforms),
                'amounts' => array_map(static fn (array $row): float => round((float) $row['total_amount'], 2), $platforms),
            ],
        ];
    }

    /**
     * 业绩面板：给页面和后续 AJAX action 复用的结构化数据。
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function dashboard(string $tenantKey, ?array $user = null, array $filters = []): array
    {
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $monthStart = date('Y-m-01');
        [$startDate, $endDate] = $this->dateRange($filters, date('Y-m-d', strtotime('-13 days')), $today);

        return [
            'today' => $this->periodTotals($tenantKey, $user, $today, $today),
            'yesterday' => $this->periodTotals($tenantKey, $user, $yesterday, $yesterday),
            'month' => $this->periodTotals($tenantKey, $user, $monthStart, $today),
            'range' => $this->periodTotals($tenantKey, $user, $startDate, $endDate),
            'daily' => $this->dailyBreakdown($tenantKey, $user, [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'platform' => (string) ($filters['platform'] ?? ''),
            ]),
            'platforms' => $this->summary($tenantKey, $user, [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ])['platforms'],
            'filters' => ['start_date' => $startDate, 'end_date' => $endDate, 'platform' => (string) ($filters['platform'] ?? '')],
        ];
    }

    /**
     * 日统计数据：旧 performance/index.php 的后端聚合口径。
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function dailyBreakdown(string $tenantKey, ?array $user = null, array $filters = []): array
    {
        [$startDate, $endDate] = $this->dateRange($filters);
        $selectedPlatform = trim((string) ($filters['platform'] ?? ''));
        $platformNames = $this->tenantPlatformNames($tenantKey);
        $rowsByKey = [];
        $seenOrders = [];

        foreach ($this->ordersForUser($tenantKey, $user) as $order) {
            if ($selectedPlatform !== '' && (string) ($order['platform'] ?? '') !== $selectedPlatform) {
                continue;
            }
            if (!$this->orderInScope($order, $platformNames, $startDate, $endDate)) {
                continue;
            }

            $identity = $this->orderIdentity($order);
            if (isset($seenOrders[$identity])) {
                continue;
            }
            $seenOrders[$identity] = true;

            $date = $this->orderDate($order);
            $platform = (string) ($order['platform'] ?? '');
            $store = (string) (($order['store'] ?? '') ?: '未命名店铺');
            $key = $date . '|' . $platform . '|' . $store;
            if (!isset($rowsByKey[$key])) {
                $rowsByKey[$key] = [
                    'date' => $date,
                    'platform_code' => $platform,
                    'platform' => $platformNames[$platform] ?? $platform,
                    'store' => $store,
                    'order_count' => 0,
                    'total_amount' => 0.0,
                ];
            }
            $rowsByKey[$key]['order_count']++;
            $rowsByKey[$key]['total_amount'] += $this->orderAmount($order);
        }

        $rows = array_values($rowsByKey);
        usort($rows, static function (array $a, array $b): int {
            return ((string) $b['date'] <=> (string) $a['date'])
                ?: ((string) $a['platform'] <=> (string) $b['platform'])
                ?: ((string) $a['store'] <=> (string) $b['store']);
        });

        $dailyTotals = [];
        foreach ($rows as $row) {
            $date = (string) $row['date'];
            $dailyTotals[$date]['date'] = $date;
            $dailyTotals[$date]['order_count'] = ($dailyTotals[$date]['order_count'] ?? 0) + (int) $row['order_count'];
            $dailyTotals[$date]['total_amount'] = ($dailyTotals[$date]['total_amount'] ?? 0.0) + (float) $row['total_amount'];
        }
        ksort($dailyTotals);

        $totalOrders = array_sum(array_column($rows, 'order_count'));
        $totalAmount = array_sum(array_column($rows, 'total_amount'));
        $days = count($dailyTotals);

        return [
            'success' => true,
            'filters' => ['start_date' => $startDate, 'end_date' => $endDate, 'platform' => $selectedPlatform],
            'rows' => $rows,
            'summary' => [
                'total_orders' => $totalOrders,
                'total_amount' => $totalAmount,
                'avg_daily_orders' => $days > 0 ? round($totalOrders / $days, 2) : 0,
            ],
            'chart' => [
                'labels' => array_keys($dailyTotals),
                'orders' => array_map(static fn (array $row): int => (int) $row['order_count'], array_values($dailyTotals)),
                'amounts' => array_map(static fn (array $row): float => round((float) $row['total_amount'], 2), array_values($dailyTotals)),
            ],
            'platforms' => $platformNames,
        ];
    }

    /**
     * 出单商品分析：按商品 code 聚合热卖排名。
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function productAnalysis(string $tenantKey, ?array $user = null, array $filters = []): array
    {
        [$startDate, $endDate] = $this->dateRange($filters);
        $platformNames = $this->tenantPlatformNames($tenantKey);
        $selectedPlatform = trim((string) ($filters['platform'] ?? ''));
        if ($selectedPlatform === '' && $platformNames !== []) {
            $selectedPlatform = (string) array_key_first($platformNames);
        }
        $productType = (string) ($filters['product_type'] ?? 'all');
        $productType = in_array($productType, ['all', 'premium', 'distribution'], true) ? $productType : 'all';
        $page = $this->boundedInt($filters['page'] ?? 1, 1, 100000);
        $perPage = $this->boundedInt($filters['per_page'] ?? 100, 20, 500);
        $stores = $this->storesByName($tenantKey);
        $groups = [];

        foreach ($this->ordersForUser($tenantKey, $user) as $order) {
            $platform = (string) ($order['platform'] ?? '');
            if ($selectedPlatform !== '' && $platform !== $selectedPlatform) {
                continue;
            }
            if (!$this->orderInScope($order, $platformNames, $startDate, $endDate)) {
                continue;
            }

            foreach ($this->items($order) as $item) {
                if (!$this->matchesProductType($order, $item, $productType)) {
                    continue;
                }
                $code = $this->productCode($item);
                if ($code === '') {
                    continue;
                }

                $key = $platform . '|' . $code;
                $quantity = $this->itemQuantity($item);
                $amount = $this->itemSales($item);
                $store = (string) (($order['store'] ?? '') ?: '未命名店铺');
                if (!isset($groups[$key])) {
                    $config = self::PRODUCT_PLATFORM_CONFIG[$platform] ?? ['product_url' => '', 'needs_dpid' => false];
                    $groups[$key] = [
                        'product_code' => $code,
                        'platform' => $platform,
                        'platform_name' => $platformNames[$platform] ?? $platform,
                        'order_count' => 0,
                        'total_amount' => 0.0,
                        'avg_price' => 0.0,
                        'shop_count' => 0,
                        'shops' => [],
                        'image' => $this->itemImage($item),
                        'product_url' => (string) ($config['product_url'] ?? ''),
                        'needs_dpid' => (bool) ($config['needs_dpid'] ?? false),
                        'dpid' => $this->storeDpid($store, $stores),
                        'subcodes' => [],
                    ];
                }

                $groups[$key]['order_count'] += $quantity;
                $groups[$key]['total_amount'] += $amount;
                $groups[$key]['shops'][$store] = true;
                if ((string) $groups[$key]['image'] === '') {
                    $groups[$key]['image'] = $this->itemImage($item);
                }
                if ((string) $groups[$key]['dpid'] === '') {
                    $groups[$key]['dpid'] = $this->storeDpid($store, $stores);
                }
                $subcode = $this->itemSubcode($item);
                if ($subcode !== '') {
                    $groups[$key]['subcodes'][$subcode] = ($groups[$key]['subcodes'][$subcode] ?? 0) + $quantity;
                }
            }
        }

        foreach ($groups as &$group) {
            $group['avg_price'] = (int) $group['order_count'] > 0
                ? round((float) $group['total_amount'] / (int) $group['order_count'], 2)
                : 0.0;
            $group['shop_count'] = count($group['shops']);
            $group['shops'] = implode(', ', array_keys($group['shops']));
            arsort($group['subcodes']);
        }
        unset($group);

        $rows = array_values($groups);
        usort($rows, static fn (array $a, array $b): int => [(int) $b['order_count'], (float) $b['total_amount']] <=> [(int) $a['order_count'], (float) $a['total_amount']]);

        $totalCount = count($rows);
        $totalPages = max(1, (int) ceil($totalCount / $perPage));
        $page = min($page, $totalPages);
        $pageRows = array_slice($rows, ($page - 1) * $perPage, $perPage);
        foreach ($pageRows as $index => &$row) {
            $row['rank'] = ($page - 1) * $perPage + $index + 1;
        }
        unset($row);

        return [
            'success' => true,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'platform' => $selectedPlatform,
                'product_type' => $productType,
            ],
            'rows' => $pageRows,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_count' => $totalCount,
                'total_pages' => $totalPages,
            ],
            'summary' => [
                'total_products' => $totalCount,
                'total_orders' => array_sum(array_column($rows, 'order_count')),
                'total_amount' => array_sum(array_column($rows, 'total_amount')),
                'avg_orders_per_product' => $totalCount > 0 ? round(array_sum(array_column($rows, 'order_count')) / $totalCount, 2) : 0,
            ],
            'platforms' => $platformNames,
            'chart' => [
                'labels' => array_map(static fn (array $row): string => (string) $row['product_code'], array_slice($rows, 0, 20)),
                'quantities' => array_map(static fn (array $row): int => (int) $row['order_count'], array_slice($rows, 0, 20)),
                'amounts' => array_map(static fn (array $row): float => round((float) $row['total_amount'], 2), array_slice($rows, 0, 20)),
            ],
        ];
    }

    /** @param array<string, mixed> $filters */
    private function dateRange(array $filters, ?string $defaultStart = null, ?string $defaultEnd = null): array
    {
        $start = $this->dateOnly((string) ($filters['start_date'] ?? $filters['date_from'] ?? ($defaultStart ?? date('Y-m-d'))));
        $end = $this->dateOnly((string) ($filters['end_date'] ?? $filters['date_to'] ?? ($defaultEnd ?? date('Y-m-d'))));
        if ($start === '') {
            $start = $defaultStart ?? date('Y-m-d');
        }
        if ($end === '') {
            $end = $defaultEnd ?? $start;
        }
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        return [$start, $end];
    }

    /** @return array<string, int|float> */
    private function periodTotals(string $tenantKey, ?array $user, string $startDate, string $endDate): array
    {
        $summary = $this->summary($tenantKey, $user, ['start_date' => $startDate, 'end_date' => $endDate]);

        return [
            'order_count' => (int) ($summary['totals']['order_count'] ?? 0),
            'total_amount' => (float) ($summary['totals']['total_amount'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    private function ordersForUser(string $tenantKey, ?array $user): array
    {
        $orders = ($user === null || ($user['role'] ?? '') === '公司管理员' || ($user['is_company_admin'] ?? false))
            ? $this->store->orders($tenantKey)
            : $this->store->ordersForStores($tenantKey, (array) ($user['stores'] ?? []));

        $allowed = array_flip(array_keys($this->tenantPlatformNames($tenantKey)));
        if (!$allowed) {
            return [];
        }

        return array_values(array_filter(
            $orders,
            static fn (array $order): bool => isset($allowed[(string) ($order['platform'] ?? '')])
        ));
    }

    /** @return array<string, string> */
    private function tenantPlatformNames(string $tenantKey): array
    {
        $tenant = $this->store->tenant($tenantKey);
        if (($tenant['status'] ?? '') === 'suspended') {
            return [];
        }

        $allowed = [];
        foreach ((array) ($tenant['platforms'] ?? []) as $item) {
            if (($item['enabled'] ?? false) && !($item['locked'] ?? false)) {
                $allowed[(string) ($item['code'] ?? '')] = true;
            }
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

    /**
     * @param array<string, mixed> $order
     * @param array<string, string> $platformNames
     */
    private function orderInScope(array $order, array $platformNames, string $startDate, string $endDate): bool
    {
        $platform = (string) ($order['platform'] ?? '');
        if (!isset($platformNames[$platform])) {
            return false;
        }
        if ($this->isCancelledOrder($order)) {
            return false;
        }

        $date = $this->orderDate($order);
        return $date !== '' && $date >= $startDate && $date <= $endDate;
    }

    /** @param array<string, mixed> $order */
    private function isCancelledOrder(array $order): bool
    {
        $status = trim((string) ($order['status'] ?? ''));
        if (in_array($status, self::CANCELLED_STATUSES, true)) {
            return true;
        }

        foreach ($this->items($order) as $item) {
            if (in_array(trim((string) ($item['purchase_status'] ?? '')), self::CANCELLED_STATUSES, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $order */
    private function orderIdentity(array $order): string
    {
        $platform = (string) ($order['platform'] ?? '');
        $orderNo = trim((string) ($order['platform_order_id'] ?? ''));
        if ($orderNo !== '') {
            return $platform . '|' . $orderNo;
        }

        if (isset($order['id'])) {
            return $platform . '|id:' . (string) $order['id'];
        }

        return $platform . '|hash:' . sha1((string) json_encode($order, JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $order */
    private function orderDate(array $order): string
    {
        return $this->dateOnly((string) (($order['order_date'] ?? '') ?: ($order['imported_at'] ?? '')));
    }

    /** @param array<string, mixed> $order */
    private function orderAmount(array $order): float
    {
        foreach (['total', 'total_item_price', 'request_price', 'amount'] as $field) {
            $amount = $this->moneyFloat($order[$field] ?? 0);
            if ($amount > 0) {
                return $amount;
            }
        }

        return array_sum(array_map(fn (array $item): float => $this->itemSales($item), $this->items($order)));
    }

    /**
     * @param array<string, mixed> $order
     * @return array<int, array<string, mixed>>
     */
    private function items(array $order): array
    {
        return array_values(array_filter((array) ($order['items'] ?? []), 'is_array'));
    }

    /** @param array<string, mixed> $item */
    private function itemSales(array $item): float
    {
        foreach (['line_total', 'total_item_price', 'totalItemPrice'] as $field) {
            $amount = $this->moneyFloat($item[$field] ?? 0);
            if ($amount > 0) {
                return $amount;
            }
        }

        return $this->moneyFloat($item['unit_price'] ?? 0) * $this->itemQuantity($item);
    }

    /** @param array<string, mixed> $item */
    private function itemQuantity(array $item): int
    {
        return max(1, (int) ($item['quantity'] ?? $item['unit'] ?? 1));
    }

    /** @param array<string, mixed> $item */
    private function productCode(array $item): string
    {
        foreach (['item_code', 'lot_number', 'lotnumber', 'item_management_id', 'ItemId'] as $field) {
            $value = trim((string) ($item[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     */
    private function matchesProductType(array $order, array $item, string $productType): bool
    {
        if ($productType === 'all') {
            return true;
        }

        $status = (string) (($item['purchase_status'] ?? '') ?: ($order['status'] ?? ''));
        $isPremium = ($item['source_type'] ?? '') === 'jp_stock' || in_array($status, self::PREMIUM_STATUSES, true);

        return $productType === 'premium' ? $isPremium : !$isPremium;
    }

    /** @param array<string, mixed> $item */
    private function itemImage(array $item): string
    {
        foreach (['image', 'zhutu', 'sku_image'] as $field) {
            $value = trim((string) ($item[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** @param array<string, mixed> $item */
    private function itemSubcode(array $item): string
    {
        foreach (['subcode_option', 'SubCodeOption', 'subcode', 'option', 'chinese_option'] as $field) {
            $value = trim((string) ($item[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function storesByName(string $tenantKey): array
    {
        $stores = [];
        foreach ($this->store->stores($tenantKey) as $store) {
            $name = (string) ($store['name'] ?? '');
            if ($name !== '') {
                $stores[$name] = $store;
            }
            $short = (string) ($store['short'] ?? '');
            if ($short !== '') {
                $stores[$short] = $store;
            }
        }

        return $stores;
    }

    /** @param array<string, array<string, mixed>> $stores */
    private function storeDpid(string $storeName, array $stores): string
    {
        $store = $stores[$storeName] ?? null;
        return is_array($store) ? (string) ($store['legacy_dpid'] ?? '') : '';
    }

    private function dateOnly(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $match)) {
            return "{$match[1]}-{$match[2]}-{$match[3]}";
        }
        if (preg_match('/(\d{4})年(\d{1,2})月(\d{1,2})日/u', $value, $match)) {
            return sprintf('%04d-%02d-%02d', (int) $match[1], (int) $match[2], (int) $match[3]);
        }

        return '';
    }

    private function moneyFloat(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $normalized = preg_replace('/[^\d.\-]+/', '', str_replace(',', '', (string) ($value ?? '')));
        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function boundedInt(mixed $value, int $min, int $max): int
    {
        $number = is_numeric($value) ? (int) $value : $min;
        return max($min, min($max, $number));
    }
}
