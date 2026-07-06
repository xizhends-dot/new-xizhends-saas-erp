<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;
use Xizhen\Services\Concerns\OrderMathHelpers;

final class OrderFilterService
{

    use OrderMathHelpers;


    /** @var array<int, string> */
    private const DEFAULT_HIDDEN_PURCHASE_STATUSES = [
        '国内采购-已采购',
        '发货中',
        '国内采购--问题',
        '国内采购-TB/PDD已采购',
        '已发日本',
        '客人取消订单',
        '已到货',
        '问题订单(后台处理)',
        '日本库存订单',
        '日本仓库已发出荷通知',
        '已到货问题件',
        '已发出荷通知',
        '已取消',
        '日本仓库已处理',
        '库存缺货订单',
        '刷单订单',
        '已发货代订单',
        'mii订单',
        '刷单订单已发出荷',
    ];

    public function __construct(private readonly StoreInterface $store)
    {
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
            $copy['items'] = array_values(array_filter($order['items'] ?? [], function (array $item) use ($order, $view, $source, $filters): bool {
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
                return $this->itemMatchesFilters($item, $view, $filters, $order);
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


    /** @param array<string, mixed> $order */
    private function orderMatchesFilters(array $order, array $filters): bool
    {
        $checks = [
            'store' => $order['store'] ?? '',
            'customer_name' => $order['customer']['name'] ?? '',
            'kana' => $order['customer']['kana'] ?? '',
            'mail' => $order['customer']['mail'] ?? '',
            'phone' => $order['customer']['phone'] ?? '',
            'pay_method' => $order['pay_method'] ?? '',
            'ship_method' => implode(' ', [$order['ship_method'] ?? '', $order['pay_method'] ?? '']),
        ];
        foreach ($checks as $key => $value) {
            $needle = trim((string) ($filters[$key] ?? ''));
            if ($needle !== '' && !$this->containsFilterValue($value, $needle)) {
                return false;
            }
        }

        if (!$this->dateInRange($order['imported_at'] ?? '', (string) ($filters['import_date_from'] ?? ''), (string) ($filters['import_date_to'] ?? ''))) {
            return false;
        }
        if (!$this->dateInRange($order['order_date'] ?? '', (string) ($filters['order_date_from'] ?? ''), (string) ($filters['order_date_to'] ?? ''))) {
            return false;
        }
        $dateScope = (string) ($filters['date_scope'] ?? 'imported');
        if ($dateScope === 'imported' && !$this->dateInRange($order['imported_at'] ?? $order['order_date'] ?? '', (string) ($filters['date_from'] ?? ''), (string) ($filters['date_to'] ?? ''))) {
            return false;
        }
        if ($dateScope === 'order' && !$this->dateInRange($order['order_date'] ?? '', (string) ($filters['date_from'] ?? ''), (string) ($filters['date_to'] ?? ''))) {
            return false;
        }

        foreach (['review_invited', 'reviewed'] as $key) {
            $expected = $this->booleanFilterValue($filters[$key] ?? '');
            if ($expected !== null && !empty($order[$key]) !== $expected) {
                return false;
            }
        }

        return true;
    }


    /** @param array<string, mixed> $item @param array<string, mixed> $order */
    private function itemMatchesFilters(array $item, string $view, array $filters, array $order): bool
    {
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== '__ALL__') {
            $currentStatus = $view === 'jp'
                ? (string) ($item['out_status'] ?? '')
                : (string) ($item['purchase_status'] ?? '');
            if ($currentStatus !== $status) {
                return false;
            }
        } elseif ($status === '' && $view !== 'jp' && $this->usesDefaultPendingStatus($filters)) {
            if (in_array((string) ($item['purchase_status'] ?? ''), self::DEFAULT_HIDDEN_PURCHASE_STATUSES, true)) {
                return false;
            }
        }

        $checks = [
            'order_no' => implode(' ', [
                $order['platform_order_id'] ?? '',
                $order['order_detail_id'] ?? '',
                $item['order_detail_id'] ?? '',
                $item['line_id'] ?? '',
            ]),
            'order_detail_id' => implode(' ', [$order['order_detail_id'] ?? '', $item['order_detail_id'] ?? '', $item['line_id'] ?? '']),
            'tabaono' => implode(' ', [$item['tabaono'] ?? '', $item['comment'] ?? '', $item['logistic_trace'] ?? '']),
            'item_id' => implode(' ', [$item['item_code'] ?? '', $item['title'] ?? '', $item['jp_warehouse_id'] ?? '', $item['order_detail_id'] ?? '']),
            'lot_number' => $item['lot_number'] ?? '',
            'item_management_id' => $item['item_management_id'] ?? '',
            'product_name' => implode(' ', [$item['title'] ?? '', $item['option'] ?? '']),
            'buyer' => $view === 'jp' ? ($item['assignee'] ?? '') : ($item['buyer'] ?? ''),
            'cn_ship_no' => implode(' ', [$item['ship_number'] ?? '', $item['comment'] ?? '', $item['logistic_trace'] ?? '']),
            'intl_ship_no' => ($item['intl_number'] ?? '') ?: ($item['ship_number'] ?? ''),
            'carrier' => $item['ship_company'] ?? '',
            'location' => implode(' ', [$item['location'] ?? '', $item['jp_warehouse_id'] ?? '']),
            'receipt_city' => implode(' ', [$item['receipt_city'] ?? '', $item['location'] ?? '', $item['logistics'] ?? '', $item['logistic_trace'] ?? '']),
            'purchase_link' => implode(' ', [$item['purchase_link'] ?? '', $item['buhuo_link'] ?? '']),
            'comment' => implode(' ', [$item['comment'] ?? '', $item['tranship_comment'] ?? '', $item['intl_comment'] ?? '']),
            'purchase_comment' => implode(' ', [$item['comment'] ?? '', $item['logistic_trace'] ?? '']),
            'material' => $item['material'] ?? '',
        ];
        foreach ($checks as $key => $value) {
            $needle = trim((string) ($filters[$key] ?? ''));
            if ($needle !== '' && !$this->containsFilterValue($value, $needle)) {
                return false;
            }
        }

        if (!empty($filters['lot_number_empty']) && trim((string) ($item['lot_number'] ?? '')) !== '') {
            return false;
        }
        $intlShipState = trim((string) ($filters['intl_ship_empty'] ?? ''));
        if ($intlShipState !== '') {
            $hasIntlNumber = trim((string) ($item['intl_number'] ?? '')) !== '';
            if (($intlShipState === 'no' && $hasIntlNumber) || ($intlShipState === 'yes' && !$hasIntlNumber)) {
                return false;
            }
            if (!in_array($intlShipState, ['no', 'yes'], true) && $hasIntlNumber) {
                return false;
            }
        }
        if (!$this->dateInRange($item['purchase_time'] ?? '', (string) ($filters['purchase_date_from'] ?? ''), (string) ($filters['purchase_date_to'] ?? ''))) {
            return false;
        }
        $purchaseDate = trim((string) ($filters['purchase_date'] ?? ''));
        if ($purchaseDate !== '' && !$this->dateStartsWith($item['purchase_time'] ?? '', $purchaseDate)) {
            return false;
        }
        if (($filters['date_scope'] ?? '') === 'purchase' && !$this->dateInRange($item['purchase_time'] ?? '', (string) ($filters['date_from'] ?? ''), (string) ($filters['date_to'] ?? ''))) {
            return false;
        }

        $frbPush = trim((string) ($filters['frb_push'] ?? ''));
        if ($frbPush !== '') {
            $hasFrbPush = $this->hasFlyRabbitPush($order, $item);
            if (($frbPush === 'yes' && !$hasFrbPush) || ($frbPush === 'no' && $hasFrbPush)) {
                return false;
            }
        }

        if (!empty($filters['late_ship']) && !$this->isLateShipItem($item)) {
            return false;
        }
        $deliveryText = implode(' ', [$item['logistics'] ?? '', $item['logistic_trace'] ?? '', $item['intl_status'] ?? '']);
        if (!empty($filters['in_delivery']) && trim($deliveryText) === '') {
            return false;
        }
        if (!empty($filters['delivered']) && !$this->isDeliveredLogistics($item, $deliveryText)) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $item */
    private function hasFlyRabbitPush(array $order, array $item): bool
    {
        $orderExtra = is_array($order['platform_extra'] ?? null) ? $order['platform_extra'] : [];
        $itemExtra = is_array($item['platform_extra'] ?? null) ? $item['platform_extra'] : [];
        foreach ([
            $order['frb_pushed_at'] ?? '',
            $order['frb_order_no'] ?? '',
            $item['frb_pushed_at'] ?? '',
            $item['frb_order_no'] ?? '',
            $orderExtra['frb_pushed_at'] ?? '',
            $orderExtra['frb_order_no'] ?? '',
            $itemExtra['frb_pushed_at'] ?? '',
            $itemExtra['frb_order_no'] ?? '',
        ] as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }


    private function containsFilterValue(mixed $value, string $needle): bool
    {
        $haystack = strtolower((string) $value);
        foreach ($this->filterTerms($needle) as $term) {
            if (str_contains($haystack, strtolower($term))) {
                return true;
            }
        }

        return false;
    }


    /** @return array<int, string> */
    private function filterTerms(string $needle): array
    {
        $terms = preg_split('/[\\s,，]+/u', trim($needle)) ?: [];
        $terms = array_values(array_filter(array_map('trim', $terms), static fn (string $term): bool => $term !== ''));

        return $terms ?: [trim($needle)];
    }


    private function dateInRange(mixed $value, string $from, string $to): bool
    {
        $from = $this->dateOnly($from);
        $to = $this->dateOnly($to);
        if ($from === '' && $to === '') {
            return true;
        }

        $date = $this->dateOnly((string) $value);
        if ($date === '') {
            return false;
        }

        return ($from === '' || $date >= $from) && ($to === '' || $date <= $to);
    }


    private function dateStartsWith(mixed $value, string $prefix): bool
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return true;
        }

        return str_starts_with((string) $value, $prefix);
    }


    private function dateOnly(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}/', $value)) {
            return '';
        }

        return substr($value, 0, 10);
    }


    private function booleanFilterValue(mixed $value): ?bool
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (in_array($value, ['1', 'true', 'on', 'yes', '已邀评', '已评价'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'off', 'no', '未邀评', '未评价'], true)) {
            return false;
        }

        return null;
    }


