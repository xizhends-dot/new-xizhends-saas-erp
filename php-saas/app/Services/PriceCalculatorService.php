<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class PriceCalculatorService
{
    public function __construct(private readonly StoreInterface $store)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(string $tenantKey): array
    {
        $settings = $this->store->tenantSettings($tenantKey);
        $profit = is_array($settings['profit'] ?? null) ? $settings['profit'] : [];
        $exchangeRate = $this->positiveFloat(
            $profit['exchange_rate'] ?? $profit['fixed_exchange_rate'] ?? 0.048,
            0.048
        );

        return [
            'exchange_rate' => $exchangeRate,
            'exchange_rate_source' => (string) (($profit['exchange_rate_mode'] ?? 'fixed') === 'realtime' ? '实时汇率设置' : '固定汇率'),
            'shipping' => $this->nonNegativeFloat($profit['default_shipping'] ?? $profit['default_domestic_shipping'] ?? 40, 40),
            'deduction' => $this->boundedFloat($profit['default_price_deduction'] ?? 70, 0, 100),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function calculateRows(string $tenantKey, array $rows): array
    {
        $defaults = $this->defaults($tenantKey);
        $calculated = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $calculated[] = $this->calculateRow($row, $defaults, $index + 1);
        }

        return [
            'defaults' => $defaults,
            'rows' => $calculated,
            'summary' => $this->summary($calculated),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    public function calculateRow(array $row, ?array $defaults = null, int $rowNo = 1): array
    {
        $defaults ??= [
            'exchange_rate' => 0.048,
            'shipping' => 40,
            'deduction' => 70,
        ];
        $cost = $this->nonNegativeFloat($row['cost'] ?? 0, 0);
        $shipping = $this->nonNegativeFloat($row['shipping'] ?? $defaults['shipping'] ?? 40, 0);
        $deduction = $this->boundedFloat($row['deduction'] ?? $defaults['deduction'] ?? 70, 0, 100);
        $rate = $this->positiveFloat($row['exchange_rate'] ?? $row['rate'] ?? $defaults['exchange_rate'] ?? 0.048, 0.048);
        $salePrice = $this->nonNegativeFloat($row['sale_price'] ?? $row['salePrice'] ?? 0, 0);
        $targetProfit = $row['target_profit'] ?? $row['profit'] ?? null;
        $mode = (string) ($row['mode'] ?? '');

        if (($mode === 'target_profit' || ($salePrice <= 0 && is_numeric($targetProfit))) && $deduction > 0 && $rate > 0) {
            $salePrice = $this->salePriceForTargetProfit((float) $targetProfit, $cost, $shipping, $deduction, $rate);
        }

        $actualIncome = $salePrice * ($deduction / 100) * $rate;
        $totalCost = $cost + $shipping;
        $profit = $actualIncome - $totalCost;
        $profitRate = ($rate > 0 && $salePrice > 0) ? ($profit / $rate / $salePrice * 100) : 0;

        return [
            'row_no' => $rowNo,
            'name' => (string) ($row['name'] ?? ''),
            'cost' => round($cost, 2),
            'shipping' => round($shipping, 2),
            'deduction' => round($deduction, 2),
            'exchange_rate' => round($rate, 4),
            'sale_price' => ceil($salePrice),
            'actual_income' => round($actualIncome, 2),
            'total_cost' => round($totalCost, 2),
            'profit' => round($profit, 2),
            'profit_rate' => round($profitRate, 2),
            'is_profitable' => $profit >= 0,
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function quoteOrderItem(string $tenantKey, array $order, array $item, array $overrides = []): array
    {
        $settings = $this->store->tenantSettings($tenantKey);
        $profit = is_array($settings['profit'] ?? null) ? $settings['profit'] : [];
        $defaults = $this->defaults($tenantKey);
        $defaults['shipping'] = $this->nonNegativeFloat(
            $profit['default_intl_fee'] ?? $profit['default_shipping'] ?? $defaults['shipping'] ?? 40,
            (float) ($defaults['shipping'] ?? 40)
        );

        $store = $this->storeForOrder($tenantKey, $order);
        $platformDeductions = is_array($profit['platform_deductions'] ?? null) ? $profit['platform_deductions'] : [];
        $deduction = $this->boundedFloat(
            $store['profit_deduction'] ?? $platformDeductions[(string) ($order['platform'] ?? '')] ?? $defaults['deduction'] ?? 70,
            0,
            100
        );
        $deductionSource = isset($store['profit_deduction']) ? 'store' : 'tenant_default';
        $actualShipping = $this->actualShippingForOrder($order);
        $shipping = $actualShipping > 0 ? $actualShipping : (float) $defaults['shipping'];
        $shippingSource = $actualShipping > 0 ? 'actual_com_amount' : 'tenant_default';
        if (array_key_exists('shipping', $overrides) && trim((string) $overrides['shipping']) !== '') {
            $shipping = $this->nonNegativeFloat($overrides['shipping'], $shipping);
            $shippingSource = 'override';
        }

        if (array_key_exists('deduction', $overrides) && trim((string) $overrides['deduction']) !== '') {
            $deduction = $this->boundedFloat($overrides['deduction'], 0, 100);
            $deductionSource = 'override';
        }

        $salePrice = $this->defaultSalePrice($item);
        if (array_key_exists('sale_price', $overrides) && trim((string) $overrides['sale_price']) !== '') {
            $salePrice = $this->nonNegativeFloat($overrides['sale_price'], $salePrice);
        } elseif (array_key_exists('salePrice', $overrides) && trim((string) $overrides['salePrice']) !== '') {
            $salePrice = $this->nonNegativeFloat($overrides['salePrice'], $salePrice);
        }

        $cost = $this->defaultCost($item);
        if (array_key_exists('cost', $overrides) && trim((string) $overrides['cost']) !== '') {
            $cost = $this->nonNegativeFloat($overrides['cost'], $cost);
        }

        $row = [
            'name' => (string) (($item['title'] ?? '') ?: ($item['item_code'] ?? '')),
            'cost' => $cost,
            'shipping' => $shipping,
            'deduction' => $deduction,
            'exchange_rate' => $defaults['exchange_rate'] ?? 0.048,
            'sale_price' => $salePrice,
        ];
        $calculated = $this->calculateRow($row, $defaults, 1);

        return $this->quotePayload($tenantKey, $order, $item, $calculated, [
            'actual_shipping' => $actualShipping,
            'has_actual_shipping' => $actualShipping > 0,
            'shipping_source' => $shippingSource,
            'deduction_source' => $deductionSource,
            'exchange_rate_source' => (string) ($defaults['exchange_rate_source'] ?? ''),
            'default_shipping' => (float) $defaults['shipping'],
        ]);
    }

    private function salePriceForTargetProfit(float $targetProfit, float $cost, float $shipping, float $deduction, float $rate): float
    {
        if ($deduction <= 0 || $rate <= 0) {
            return 0.0;
        }

        return ceil(($targetProfit + $cost + $shipping) / ($deduction / 100) / $rate);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function summary(array $rows): array
    {
        $totalCost = array_sum(array_column($rows, 'cost'));
        $totalShipping = array_sum(array_column($rows, 'shipping'));
        $totalSalePrice = array_sum(array_column($rows, 'sale_price'));
        $totalActualIncome = array_sum(array_column($rows, 'actual_income'));
        $totalProfit = array_sum(array_column($rows, 'profit'));
        $weightedRate = 0.0;
        $salePriceForRate = 0.0;
        foreach ($rows as $row) {
            $salePrice = (float) ($row['sale_price'] ?? 0);
            $rate = (float) ($row['exchange_rate'] ?? 0);
            if ($salePrice > 0 && $rate > 0) {
                $weightedRate += $rate * $salePrice;
                $salePriceForRate += $salePrice;
            }
        }

        $avgRate = $salePriceForRate > 0 ? $weightedRate / $salePriceForRate : 0;
        $avgProfitRate = ($avgRate > 0 && $totalSalePrice > 0) ? ($totalProfit / $avgRate / $totalSalePrice * 100) : 0;

        return [
            'row_count' => count($rows),
            'total_cost' => round($totalCost, 2),
            'total_shipping' => round($totalShipping, 2),
            'total_sale_price' => round($totalSalePrice, 2),
            'total_actual_income' => round($totalActualIncome, 2),
            'total_profit' => round($totalProfit, 2),
            'avg_exchange_rate' => round($avgRate, 4),
            'avg_profit_rate' => round($avgProfitRate, 2),
            'is_profitable' => $totalProfit >= 0,
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function storeForOrder(string $tenantKey, array $order): array
    {
        $storeId = (int) ($order['store_id'] ?? 0);
        $storeName = (string) ($order['store'] ?? '');
        foreach ($this->store->stores($tenantKey) as $store) {
            if ($storeId > 0 && (int) ($store['id'] ?? 0) === $storeId) {
                return $store;
            }
            if ($storeName !== '' && (string) ($store['name'] ?? '') === $storeName) {
                return $store;
            }
        }

        return [];
    }

    /** @param array<string, mixed> $order */
    private function actualShippingForOrder(array $order): float
    {
        foreach ((array) ($order['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $amount = $this->nonNegativeFloat($item['com_amount'] ?? 0, 0);
            if ($amount > 0) {
                return $amount;
            }
        }

        return $this->nonNegativeFloat($order['com_amount'] ?? 0, 0);
    }

    /** @param array<string, mixed> $item */
    private function defaultSalePrice(array $item): float
    {
        $unitPrice = $this->nonNegativeFloat($item['unit_price'] ?? 0, 0);
        if ($unitPrice > 0) {
            return $unitPrice + $this->nonNegativeFloat($item['postage_price'] ?? 0, 0);
        }

        return $this->nonNegativeFloat($item['line_total'] ?? 0, 0);
    }

    /** @param array<string, mixed> $item */
    private function defaultCost(array $item): float
    {
        foreach (['amount', 'purchase_amount', 'cn_amount'] as $field) {
            $amount = $this->nonNegativeFloat($item[$field] ?? 0, 0);
            if ($amount > 0) {
                return $amount;
            }
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     * @param array<string, mixed> $calculated
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function quotePayload(string $tenantKey, array $order, array $item, array $calculated, array $context): array
    {
        $payload = array_merge($calculated, [
            'tenant_key' => $tenantKey,
            'order_id' => (int) ($order['id'] ?? 0),
            'order_no' => (string) ($order['platform_order_id'] ?? ''),
            'item_id' => (int) ($item['id'] ?? 0),
            'item_code' => (string) (($item['item_code'] ?? '') ?: ($item['lot_number'] ?? '')),
            'store' => (string) ($order['store'] ?? ''),
            'actual_shipping' => round((float) ($context['actual_shipping'] ?? 0), 2),
            'has_actual_shipping' => (bool) ($context['has_actual_shipping'] ?? false),
            'shipping_source' => (string) ($context['shipping_source'] ?? ''),
            'deduction_source' => (string) ($context['deduction_source'] ?? ''),
            'exchange_rate_source' => (string) ($context['exchange_rate_source'] ?? ''),
            'default_shipping' => round((float) ($context['default_shipping'] ?? 0), 2),
        ]);

        $payload['salePrice'] = $payload['sale_price'];
        $payload['actualIncome'] = $payload['actual_income'];
        $payload['profitRate'] = $payload['profit_rate'];
        $payload['realProfit'] = $payload['profit'];
        $payload['realProfitRate'] = $payload['profit_rate'];
        $payload['exchangeRate'] = $payload['exchange_rate'];
        $payload['actualShipping'] = $payload['actual_shipping'];
        $payload['hasActualShipping'] = $payload['has_actual_shipping'];

        return $payload;
    }

    private function positiveFloat(mixed $value, float $default): float
    {
        $number = is_numeric($value) ? (float) $value : $default;
        return $number > 0 ? $number : $default;
    }

    private function nonNegativeFloat(mixed $value, float $default): float
    {
        $number = is_numeric($value) ? (float) $value : $default;
        return max(0.0, $number);
    }

    private function boundedFloat(mixed $value, float $min, float $max): float
    {
        $number = is_numeric($value) ? (float) $value : $min;
        return max($min, min($max, $number));
    }
}
