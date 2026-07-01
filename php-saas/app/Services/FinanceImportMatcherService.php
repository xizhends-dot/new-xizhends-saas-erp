<?php

declare(strict_types=1);

namespace Xizhen\Services;

final class FinanceImportMatcherService
{
    /**
     * @param array<int, array<int|string, mixed>> $rows
     * @return array{row_count: int, matched: int, unmatched: int, updates: array<int, array<string, mixed>>, records: array<int, array<string, mixed>>, errors: array<int, string>, summary: array<string, int>}
     */
    public function planForOrders(array $orders, array $rows): array
    {
        $index = $this->trackingIndex($orders);
        $updates = [];
        $records = [];
        $errors = [];
        $rowNumber = 0;

        foreach ($rows as $row) {
            $rowNumber++;
            if ($rowNumber === 1 && $this->looksLikeHeader($row)) {
                continue;
            }

            $record = $this->financeRow($row, $rowNumber);
            if (!$record['ok']) {
                $errors[] = '第 ' . $rowNumber . ' 行：' . $record['message'];
                continue;
            }

            $matches = $this->matchTracking($record['tracking'], $index);
            if (!$matches) {
                $errors[] = '第 ' . $rowNumber . ' 行：未找到运单号 ' . $record['tracking'] . ' 对应的订单商品。';
                continue;
            }

            foreach ($matches as $match) {
                $update = [
                    'row' => $rowNumber,
                    'match_type' => $match['type'],
                    'tracking' => $record['tracking'],
                    'order_id' => $match['order_id'],
                    'platform_order_id' => $match['platform_order_id'],
                    'item_id' => $match['item_id'],
                    'item_code' => $match['item_code'],
                    'changes' => $record['changes'],
                ];
                $updates[] = $update;
                $records[] = [
                    'row' => $rowNumber,
                    'identity' => [
                        'platform_order_id' => $match['platform_order_id'],
                        'item_id' => $match['item_id'],
                        'item_code' => $match['item_code'],
                    ],
                    'changes' => $record['changes'],
                    'match' => [
                        'tracking' => $record['tracking'],
                        'type' => $match['type'],
                    ],
                ];
            }
        }

        return [
            'row_count' => max(0, $rowNumber - ($rowNumber > 0 && $this->looksLikeHeader($rows[0] ?? []) ? 1 : 0)),
            'matched' => count($updates),
            'unmatched' => count($errors),
            'updates' => $updates,
            'records' => $records,
            'errors' => array_slice($errors, 0, 50),
            'summary' => [
                'rows' => max(0, $rowNumber - ($rowNumber > 0 && $this->looksLikeHeader($rows[0] ?? []) ? 1 : 0)),
                'updates' => count($updates),
                'errors' => count($errors),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<int, string>
     */
    public function trackingValuesForItem(array $item): array
    {
        $values = [];
        foreach (['intl_number', 'ship_number'] as $field) {
            $value = $this->normalizeTracking((string) ($item[$field] ?? ''));
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    public function normalizeTracking(string $tracking): string
    {
        $tracking = trim($tracking);
        $tracking = preg_replace('/[^a-zA-Z0-9\-_\,]+/', ',', $tracking) ?? $tracking;
        $tracking = preg_replace('/,+/', ',', $tracking) ?? $tracking;

        return trim($tracking, ',');
    }

    /**
     * @param array<int|string, mixed> $row
     * @return array{ok: bool, message?: string, tracking?: string, changes?: array<string, mixed>}
     */
    private function financeRow(array $row, int $rowNumber): array
    {
        $values = $this->rowValues($row);
        if (count($values) < 5) {
            return ['ok' => false, 'message' => '财务导入要求 5 列：订单号、运单号、重量、国际运费、国内金额。'];
        }

        $tracking = $this->normalizeTracking((string) ($values[1] ?? ''));
        if (strlen($tracking) <= 6) {
            return ['ok' => false, 'message' => '运单号为空或长度不足。'];
        }

        $changes = array_filter([
            'weight' => $this->cleanScalar($values[2] ?? ''),
            'com_amount' => $this->cleanScalar($values[3] ?? ''),
            'cn_amount' => $this->cleanScalar($values[4] ?? ''),
        ], static fn (mixed $value): bool => $value !== '');

        if ($changes === []) {
            return ['ok' => false, 'message' => '没有可更新的重量、国际运费或国内金额。'];
        }

        return [
            'ok' => true,
            'tracking' => $tracking,
            'changes' => $changes,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private function trackingIndex(array $orders): array
    {
        $index = [];
        foreach ($orders as $order) {
            foreach (array_values(array_filter($order['items'] ?? [], 'is_array')) as $item) {
                foreach ($this->trackingValuesForItem($item) as $tracking) {
                    $index[] = [
                        'tracking' => $tracking,
                        'order_id' => (int) ($order['id'] ?? 0),
                        'platform_order_id' => (string) ($order['platform_order_id'] ?? ''),
                        'item_id' => (int) ($item['id'] ?? 0),
                        'item_code' => (string) (($item['item_code'] ?? '') ?: ($item['lot_number'] ?? '')),
                    ];
                }
            }
        }

        return $index;
    }

    /**
     * @param array<int, array<string, mixed>> $index
     * @return array<int, array<string, mixed>>
     */
    private function matchTracking(string $needle, array $index): array
    {
        $matches = [];
        foreach ($index as $entry) {
            $type = $this->matchType($needle, (string) ($entry['tracking'] ?? ''));
            if ($type === '') {
                continue;
            }

            $entry['type'] = $type;
            $matches[] = $entry;
        }

        return $matches;
    }

    private function matchType(string $needle, string $current): string
    {
        if ($needle === '' || $current === '') {
            return '';
        }
        if ($current === $needle) {
            return 'exact';
        }

        $quoted = preg_quote($needle, '/');
        if (preg_match('/^\s*' . $quoted . '\s*,/', $current) === 1) {
            return 'prefix';
        }
        if (preg_match('/,\s*' . $quoted . '\s*$/', $current) === 1) {
            return 'suffix';
        }
        if (preg_match('/,\s*' . $quoted . '\s*,/', $current) === 1) {
            return 'middle';
        }

        return '';
    }

    /** @param array<int|string, mixed> $row */
    private function looksLikeHeader(array $row): bool
    {
        $joined = strtolower(implode(' ', array_map('strval', $this->rowValues($row))));

        return str_contains($joined, 'ship')
            || str_contains($joined, '运单')
            || str_contains($joined, '重量')
            || str_contains($joined, 'comamount')
            || str_contains($joined, 'cnamount');
    }

    /** @param array<int|string, mixed> $row @return array<int, mixed> */
    private function rowValues(array $row): array
    {
        if (array_is_list($row)) {
            return $row;
        }

        return array_values($row);
    }

    private function cleanScalar(mixed $value): string
    {
        return trim((string) $value);
    }
}
