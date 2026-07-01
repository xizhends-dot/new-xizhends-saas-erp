<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class PurchaseStatsService
{
    /** @var array<string, array<int, string>> */
    private const STATUS_ORDER = [
        'w' => [
            '未处理的订单',
            '日本库存订单',
            '库存缺货订单',
            '日本仓库已发出荷通知',
            '国内采购-准备',
            '国内采购--问题',
            '国内采购-已采购',
            '国内采购-TB/PDD已采购',
            '发货中',
            '已到货',
            '已发货代订单',
            '已发日本',
            '已发出荷通知',
            '已到货问题件',
            '问题订单(后台处理)',
            '已取消',
        ],
        'y' => [
            '未处理的订单',
            '日本库存订单',
            '库存缺货订单',
            '日本仓库已发出荷通知',
            '刷单订单',
            '刷单订单已发出荷',
            '国内采购-准备',
            '国内采购--问题',
            '国内采购-已采购',
            '国内采购-TB/PDD已采购',
            '发货中',
            '已到货',
            '已发货代订单',
            '已发日本',
            '已发出荷通知',
            '已到货问题件',
            '问题订单(后台处理)',
            '已取消',
            '日本仓库已处理',
        ],
        'r' => [
            '未处理的订单',
            '日本库存订单',
            '库存缺货订单',
            '日本仓库已处理',
            '国内采购-准备',
            '国内采购--问题',
            '国内采购-已采购',
            '国内采购-TB/PDD已采购',
            '发货中',
            '已到货',
            '已发货代订单',
            '已发日本',
            '已发出荷通知',
            '已到货问题件',
            '问题订单(后台处理)',
            '已取消',
        ],
        'm' => [
            '未处理的订单',
            '日本库存订单',
            '库存缺货订单',
            '日本仓库已发出荷通知',
            '国内采购-准备',
            '国内采购--问题',
            '国内采购-已采购',
            '国内采购-TB/PDD已采购',
            '发货中',
            '已到货',
            '已发货代订单',
            '已发日本',
            '已发出荷通知',
            '已到货问题件',
            '问题订单(后台处理)',
            '已取消',
        ],
        'q' => [
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
            '已取消',
        ],
        'yp' => [
            '未处理的订单',
            '国内采购-准备',
            '国内采购--问题',
            '国内采购-已采购',
            '发货中',
            '已到货',
            '已发货代订单',
            '已发日本',
            '已发出荷通知',
            '已取消',
        ],
    ];

    public function __construct(private readonly StoreInterface $store)
    {
    }

    /**
     * 采购状态每日统计：按平台、状态聚合，并给出前一日差值和图表数据。
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function dailyStatus(string $tenantKey, ?array $user = null, array $filters = []): array
    {
        $date = $this->dateOnly((string) ($filters['date'] ?? date('Y-m-d')));
        if ($date === '') {
            $date = date('Y-m-d');
        }
        $platformFilter = trim((string) ($filters['platform'] ?? ''));
        $compare = !array_key_exists('compare', $filters) || (bool) $filters['compare'];
        $previousDate = date('Y-m-d', strtotime($date . ' -1 day'));
        $platformNames = $this->tenantPlatformNames($tenantKey);
        $current = $this->statusSnapshot($tenantKey, $user, $date, $platformFilter);
        $previous = $compare ? $this->statusSnapshot($tenantKey, $user, $previousDate, $platformFilter) : ['platforms' => [], 'total' => 0];

        $platforms = [];
        foreach ($platformNames as $code => $name) {
            if ($platformFilter !== '' && $code !== $platformFilter) {
                continue;
            }

            $statuses = $this->orderedStatuses($code, (array) (($current['platforms'][$code]['statuses'] ?? [])));
            $prevStatuses = (array) (($previous['platforms'][$code]['statuses'] ?? []));
            $statusRows = [];
            foreach ($statuses as $status => $count) {
                $prevCount = (int) ($prevStatuses[$status] ?? 0);
                $statusRows[] = [
                    'status' => $status,
                    'count' => (int) $count,
                    'previous_count' => $prevCount,
                    'diff' => (int) $count - $prevCount,
                    'percent' => (int) ($current['platforms'][$code]['total'] ?? 0) > 0
                        ? round((int) $count / (int) $current['platforms'][$code]['total'] * 100, 1)
                        : 0,
                    'is_defined' => in_array($status, self::STATUS_ORDER[$code] ?? [], true),
                ];
            }

            $total = (int) ($current['platforms'][$code]['total'] ?? 0);
            $previousTotal = (int) ($previous['platforms'][$code]['total'] ?? 0);
            $platforms[$code] = [
                'code' => $code,
                'name' => $name,
                'total' => $total,
                'previous_total' => $previousTotal,
                'diff' => $total - $previousTotal,
                'statuses' => $statusRows,
            ];
        }

        $platforms = array_values(array_filter(
            $platforms,
            static fn (array $platform): bool => (int) $platform['total'] > 0 || (int) $platform['previous_total'] > 0
        ));

        $total = (int) ($current['total'] ?? 0);
        $previousTotal = (int) ($previous['total'] ?? 0);

        return [
            'filters' => ['date' => $date, 'platform' => $platformFilter, 'compare' => $compare],
            'previous_date' => $previousDate,
            'total' => $total,
            'previous_total' => $previousTotal,
            'diff' => $total - $previousTotal,
            'platforms' => $platforms,
            'platformOptions' => $platformNames,
            'chart' => [
                'labels' => array_map(static fn (array $platform): string => (string) $platform['name'], $platforms),
                'totals' => array_map(static fn (array $platform): int => (int) $platform['total'], $platforms),
                'diffs' => array_map(static fn (array $platform): int => (int) $platform['diff'], $platforms),
                'status_labels' => $this->topStatusLabels($platforms),
            ],
        ];
    }

    /**
     * 采购统计补全：日期维度、采购人维度、状态维度和追溯明细。
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function purchaseStats(string $tenantKey, ?array $user = null, array $filters = []): array
    {
        [$startDate, $endDate] = $this->dateRange($filters, date('Y-m-d', strtotime('-13 days')), date('Y-m-d'));
        $platformFilter = trim((string) ($filters['platform'] ?? ''));
        $buyerFilter = trim((string) ($filters['buyer'] ?? ''));
        $statusFilter = trim((string) ($filters['status'] ?? ''));
        $platformNames = $this->tenantPlatformNames($tenantKey);
        $buyers = [];
        $statuses = [];
        $daily = [];
        $platforms = [];
        $traceRows = [];

        foreach ($this->ordersForUser($tenantKey, $user) as $order) {
            $platform = (string) ($order['platform'] ?? '');
            if (!isset($platformNames[$platform])) {
                continue;
            }
            if ($platformFilter !== '' && $platform !== $platformFilter) {
                continue;
            }

            foreach ($this->items($order) as $item) {
                if (!$this->isPurchaseItem($item)) {
                    continue;
                }

                $date = $this->purchaseDate($order, $item);
                if ($date === '' || $date < $startDate || $date > $endDate) {
                    continue;
                }
                $buyer = (string) (($item['buyer'] ?? '') ?: '未分配');
                $status = (string) (($item['purchase_status'] ?? '') ?: '待处理');
                if ($buyerFilter !== '' && $buyer !== $buyerFilter) {
                    continue;
                }
                if ($statusFilter !== '' && $status !== $statusFilter) {
                    continue;
                }

                $quantity = $this->itemQuantity($item);
                $amount = $this->purchaseAmount($item);
                $buyers[$buyer]['buyer'] = $buyer;
                $buyers[$buyer]['item_count'] = ($buyers[$buyer]['item_count'] ?? 0) + 1;
                $buyers[$buyer]['quantity'] = ($buyers[$buyer]['quantity'] ?? 0) + $quantity;
                $buyers[$buyer]['amount'] = ($buyers[$buyer]['amount'] ?? 0.0) + $amount;

                $statuses[$status] = ($statuses[$status] ?? 0) + 1;
                $daily[$date]['date'] = $date;
                $daily[$date]['item_count'] = ($daily[$date]['item_count'] ?? 0) + 1;
                $daily[$date]['quantity'] = ($daily[$date]['quantity'] ?? 0) + $quantity;
                $daily[$date]['amount'] = ($daily[$date]['amount'] ?? 0.0) + $amount;
                $platforms[$platform]['code'] = $platform;
                $platforms[$platform]['name'] = $platformNames[$platform] ?? $platform;
                $platforms[$platform]['item_count'] = ($platforms[$platform]['item_count'] ?? 0) + 1;
                $platforms[$platform]['quantity'] = ($platforms[$platform]['quantity'] ?? 0) + $quantity;
                $platforms[$platform]['amount'] = ($platforms[$platform]['amount'] ?? 0.0) + $amount;

                $traceRows[] = [
                    'date' => $date,
                    'platform_code' => $platform,
                    'platform' => $platformNames[$platform] ?? $platform,
                    'store' => (string) (($order['store'] ?? '') ?: '未命名店铺'),
                    'order_no' => (string) ($order['platform_order_id'] ?? ''),
                    'item_code' => $this->itemCode($item),
                    'title' => (string) ($item['title'] ?? ''),
                    'quantity' => $quantity,
                    'buyer' => $buyer,
                    'status' => $status,
                    'amount' => $amount,
                    'purchase_time' => (string) ($item['purchase_time'] ?? ''),
                    'tabaono' => (string) ($item['tabaono'] ?? ''),
                ];
            }
        }

        uasort($buyers, static fn (array $a, array $b): int => [(int) $b['item_count'], (float) $b['amount']] <=> [(int) $a['item_count'], (float) $a['amount']]);
        arsort($statuses);
        ksort($daily);
        uasort($platforms, static fn (array $a, array $b): int => (int) $b['item_count'] <=> (int) $a['item_count']);
        usort($traceRows, static fn (array $a, array $b): int => [$b['date'], $a['buyer'], $a['order_no']] <=> [$a['date'], $b['buyer'], $b['order_no']]);

        $total = count($traceRows);

        return [
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'platform' => $platformFilter,
                'buyer' => $buyerFilter,
                'status' => $statusFilter,
            ],
            'total' => $total,
            'buyers' => array_values($buyers),
            'statuses' => $statuses,
            'daily' => array_values($daily),
            'platforms' => array_values($platforms),
            'trace_rows' => array_slice($traceRows, 0, 500),
            'platformOptions' => $platformNames,
            'buyerOptions' => array_keys($buyers),
            'statusOptions' => array_keys($statuses),
            'totals' => [
                'item_count' => $total,
                'quantity' => array_sum(array_column($traceRows, 'quantity')),
                'amount' => array_sum(array_column($traceRows, 'amount')),
                'buyer_count' => count($buyers),
                'status_count' => count($statuses),
            ],
            'chart' => [
                'labels' => array_map(static fn (array $row): string => (string) $row['date'], array_values($daily)),
                'items' => array_map(static fn (array $row): int => (int) $row['item_count'], array_values($daily)),
                'amounts' => array_map(static fn (array $row): float => round((float) $row['amount'], 2), array_values($daily)),
            ],
        ];
    }

    /**
     * @return array{platforms: array<string, array{statuses: array<string, int>, total: int}>, total: int}
     */
    private function statusSnapshot(string $tenantKey, ?array $user, string $date, string $platformFilter = ''): array
    {
        $platformNames = $this->tenantPlatformNames($tenantKey);
        $snapshot = ['platforms' => [], 'total' => 0];
        foreach ($this->ordersForUser($tenantKey, $user) as $order) {
            $platform = (string) ($order['platform'] ?? '');
            if (!isset($platformNames[$platform])) {
                continue;
            }
            if ($platformFilter !== '' && $platform !== $platformFilter) {
                continue;
            }

            foreach ($this->items($order) as $item) {
                if (!$this->isStatusTrackedItem($item)) {
                    continue;
                }
                if ($this->purchaseDate($order, $item) !== $date) {
                    continue;
                }

                $status = (string) (($item['purchase_status'] ?? '') ?: ($order['status'] ?? '') ?: '未设置');
                $snapshot['platforms'][$platform]['statuses'][$status] = ($snapshot['platforms'][$platform]['statuses'][$status] ?? 0) + 1;
                $snapshot['platforms'][$platform]['total'] = ($snapshot['platforms'][$platform]['total'] ?? 0) + 1;
                $snapshot['total']++;
            }
        }

        return $snapshot;
    }

    /**
     * @param array<string, int> $statuses
     * @return array<string, int>
     */
    private function orderedStatuses(string $platform, array $statuses): array
    {
        $ordered = [];
        foreach (self::STATUS_ORDER[$platform] ?? [] as $status) {
            $ordered[$status] = (int) ($statuses[$status] ?? 0);
            unset($statuses[$status]);
        }
        arsort($statuses);
        foreach ($statuses as $status => $count) {
            $ordered[(string) $status] = (int) $count;
        }

        return $ordered;
    }

    /**
     * @param array<int, array<string, mixed>> $platforms
     * @return array<int, string>
     */
    private function topStatusLabels(array $platforms): array
    {
        $counts = [];
        foreach ($platforms as $platform) {
            foreach ((array) ($platform['statuses'] ?? []) as $status) {
                $name = (string) ($status['status'] ?? '');
                if ($name === '') {
                    continue;
                }
                $counts[$name] = ($counts[$name] ?? 0) + (int) ($status['count'] ?? 0);
            }
        }
        arsort($counts);

        return array_slice(array_keys($counts), 0, 12);
    }

    /** @param array<string, mixed> $filters */
    private function dateRange(array $filters, string $defaultStart, string $defaultEnd): array
    {
        $start = $this->dateOnly((string) ($filters['start_date'] ?? $filters['date_from'] ?? $defaultStart));
        $end = $this->dateOnly((string) ($filters['end_date'] ?? $filters['date_to'] ?? $defaultEnd));
        if ($start === '') {
            $start = $defaultStart;
        }
        if ($end === '') {
            $end = $defaultEnd;
        }
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        return [$start, $end];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    private function ordersForUser(string $tenantKey, ?array $user): array
    {
        return ($user === null || ($user['role'] ?? '') === '公司管理员' || ($user['is_company_admin'] ?? false))
            ? $this->store->orders($tenantKey)
            : $this->store->ordersForStores($tenantKey, (array) ($user['stores'] ?? []));
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
     * @return array<int, array<string, mixed>>
     */
    private function items(array $order): array
    {
        return array_values(array_filter((array) ($order['items'] ?? []), 'is_array'));
    }

    /** @param array<string, mixed> $item */
    private function isPurchaseItem(array $item): bool
    {
        return (string) ($item['source_type'] ?? '') === 'cn_purchase'
            || trim((string) ($item['buyer'] ?? '')) !== ''
            || trim((string) ($item['tabaono'] ?? '')) !== '';
    }

    /** @param array<string, mixed> $item */
    private function isStatusTrackedItem(array $item): bool
    {
        return trim((string) ($item['purchase_status'] ?? '')) !== ''
            || $this->isPurchaseItem($item);
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     */
    private function purchaseDate(array $order, array $item): string
    {
        foreach ([$item['purchase_time'] ?? '', $order['order_date'] ?? '', $order['imported_at'] ?? ''] as $value) {
            $date = $this->dateOnly((string) $value);
            if ($date !== '') {
                return $date;
            }
        }

        return '';
    }

    /** @param array<string, mixed> $item */
    private function itemQuantity(array $item): int
    {
        return max(1, (int) ($item['quantity'] ?? $item['unit'] ?? 1));
    }

    /** @param array<string, mixed> $item */
    private function purchaseAmount(array $item): float
    {
        foreach (['amount', 'purchase_amount', 'cn_amount'] as $field) {
            $amount = $this->moneyFloat($item[$field] ?? 0);
            if ($amount > 0) {
                return $amount;
            }
        }

        return 0.0;
    }

    /** @param array<string, mixed> $item */
    private function itemCode(array $item): string
    {
        foreach (['item_code', 'lot_number', 'lotnumber', 'item_management_id', 'ItemId'] as $field) {
            $value = trim((string) ($item[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
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
}
