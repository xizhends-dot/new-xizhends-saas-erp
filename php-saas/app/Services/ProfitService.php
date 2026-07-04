<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;
use Xizhen\Services\Concerns\OrderMathHelpers;

final class ProfitService
{

    use OrderMathHelpers;

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
