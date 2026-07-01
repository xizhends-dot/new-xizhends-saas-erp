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
