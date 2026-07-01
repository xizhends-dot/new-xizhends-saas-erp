<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class ShippingAnomalyService
{
    private AppService $app;

    public function __construct(private readonly StoreInterface $store)
    {
        $this->app = new AppService($store);
    }

    /**
     * @param array<string, mixed>|null $user
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function detect(string $tenantKey, ?array $user, array $filters = []): array
    {
        $normalized = $this->normalizeFilters($filters);
        $allGroups = $this->anomalyGroups(
            $this->filteredRows($tenantKey, $user, $normalized)
        );

        $page = (int) $normalized['page'];
        $pageSize = (int) $normalized['page_size'];
        $totalGroups = count($allGroups);
        $totalPages = max(1, (int) ceil($totalGroups / $pageSize));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $pageSize;
        $pageGroups = array_slice($allGroups, $offset, $pageSize);

        return [
            'filters' => array_merge($normalized, ['page' => $page]),
            'platforms' => $this->app->tenantPlatformNames($tenantKey),
            'stores' => $this->storesForUser($tenantKey, $user),
            'groups' => $pageGroups,
            'csv' => $this->csvDataset($tenantKey, $allGroups),
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total_groups' => $totalGroups,
                'total_pages' => $totalPages,
            ],
            'summary' => [
                'total_groups' => $totalGroups,
                'total_rows' => array_sum(array_map(
                    static fn (array $group): int => count($group['rows'] ?? []),
                    $allGroups
                )),
                'total_fee_types' => array_sum(array_map(
                    static fn (array $group): int => count($group['fee_values'] ?? []),
                    $allGroups
                )),
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $user
     * @param array<string, mixed> $filters
     * @return array{name: string, filename: string, headers: array<int, string>, rows: array<int, array<int, mixed>>}
     */
    public function csvRows(string $tenantKey, ?array $user, array $filters = []): array
    {
        $groups = $this->anomalyGroups(
            $this->filteredRows($tenantKey, $user, $this->normalizeFilters($filters))
        );

        return $this->csvDataset($tenantKey, $groups);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $pageSize = (int) ($filters['page_size'] ?? $filters['per_page'] ?? 50);
        if (!in_array($pageSize, [50, 100, 200, 500], true)) {
            $pageSize = 50;
        }

        $dateFrom = $this->dateOnly((string) ($filters['date_from'] ?? $filters['date_start'] ?? ''));
        $dateTo = $this->dateOnly((string) ($filters['date_to'] ?? $filters['date_end'] ?? ''));
        if ($dateFrom === '' && $dateTo === '') {
            $dateFrom = date('Y-m-01');
            $dateTo = date('Y-m-t');
        }

        return [
            'platform' => $this->code((string) ($filters['platform'] ?? '')),
            'store' => trim((string) ($filters['store'] ?? '')),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'item_id' => trim((string) ($filters['item_id'] ?? $filters['search_item_id'] ?? '')),
            'page' => max(1, (int) ($filters['page'] ?? 1)),
            'page_size' => $pageSize,
        ];
    }

    /**
     * @param array<string, mixed>|null $user
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function filteredRows(string $tenantKey, ?array $user, array $filters): array
    {
        $rows = [];
        foreach ($this->app->ordersForUser($tenantKey, $user) as $order) {
            if (!$this->orderMatches($order, $filters)) {
                continue;
            }

            $platform = $this->code((string) ($order['platform'] ?? ''));
            foreach ($order['items'] ?? [] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $itemId = $this->itemIdentity($item, $platform);
                if ($itemId === '') {
                    continue;
                }

                $itemFilter = strtolower((string) $filters['item_id']);
                if ($itemFilter !== '' && !str_contains(strtolower($itemId), $itemFilter)) {
                    continue;
                }

                $fee = $this->shippingFee($item);
                if ($fee['key'] === '') {
                    continue;
                }

                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                $rows[] = [
                    'group_key' => $itemId . "\n" . $quantity,
                    'item_id' => $itemId,
                    'quantity' => $quantity,
                    'fee_key' => $fee['key'],
                    'fee_value' => $fee['value'],
                    'fee_source' => $fee['source'],
                    'order_no' => (string) ($order['platform_order_id'] ?? ''),
                    'order_date' => (string) ($order['order_date'] ?? ''),
                    'platform' => (string) ($order['platform'] ?? ''),
                    'platform_name' => $this->platformName($tenantKey, (string) ($order['platform'] ?? '')),
                    'store' => (string) ($order['store'] ?? ''),
                    'item_option' => (string) ($item['option'] ?? ''),
                    'title' => (string) ($item['title'] ?? ''),
                    'shipnumber' => (string) (($item['intl_number'] ?? '') ?: ($item['ship_number'] ?? '')),
                    'weight' => (string) (($item['intl_weight'] ?? '') ?: ($item['weight'] ?? '')),
                    'image' => (string) (($item['image'] ?? '') ?: ($item['main_image'] ?? '')),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function anomalyGroups(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $key = (string) $row['group_key'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'item_id' => $row['item_id'],
                    'quantity' => $row['quantity'],
                    'fee_values' => [],
                    'fee_types' => 0,
                    'order_count' => 0,
                    'rows' => [],
                ];
            }

            $grouped[$key]['fee_values'][(string) $row['fee_key']] = $row['fee_value'];
            $grouped[$key]['rows'][] = $row;
        }

        $groups = [];
        foreach ($grouped as $group) {
            if (count($group['fee_values']) <= 1) {
                continue;
            }

            $feeValues = array_values($group['fee_values']);
            usort($feeValues, static function (string $left, string $right): int {
                return (float) $left <=> (float) $right ?: strcmp($left, $right);
            });

            usort($group['rows'], static function (array $left, array $right): int {
                return strcmp((string) ($right['order_date'] ?? ''), (string) ($left['order_date'] ?? ''));
            });

            $group['fee_values'] = $feeValues;
            $group['fee_types'] = count($feeValues);
            $group['order_count'] = count($group['rows']);
            $groups[] = $group;
        }

        usort($groups, static function (array $left, array $right): int {
            return ((int) $right['fee_types'] <=> (int) $left['fee_types'])
                ?: ((int) $right['order_count'] <=> (int) $left['order_count'])
                ?: strcmp((string) $left['item_id'], (string) $right['item_id']);
        });

        return $groups;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $filters
     */
    private function orderMatches(array $order, array $filters): bool
    {
        $platform = (string) $filters['platform'];
        if ($platform !== '' && $this->code((string) ($order['platform'] ?? '')) !== $platform) {
            return false;
        }

        $store = (string) $filters['store'];
        if ($store !== '' && (string) ($order['store'] ?? '') !== $store) {
            return false;
        }

        $date = $this->dateOnly((string) ($order['order_date'] ?? ''));
        if ($date === '') {
            return false;
        }

        $from = (string) $filters['date_from'];
        $to = (string) $filters['date_to'];
        return ($from === '' || $date >= $from) && ($to === '' || $date <= $to);
    }

    /**
     * @param array<string, mixed> $item
     * @return array{key: string, value: string, source: string}
     */
    private function shippingFee(array $item): array
    {
        $raw = $item['com_amount'] ?? null;
        $source = 'com_amount';
        if ($this->blankMoney($raw)) {
            $raw = $item['intl_fee'] ?? null;
            $source = 'intl_fee';
        }

        if ($this->blankMoney($raw)) {
            return ['key' => '', 'value' => '', 'source' => $source];
        }

        $value = $this->moneyString($raw);
        if ($value === '') {
            return ['key' => '', 'value' => '', 'source' => $source];
        }

        return ['key' => $value, 'value' => $value, 'source' => $source];
    }

    private function blankMoney(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        return false;
    }

    private function moneyString(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
        }

        $raw = trim((string) $value);
        $normalized = preg_replace('/[^\d.\-]+/', '', str_replace(',', '', $raw));
        if ($normalized === null || $normalized === '' || !is_numeric($normalized)) {
            return $raw;
        }

        return rtrim(rtrim(number_format((float) $normalized, 2, '.', ''), '0'), '.');
    }

    /** @param array<string, mixed> $item */
    private function itemIdentity(array $item, string $platform): string
    {
        $fields = in_array($platform, ['w', 'm', 'yp', 'q'], true)
            ? ['lot_number', 'item_code', 'item_management_id']
            : ['item_code', 'lot_number', 'item_management_id'];

        foreach ($fields as $field) {
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
        if ($value === '') {
            return '';
        }

        if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/', $value, $matches)) {
            return sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        if (preg_match('/^(\d{4})年(\d{1,2})月(\d{1,2})日/u', $value, $matches)) {
            return sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        return '';
    }

    private function code(string $value): string
    {
        return preg_replace('/[^a-z0-9_]/', '', strtolower(trim($value))) ?: '';
    }

    private function platformName(string $tenantKey, string $platform): string
    {
        $names = $this->app->tenantPlatformNames($tenantKey, true);
        return $names[$platform] ?? $platform;
    }

    /**
     * @param array<string, mixed>|null $user
     * @return array<int, array<string, mixed>>
     */
    private function storesForUser(string $tenantKey, ?array $user): array
    {
        $stores = $this->app->storesForTenant($tenantKey);
        if ($user === null || ($user['is_company_admin'] ?? false)) {
            return $stores;
        }

        $allowed = array_values(array_filter(array_map('trim', (array) ($user['stores'] ?? []))));
        if (!$allowed || in_array('全部店铺', $allowed, true)) {
            return $stores;
        }

        return array_values(array_filter(
            $stores,
            static fn (array $store): bool => in_array((string) ($store['name'] ?? ''), $allowed, true)
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     * @return array{name: string, filename: string, headers: array<int, string>, rows: array<int, array<int, mixed>>}
     */
    private function csvDataset(string $tenantKey, array $groups): array
    {
        $rows = [];
        foreach ($groups as $group) {
            foreach ($group['rows'] ?? [] as $row) {
                $rows[] = [
                    $row['platform_name'] ?? $row['platform'] ?? '',
                    $row['store'] ?? '',
                    $row['order_no'] ?? '',
                    $row['order_date'] ?? '',
                    $row['quantity'] ?? 0,
                    $row['item_id'] ?? '',
                    $row['title'] ?? '',
                    $row['item_option'] ?? '',
                    $row['shipnumber'] ?? '',
                    $row['fee_value'] ?? '',
                    $row['fee_source'] ?? '',
                    $row['weight'] ?? '',
                    implode('|', $group['fee_values'] ?? []),
                    $group['order_count'] ?? 0,
                    $group['fee_types'] ?? 0,
                ];
            }
        }

        return [
            'name' => '异常运费检测',
            'filename' => 'shipping-anomaly-' . $tenantKey . '-' . date('Ymd-His') . '.csv',
            'headers' => ['平台', '店铺', '订单号', '订单时间', '数量', '商品ID', '商品名称', '商品属性', '国际运单号', '国际运费', '运费字段', '产品重量', '异常组运费值', '组内订单数', '组内运费种类'],
            'rows' => $rows,
        ];
    }
}
