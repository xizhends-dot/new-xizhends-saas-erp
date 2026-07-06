<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;
use Xizhen\Services\Concerns\OrderMathHelpers;

final class ProfitService
{

    use OrderMathHelpers;

    /** @var array<int, string> */
    private const DEFAULT_ANALYSIS_STATUSES = [
        '国内采购-准备',
        '国内采购--问题',
        '国内采购-已采购',
        '国内采购-TB/PDD已采购',
        '发货中',
        '已到货',
        '已发货代订单',
        '已发日本',
        '已发出荷通知',
        '问题订单(后台处理)',
        '已到货问题件',
    ];

    public function __construct(private readonly StoreInterface $store)
    {
    }


    /** @return array<string, mixed> */
    /** @param array<string, mixed>|null $user */
    public function profitSummary(string $tenantKey, ?array $user = null): array
    {
        return $this->profitSummaryForOrders($tenantKey, $this->ordersForUser($tenantKey, $user));
    }

    /**
     * @param array<string, mixed>|null $user
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public function profitAnalysis(string $tenantKey, ?array $user = null, array $source = []): array
    {
        return $this->profitAnalysisForOrders($tenantKey, $this->ordersForUser($tenantKey, $user), $source);
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public function profitAnalysisForOrders(string $tenantKey, array $orders, array $source = []): array
    {
        $settings = $this->profitSettings($tenantKey);
        $platforms = $this->platformMap($tenantKey);
        $filters = $this->profitFilters($source, array_keys($platforms));
        $filters['excluded_statuses'] = array_flip($this->stringList($settings['excluded_purchase_statuses'] ?? []));
        $stores = $this->storeLookups($tenantKey);
        $statusOptions = $this->analysisStatusOptions($tenantKey, $orders, $filters['excluded_statuses']);
        $candidates = $this->profitCandidates($orders, $filters, $statusOptions);
        $orderInfos = $this->profitOrderInfos($candidates);

        $rows = [];
        foreach ($candidates as $candidate) {
            $row = $this->profitDetailRow($candidate, $orderInfos, $stores, $settings, $platforms, $filters);
            if (!$this->profitRowMatches($row, $filters)) {
                continue;
            }
            $rows[] = $row;
        }

        usort($rows, static fn (array $left, array $right): int => strcmp((string) ($right['order_date'] ?? ''), (string) ($left['order_date'] ?? '')));
        $pagination = $this->paginationFor($rows, $filters['page'], $filters['per_page']);

        return array_merge($this->profitDetailSummary($rows, $filters), [
            'rows' => array_slice($rows, $pagination['offset'], $pagination['per_page']),
            'settings' => $settings,
            'filters' => $filters,
            'pagination' => $pagination,
            'platforms' => $platforms,
            'status_options' => $statusOptions,
        ]);
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
        $storesById = [];
        $storesByName = [];
        foreach ($this->store->stores($tenantKey) as $store) {
            $storeId = (int) ($store['id'] ?? 0);
            if ($storeId > 0) {
                $storesById[$storeId] = $store;
            }
            $storeName = (string) ($store['name'] ?? '');
            if ($storeName !== '') {
                $storesByName[$storeName] = $store;
            }
        }

        $rows = [];
        foreach ($orders as $order) {
            $items = array_values(array_filter($order['items'] ?? [], 'is_array'));
            $quantity = $this->itemsQuantity($items);
            $japanPostage = $this->japanPostageForOrder($order, $items);
            $intlFeeData = $this->intlFeeForOrder($items, $defaultIntlFee);
            $sales = $this->salesForProfitOrder($order, $items, $japanPostage['total']);
            $purchaseCost = array_sum(array_map(
                fn (array $item): float => $this->firstPositiveItemMoney($item, ['amount', 'cn_amount']),
                $items
            ));
            $intlFee = $intlFeeData['total'];
            $store = $this->storeForProfitOrder($order, $storesById, $storesByName);
            $deduction = $platformDeductions[(string) ($order['platform'] ?? '')] ?? 70;
            $deductionSource = '平台扣点';
            if ($storeDeductionEnabled && isset($store['profit_deduction']) && is_numeric($store['profit_deduction'])) {
                $deduction = $store['profit_deduction'];
                $deductionSource = '店铺扣点';
            }
            $feeRatio = $this->deductionFeeRatio($deduction);
            $keepRatio = 1.0 - $feeRatio;
            $platformFee = round($sales * $feeRatio, 2);
            $salesAfterDeduction = round($sales * $keepRatio, 2);
            $salesAfterDeductionConverted = round($salesAfterDeduction * $exchangeRate, 2);
            $profit = $salesAfterDeductionConverted - $purchaseCost - $intlFee;
            $rows[] = [
                'order_no' => $order['platform_order_id'] ?? '',
                'store' => $order['store'] ?? '',
                'platform' => $order['platform'] ?? '',
                'item_count' => count($items),
                'quantity' => $quantity,
                'sales' => $sales,
                'japan_postage' => $japanPostage['total'],
                'japan_postage_shared' => $japanPostage['shared'],
                'postage_allocations' => $japanPostage['allocations'],
                'purchase_cost' => $purchaseCost,
                'intl_fee' => $intlFee,
                'intl_fee_source' => $intlFeeData['source'],
                'intl_fee_allocations' => $intlFeeData['allocations'],
                'platform_fee' => $platformFee,
                'platform_fee_converted' => round($platformFee * $exchangeRate, 2),
                'deduction' => round((float) $deduction, 2),
                'deduction_source' => $deductionSource,
                'exchange_rate' => $exchangeRate,
                'sales_converted' => round($sales * $exchangeRate, 2),
                'sales_after_deduction' => $salesAfterDeduction,
                'sales_after_deduction_converted' => $salesAfterDeductionConverted,
                'profit' => $profit,
                'margin' => $sales > 0 && $exchangeRate > 0 ? round($profit / $exchangeRate / $sales * 100, 1) : 0,
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
            'quantity' => array_sum(array_column($rows, 'quantity')),
            'sales' => array_sum(array_column($rows, 'sales')),
            'japan_postage' => array_sum(array_column($rows, 'japan_postage')),
            'sales_converted' => array_sum(array_column($rows, 'sales_converted')),
            'sales_after_deduction_converted' => array_sum(array_column($rows, 'sales_after_deduction_converted')),
            'purchase_cost' => array_sum(array_column($rows, 'purchase_cost')),
            'intl_fee' => array_sum(array_column($rows, 'intl_fee')),
            'platform_fee' => array_sum(array_column($rows, 'platform_fee')),
            'platform_fee_converted' => array_sum(array_column($rows, 'platform_fee_converted')),
            'profit' => array_sum(array_column($rows, 'profit')),
        ];
    }

    /** @return array<string, mixed> */
    private function profitSettings(string $tenantKey): array
    {
        $settings = $this->store->tenantSettings($tenantKey);
        $profit = is_array($settings['profit'] ?? null) ? $settings['profit'] : [];
        $exchangeRate = $this->positiveFloat($profit['exchange_rate'] ?? $profit['fixed_exchange_rate'] ?? 0.046, 0.046);
        $defaultIntlFee = $this->positiveFloat($profit['default_intl_fee'] ?? $profit['default_shipping'] ?? 40, 40);

        return [
            'exchange_rate' => $exchangeRate,
            'default_intl_fee' => $defaultIntlFee,
            'store_deduction_enabled' => (bool) ($profit['store_deduction_enabled'] ?? true),
            'platform_deductions' => is_array($profit['platform_deductions'] ?? null) ? $profit['platform_deductions'] : [],
            'excluded_purchase_statuses' => $this->stringList($profit['excluded_purchase_statuses'] ?? ['已取消', '客人取消订单']),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function platformMap(string $tenantKey): array
    {
        $allowed = array_flip($this->enabledPlatformCodes($tenantKey));
        $platforms = [];
        foreach ($this->store->platforms() as $platform) {
            $code = (string) ($platform['code'] ?? '');
            if ($code !== '' && isset($allowed[$code])) {
                $platforms[$code] = $platform;
            }
        }

        return $platforms;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $allowedPlatforms
     * @return array<string, mixed>
     */
    private function profitFilters(array $source, array $allowedPlatforms): array
    {
        $platform = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($source['platform'] ?? '')) ?: '';
        $shippingType = (string) ($source['shipping_type'] ?? '');
        $page = $this->boundedInt($source['page'] ?? 1, 1, 1000000);

        return [
            'platform' => in_array($platform, $allowedPlatforms, true) ? $platform : '',
            'date_start' => $this->dateOnlyForProfit($source['date_start'] ?? $source['order_date_from'] ?? ''),
            'date_end' => $this->dateOnlyForProfit($source['date_end'] ?? $source['order_date_to'] ?? ''),
            'status' => trim((string) ($source['status'] ?? '')),
            'excluded_statuses' => [],
            'shipping_type' => in_array($shippingType, ['actual', 'estimate'], true) ? $shippingType : '',
            'order_id' => trim((string) ($source['order_id'] ?? $source['order_no'] ?? '')),
            'profit_threshold' => $this->nullableFloat($source['profit_threshold'] ?? null),
            'filter_low_profit' => (string) ($source['filter_low_profit'] ?? '') === '1',
            'target_profit_rate' => $this->positiveFloat($source['target_profit_rate'] ?? 15, 15),
            'per_page' => $this->boundedInt($source['per_page'] ?? $source['page_size'] ?? 100, 10, 500),
            'page' => $page,
        ];
    }

    /** @return array{by_id: array<int, array<string, mixed>>, by_name: array<string, array<string, mixed>>} */
    private function storeLookups(string $tenantKey): array
    {
        $lookups = ['by_id' => [], 'by_name' => []];
        foreach ($this->store->stores($tenantKey) as $store) {
            $storeId = (int) ($store['id'] ?? 0);
            if ($storeId > 0) {
                $lookups['by_id'][$storeId] = $store;
            }
            $storeName = (string) ($store['name'] ?? '');
            if ($storeName !== '') {
                $lookups['by_name'][$storeName] = $store;
            }
        }

        return $lookups;
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, string>
     */
    private function analysisStatusOptions(string $tenantKey, array $orders, array $excludedStatuses = []): array
    {
        $configured = (new PurchaseStatusService($this->store))->statusesFor($tenantKey);
        $seen = [];
        foreach (array_merge(self::DEFAULT_ANALYSIS_STATUSES, $configured, $this->statusesInOrders($orders)) as $status) {
            $status = trim((string) $status);
            if ($status === '' || isset($excludedStatuses[$status]) || isset($seen[$status])) {
                continue;
            }
            $seen[$status] = true;
        }

        return array_keys($seen);
    }

    /** @param array<int, array<string, mixed>> $orders @return array<int, string> */
    private function statusesInOrders(array $orders): array
    {
        $statuses = [];
        foreach ($orders as $order) {
            foreach (array_values(array_filter($order['items'] ?? [], 'is_array')) as $item) {
                $statuses[] = (string) ($item['purchase_status'] ?? '');
            }
        }

        return $statuses;
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @param array<string, mixed> $filters
     * @param array<int, string> $statusOptions
     * @return array<int, array{order: array<string, mixed>, item: array<string, mixed>}>
     */
    private function profitCandidates(array $orders, array $filters, array $statusOptions): array
    {
        $rows = [];
        foreach ($orders as $order) {
            if (!$this->profitOrderMatches($order, $filters)) {
                continue;
            }
            foreach (array_values(array_filter($order['items'] ?? [], 'is_array')) as $item) {
                if ($this->profitItemMatches($order, $item, $filters, $statusOptions)) {
                    $rows[] = ['order' => $order, 'item' => $item];
                }
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $filters */
    private function profitOrderMatches(array $order, array $filters): bool
    {
        if ($filters['platform'] !== '' && (string) ($order['platform'] ?? '') !== $filters['platform']) {
            return false;
        }

        return $this->dateInProfitRange((string) ($order['order_date'] ?? $order['imported_at'] ?? ''), $filters['date_start'], $filters['date_end']);
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     * @param array<string, mixed> $filters
     * @param array<int, string> $statusOptions
     */
    private function profitItemMatches(array $order, array $item, array $filters, array $statusOptions): bool
    {
        $status = (string) ($item['purchase_status'] ?? '');
        if (isset($filters['excluded_statuses'][$status])) {
            return false;
        }
        if ($filters['status'] !== '' && $filters['status'] !== '__ALL__' && $status !== $filters['status']) {
            return false;
        }
        if ($filters['status'] === '' && !in_array($status, $statusOptions, true)) {
            return false;
        }

        return $this->termsMatch($filters['order_id'], implode(' ', [
            $order['platform_order_id'] ?? '',
            $order['order_detail_id'] ?? '',
            $item['order_detail_id'] ?? '',
            $item['line_id'] ?? '',
            $item['item_code'] ?? '',
            $item['lot_number'] ?? '',
        ]));
    }

    /**
     * @param array<int, array{order: array<string, mixed>, item: array<string, mixed>}> $candidates
     * @return array<string, array<string, mixed>>
     */
    private function profitOrderInfos(array $candidates): array
    {
        $infos = [];
        foreach ($candidates as $candidate) {
            $key = $this->profitOrderKey($candidate['order']);
            $infos[$key] ??= ['count' => 0, 'total_actual_shipping' => 0.0, 'has_actual_shipping' => false, 'total_postage' => 0.0, 'has_postage' => false, 'seen_shipping' => []];
            $infos[$key]['count']++;
            $this->accumulateProfitShippingInfo($infos[$key], $candidate['order'], $candidate['item']);
        }

        return $infos;
    }

    /**
     * @param array<string, mixed> $info
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     */
    private function accumulateProfitShippingInfo(array &$info, array $order, array $item): void
    {
        $shipping = $this->actualShippingForItem($item);
        $shippingKey = trim((string) ($item['intl_number'] ?? '')) ?: ('item:' . (string) ($item['id'] ?? spl_object_id((object) $item)));
        if ($shipping > 0 && !isset($info['seen_shipping'][$shippingKey])) {
            $info['total_actual_shipping'] += $shipping;
            $info['has_actual_shipping'] = true;
            $info['seen_shipping'][$shippingKey] = true;
        }
        $postage = max($this->moneyFloat($item['postage_price'] ?? 0), $this->moneyFloat($order['postage_price'] ?? 0));
        if ($postage > 0) {
            $info['total_postage'] = max((float) $info['total_postage'], $postage);
            $info['has_postage'] = true;
        }
    }

    /**
     * @param array{order: array<string, mixed>, item: array<string, mixed>} $candidate
     * @param array<string, array<string, mixed>> $orderInfos
     * @param array{by_id: array<int, array<string, mixed>>, by_name: array<string, array<string, mixed>>} $stores
     * @param array<string, mixed> $settings
     * @param array<string, array<string, mixed>> $platforms
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function profitDetailRow(array $candidate, array $orderInfos, array $stores, array $settings, array $platforms, array $filters): array
    {
        $order = $candidate['order'];
        $item = $candidate['item'];
        $info = $orderInfos[$this->profitOrderKey($order)] ?? ['count' => 1, 'has_actual_shipping' => false, 'total_actual_shipping' => 0, 'total_postage' => 0, 'has_postage' => false];
        $pricing = $this->profitLinePricing($order, $item, $info, (float) $settings['default_intl_fee']);
        $deduction = $this->deductionForProfitOrder($order, $stores, $settings);
        $rate = (float) $settings['exchange_rate'];
        $cost = $this->firstPositiveItemMoney($item, ['amount', 'purchase_amount', 'cn_amount']);
        $actualIncome = round($pricing['sale_price'] * ($deduction['value'] / 100) * $rate, 2);
        $refCost = round($actualIncome - $pricing['shipping'], 2);
        $profit = round($refCost - $cost, 2);
        $profitRate = $rate > 0 && $pricing['sale_price'] > 0 ? round($profit / $rate / $pricing['sale_price'] * 100, 2) : 0.0;
        $suggested = $this->suggestedPrice($profitRate, $cost, $pricing['shipping'], $deduction['value'], $rate, $filters['profit_threshold'], $filters['target_profit_rate']);

        return $this->profitDetailPayload($order, $item, $platforms, $pricing, $deduction, $suggested, [
            'cost' => $cost,
            'actual_income' => $actualIncome,
            'ref_cost' => $refCost,
            'profit' => $profit,
            'profit_rate' => $profitRate,
            'exchange_rate' => $rate,
        ]);
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     * @param array<string, mixed> $info
     * @return array<string, mixed>
     */
    private function profitLinePricing(array $order, array $item, array $info, float $defaultShipping): array
    {
        $count = max(1, (int) ($info['count'] ?? 1));
        $platform = strtolower((string) ($order['platform'] ?? ''));
        $unitPrice = $this->unitPriceForProfit($item);
        $itemPostage = $this->moneyFloat($item['postage_price'] ?? 0);
        $postage = $itemPostage;
        $postageShared = false;
        if (in_array($platform, ['y', 'r'], true) && $count > 1 && !empty($info['has_postage'])) {
            $postage = round((float) $info['total_postage'] / $count, 2);
            $postageShared = true;
        }
        $hasActualShipping = !empty($info['has_actual_shipping']);

        return [
            'unit_price' => $unitPrice,
            'postage' => $postage,
            'original_postage' => $postageShared ? (float) $info['total_postage'] : $itemPostage,
            'postage_shared' => $postageShared,
            'sale_price' => round($unitPrice + $postage, 2),
            'shipping' => round(($hasActualShipping ? (float) $info['total_actual_shipping'] : $defaultShipping) / $count, 2),
            'shipping_count' => $count,
            'has_actual_shipping' => $hasActualShipping,
            'shipping_source' => $hasActualShipping ? '实际运费' : '预估运费',
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @param array{by_id: array<int, array<string, mixed>>, by_name: array<string, array<string, mixed>>} $stores
     * @param array<string, mixed> $settings
     * @return array{value: float, source: string}
     */
    private function deductionForProfitOrder(array $order, array $stores, array $settings): array
    {
        $store = $this->storeForProfitOrder($order, $stores['by_id'], $stores['by_name']);
        if (!empty($settings['store_deduction_enabled']) && isset($store['profit_deduction']) && is_numeric($store['profit_deduction'])) {
            return ['value' => $this->boundedFloat($store['profit_deduction'], 0, 100), 'source' => '店铺扣点'];
        }

        $platformDeductions = is_array($settings['platform_deductions'] ?? null) ? $settings['platform_deductions'] : [];
        return ['value' => $this->boundedFloat($platformDeductions[(string) ($order['platform'] ?? '')] ?? 70, 0, 100), 'source' => '平台扣点'];
    }

    /** @return array{price: int, need_adjust: bool} */
    private function suggestedPrice(float $profitRate, float $cost, float $shipping, float $deduction, float $exchangeRate, ?float $threshold, float $targetProfitRate): array
    {
        $targetRate = $targetProfitRate / 100;
        $denominator = $exchangeRate * (($deduction / 100) - $targetRate);
        $needAdjust = $threshold !== null && $profitRate < $threshold && $cost > 0 && $denominator > 0;

        return ['price' => $needAdjust ? (int) ceil(($cost + $shipping) / $denominator) : 0, 'need_adjust' => $needAdjust];
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     * @param array<string, array<string, mixed>> $platforms
     * @param array<string, mixed> $pricing
     * @param array{value: float, source: string} $deduction
     * @param array{price: int, need_adjust: bool} $suggested
     * @param array<string, mixed> $money
     * @return array<string, mixed>
     */
    private function profitDetailPayload(array $order, array $item, array $platforms, array $pricing, array $deduction, array $suggested, array $money): array
    {
        $platform = (string) ($order['platform'] ?? '');
        $suggestedDiff = $suggested['price'] > 0 ? $suggested['price'] - (float) $pricing['sale_price'] : 0;

        return [
            'order_id' => (int) ($order['id'] ?? 0),
            'item_id' => (int) ($item['id'] ?? 0),
            'platform' => $platform,
            'platform_name' => (string) ($platforms[$platform]['name'] ?? $platform),
            'order_no' => (string) ($order['platform_order_id'] ?? ''),
            'order_date' => (string) ($order['order_date'] ?? $order['imported_at'] ?? ''),
            'store' => (string) ($order['store'] ?? ''),
            'item_code' => (string) (($item['item_code'] ?? '') ?: ($item['lot_number'] ?? '')),
            'lot_number' => (string) ($item['lot_number'] ?? ''),
            'item_management_id' => (string) ($item['item_management_id'] ?? ''),
            'title' => (string) ($item['title'] ?? ''),
            'image' => (string) ($item['image'] ?? ''),
            'quantity' => $this->itemQuantity($item),
            'unit_price' => round((float) $pricing['unit_price'], 2),
            'postage' => round((float) $pricing['postage'], 2),
            'original_postage' => round((float) $pricing['original_postage'], 2),
            'postage_shared' => (bool) $pricing['postage_shared'],
            'sale_price' => round((float) $pricing['sale_price'], 2),
            'shipping' => round((float) $pricing['shipping'], 2),
            'shipping_count' => (int) $pricing['shipping_count'],
            'has_actual_shipping' => (bool) $pricing['has_actual_shipping'],
            'shipping_source' => (string) $pricing['shipping_source'],
            'deduction' => round($deduction['value'], 2),
            'deduction_source' => $deduction['source'],
            'exchange_rate' => round((float) $money['exchange_rate'], 4),
            'actual_income' => round((float) $money['actual_income'], 2),
            'ref_cost' => round((float) $money['ref_cost'], 2),
            'cost' => round((float) $money['cost'], 2),
            'profit' => round((float) $money['profit'], 2),
            'profit_rate' => round((float) $money['profit_rate'], 2),
            'suggested_price' => $suggested['price'],
            'suggested_diff' => round($suggestedDiff, 2),
            'need_adjust' => $suggested['need_adjust'],
            'purchase_status' => (string) ($item['purchase_status'] ?? ''),
            'tabaono' => (string) ($item['tabaono'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $filters */
    private function profitRowMatches(array $row, array $filters): bool
    {
        if ($filters['shipping_type'] === 'actual' && empty($row['has_actual_shipping'])) {
            return false;
        }
        if ($filters['shipping_type'] === 'estimate' && !empty($row['has_actual_shipping'])) {
            return false;
        }
        if ($filters['filter_low_profit'] && $filters['profit_threshold'] !== null && (float) $row['profit_rate'] >= (float) $filters['profit_threshold']) {
            return false;
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function profitDetailSummary(array $rows, array $filters): array
    {
        $count = count($rows);
        $sumRate = array_sum(array_column($rows, 'profit_rate'));

        return [
            'total_count' => $count,
            'loss_count' => count(array_filter($rows, static fn (array $row): bool => (float) ($row['profit_rate'] ?? 0) < 0)),
            'warning_count' => $filters['profit_threshold'] === null ? 0 : count(array_filter(
                $rows,
                static fn (array $row): bool => (float) ($row['profit_rate'] ?? 0) >= 0 && (float) ($row['profit_rate'] ?? 0) < (float) $filters['profit_threshold']
            )),
            'need_adjust_count' => count(array_filter($rows, static fn (array $row): bool => !empty($row['need_adjust']))),
            'actual_shipping_count' => count(array_filter($rows, static fn (array $row): bool => !empty($row['has_actual_shipping']))),
            'estimate_shipping_count' => count(array_filter($rows, static fn (array $row): bool => empty($row['has_actual_shipping']))),
            'total_profit' => round(array_sum(array_column($rows, 'profit')), 2),
            'avg_profit_rate' => $count > 0 ? round($sumRate / $count, 2) : 0.0,
            'total_sale_price' => round(array_sum(array_column($rows, 'sale_price')), 2),
            'total_shipping' => round(array_sum(array_column($rows, 'shipping')), 2),
            'total_cost' => round(array_sum(array_column($rows, 'cost')), 2),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{total: int, per_page: int, page: int, total_pages: int, offset: int, from: int, to: int}
     */
    private function paginationFor(array $rows, int $page, int $perPage): array
    {
        $total = count($rows);
        $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;

        return [
            'total' => $total,
            'per_page' => $perPage,
            'page' => $page,
            'total_pages' => $totalPages,
            'offset' => $offset,
            'from' => $total === 0 ? 0 : $offset + 1,
            'to' => min($total, $offset + $perPage),
        ];
    }

    /** @param array<string, mixed> $order */
    private function profitOrderKey(array $order): string
    {
        $orderNo = (string) ($order['platform_order_id'] ?? '');
        return (string) ($order['platform'] ?? '') . ':' . ($orderNo !== '' ? $orderNo : (string) ($order['id'] ?? '0'));
    }

    /** @param array<string, mixed> $item */
    private function unitPriceForProfit(array $item): float
    {
        $unitPrice = $this->moneyFloat($item['unit_price'] ?? 0);
        return $unitPrice > 0 ? $unitPrice : $this->moneyFloat($item['line_total'] ?? 0);
    }

    /** @param array<string, mixed> $item */
    private function actualShippingForItem(array $item): float
    {
        $intlFee = $this->moneyFloat($item['intl_fee'] ?? 0);
        return $intlFee > 0 ? $intlFee : $this->moneyFloat($item['com_amount'] ?? 0);
    }

    private function nullableFloat(mixed $value): ?float
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' && is_numeric($value) ? (float) $value : null;
    }

    /** @return array<int, string> */
    private function stringList(mixed $value): array
    {
        $values = is_array($value) ? $value : [];
        $seen = [];
        foreach ($values as $item) {
            $item = trim((string) $item);
            if ($item !== '' && !isset($seen[$item])) {
                $seen[$item] = true;
            }
        }

        return array_keys($seen);
    }

    private function boundedFloat(mixed $value, float $min, float $max): float
    {
        $number = is_numeric($value) ? (float) $value : $min;
        return max($min, min($max, $number));
    }

    private function boundedInt(mixed $value, int $min, int $max): int
    {
        $number = is_numeric($value) ? (int) $value : $min;
        return max($min, min($max, $number));
    }

    private function dateOnlyForProfit(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $normalized = str_replace(['年', '月', '日', '/'], ['-', '-', '', '-'], $value);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $normalized, $matches) !== 1) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
    }

    private function dateInProfitRange(string $value, string $from, string $to): bool
    {
        if ($from === '' && $to === '') {
            return true;
        }
        $date = $this->dateOnlyForProfit($value);
        if ($date === '') {
            return false;
        }

        return ($from === '' || $date >= $from) && ($to === '' || $date <= $to);
    }

    private function termsMatch(string $needle, string $haystack): bool
    {
        $needle = trim($needle);
        if ($needle === '') {
            return true;
        }
        $haystack = strtolower($haystack);
        foreach (preg_split('/[\s,，\r\n]+/u', $needle, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
            if (str_contains($haystack, strtolower(trim((string) $term)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $storesById
     * @param array<string, array<string, mixed>> $storesByName
     * @return array<string, mixed>
     */
    private function storeForProfitOrder(array $order, array $storesById, array $storesByName): array
    {
        $storeId = (int) ($order['store_id'] ?? 0);
        if ($storeId > 0 && isset($storesById[$storeId])) {
            return $storesById[$storeId];
        }

        $storeName = (string) ($order['store'] ?? '');
        return $storeName !== '' ? ($storesByName[$storeName] ?? []) : [];
    }


    /**
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $items
     * @return array{total: float, shared: bool, allocations: array<int, array<string, mixed>>}
     */
    private function japanPostageForOrder(array $order, array $items): array
    {
        $platform = strtolower((string) ($order['platform'] ?? ''));
        $orderPostage = $this->moneyFloat($order['postage_price'] ?? 0);
        $itemPostages = array_map(fn (array $item): float => $this->moneyFloat($item['postage_price'] ?? 0), $items);

        if (in_array($platform, ['y', 'r'], true)) {
            $total = $orderPostage > 0 ? $orderPostage : ($itemPostages ? max($itemPostages) : 0.0);

            return [
                'total' => $total,
                'shared' => $total > 0 && $this->itemsQuantity($items) > 1,
                'allocations' => $this->allocationsByQuantity($total, $items),
            ];
        }

        $total = $orderPostage > 0 ? $orderPostage : array_sum($itemPostages);

        return [
            'total' => $total,
            'shared' => false,
            'allocations' => $this->allocationsByQuantity($total, $items),
        ];
    }


    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{total: float, source: string, allocations: array<int, array<string, mixed>>}
     */
    private function intlFeeForOrder(array $items, float $defaultIntlFee): array
    {
        if (!$items) {
            return ['total' => 0.0, 'source' => '无商品', 'allocations' => []];
        }

        $total = $this->positiveItemMoneyTotal($items, 'intl_fee', 'intl_number');
        $source = '实际国际运费';
        if ($total <= 0) {
            $total = $this->positiveItemMoneyTotal($items, 'com_amount');
            $source = '旧comamount运费';
        }
        if ($total <= 0) {
            $total = $defaultIntlFee;
            $source = '默认国际运费';
        }

        return [
            'total' => $total,
            'source' => $source,
            'allocations' => $this->allocationsByQuantity($total, $items),
        ];
    }


    /**
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $items
     */
    private function salesForProfitOrder(array $order, array $items, float $japanPostage): float
    {
        $orderTotal = $this->moneyFloat($order['total'] ?? 0);
        $itemTotal = array_sum(array_map(fn (array $item): float => $this->itemSales($item), $items));
        if ($orderTotal > 0) {
            $platform = strtolower((string) ($order['platform'] ?? ''));
            if (in_array($platform, ['y', 'r'], true) && $japanPostage > 0 && $itemTotal > 0 && $orderTotal <= $itemTotal) {
                return $orderTotal + $japanPostage;
            }

            return $orderTotal;
        }

        return $itemTotal + $japanPostage;
    }


    /** @param array<string, mixed> $item */
    private function itemSales(array $item): float
    {
        $lineTotal = $this->moneyFloat($item['line_total'] ?? 0);
        if ($lineTotal > 0) {
            return $lineTotal;
        }

        return $this->moneyFloat($item['unit_price'] ?? 0) * $this->itemQuantity($item);
    }


    /** @param array<string, mixed> $item @param array<int, string> $fields */
    private function firstPositiveItemMoney(array $item, array $fields): float
    {
        foreach ($fields as $field) {
            $amount = $this->moneyFloat($item[$field] ?? 0);
            if ($amount > 0) {
                return $amount;
            }
        }

        return 0.0;
    }


    /** @param array<int, array<string, mixed>> $items */
    private function positiveItemMoneyTotal(array $items, string $field, ?string $dedupeField = null): float
    {
        $total = 0.0;
        $seen = [];
        foreach ($items as $index => $item) {
            $amount = $this->moneyFloat($item[$field] ?? 0);
            if ($amount <= 0) {
                continue;
            }

            if ($dedupeField !== null) {
                $key = trim((string) ($item[$dedupeField] ?? ''));
                if ($key !== '') {
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                } else {
                    $seen["__row_{$index}"] = true;
                }
            }

            $total += $amount;
        }

        return $total;
    }


    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function allocationsByQuantity(float $total, array $items): array
    {
        if (!$items || $total <= 0) {
            return [];
        }

        $totalQuantity = $this->itemsQuantity($items);
        $remaining = round($total, 2);
        $lastIndex = array_key_last($items);
        $allocations = [];
        foreach ($items as $index => $item) {
            $quantity = $this->itemQuantity($item);
            $amount = $index === $lastIndex
                ? round($remaining, 2)
                : round($total * $quantity / $totalQuantity, 2);
            $remaining -= $amount;
            $allocations[] = [
                'item_id' => (int) ($item['id'] ?? 0),
                'item_code' => (string) (($item['item_code'] ?? '') ?: ($item['lot_number'] ?? '')),
                'quantity' => $quantity,
                'amount' => $amount,
            ];
        }

        return $allocations;
    }


    private function deductionFeeRatio(mixed $deduction): float
    {
        $number = is_numeric($deduction) ? (float) $deduction : 70.0;
        $number = max(0.0, min(100.0, $number));

        return $number > 30 ? round((100.0 - $number) / 100.0, 4) : round($number / 100.0, 4);
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
