<?php

declare(strict_types=1);

namespace Xizhen\Services\Concerns;

trait OrderMathHelpers
{


    private function moneyFloat(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $normalized = preg_replace('/[^\d.\-]+/', '', str_replace(',', '', (string) ($value ?? '')));
        return is_numeric($normalized) ? (float) $normalized : 0.0;
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


    /** @param array<string, mixed> $item */
    private function itemQuantity(array $item): int
    {
        return max(1, (int) ($item['quantity'] ?? 1));
    }


    /** @param array<int, array<string, mixed>> $items */
    private function itemsQuantity(array $items): int
    {
        if (!$items) {
            return 0;
        }

        $quantity = array_sum(array_map(fn (array $item): int => $this->itemQuantity($item), $items));
        return max(1, (int) $quantity);
    }
}