    private function usesDefaultPendingStatus(array $filters): bool
    {
        if (empty($filters['default_pending']) || $this->showsAllOrders($filters)) {
            return false;
        }

        foreach ($filters as $key => $value) {
            if (in_array((string) $key, ['status', 'page_size', 'date_scope', 'default_pending', 'all_orders'], true)) {
                continue;
            }
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }


    private function showsAllOrders(array $filters): bool
    {
        return trim((string) ($filters['status'] ?? '')) === '__ALL__'
            || trim((string) ($filters['all_orders'] ?? '')) === '1';
    }


    /** @param array<string, mixed> $item */
    private function isDeliveredLogistics(array $item, string $deliveryText): bool
    {
        return str_contains($deliveryText, '配達完了')
            || str_contains($deliveryText, 'お客様引渡完了')
            || str_contains(strtolower($deliveryText), 'delivered')
            || trim((string) ($item['jpship_completed_at'] ?? '')) !== '';
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

        $logisticsStatus = trim(implode(' ', [
            (string) ($item['logistics'] ?? ''),
            (string) ($item['logistic_trace'] ?? ''),
            (string) ($item['intl_status'] ?? ''),
        ]));
        if ($logisticsStatus !== '') {
            return false;
        }

        $purchaseTime = strtotime((string) ($item['purchase_time'] ?? ''));
        return $purchaseTime !== false && $purchaseTime < strtotime('-3 days');
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

    /** @param array<string, mixed>|null $user @return array<int, array<string, mixed>> */
    private function ordersForUser(string $tenantKey, ?array $user): array
    {
        if ($user === null || ($user['role'] ?? '') === '公司管理员' || ($user['is_company_admin'] ?? false)) {
            return $this->filterOrdersByTenantPlatforms($tenantKey, $this->store->orders($tenantKey));
        }

        return $this->filterOrdersByTenantPlatforms($tenantKey, $this->store->ordersForStores($tenantKey, (array) ($user['stores'] ?? [])));
    }

    /** @param array<int, array<string, mixed>> $orders @return array<int, array<string, mixed>> */
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

    /** @return array<int, string> */
    private function enabledPlatformCodes(string $tenantKey, bool $includeLocked = false): array
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
}
