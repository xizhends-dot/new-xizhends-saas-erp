<?php

declare(strict_types=1);

namespace Xizhen\Services;

final class JapanWarehouseImportService
{
    /**
     * @param array<int, array<int|string, mixed>> $rows
     * @return array{row_count: int, records: array<int, array<string, mixed>>, preview: array<int, array<string, mixed>>, errors: array<int, string>}
     */
    public function parseRows(array $rows): array
    {
        $records = [];
        $preview = [];
        $errors = [];
        $rowNumber = 0;
        $skippedHeader = false;

        foreach ($rows as $row) {
            $rowNumber++;
            if ($rowNumber === 1 && $this->looksLikeHeader($row)) {
                $skippedHeader = true;
                continue;
            }

            $parsed = $this->warehouseRow($row, $rowNumber);
            if (!$parsed['ok']) {
                $errors[] = '第 ' . $rowNumber . ' 行：' . $parsed['message'];
                continue;
            }

            $records[] = $parsed['record'];
            if (count($preview) < 5) {
                $preview[] = $parsed['record'];
            }
        }

        return [
            'row_count' => max(0, $rowNumber - ($skippedHeader ? 1 : 0)),
            'records' => $records,
            'preview' => $preview,
            'errors' => array_slice($errors, 0, 50),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @param array<int, array<int|string, mixed>> $rows
     * @return array{updates: array<int, array<string, mixed>>, unmatched: array<int, array<string, mixed>>, parsed: array<string, mixed>}
     */
    public function planForOrders(array $orders, array $rows): array
    {
        $parsed = $this->parseRows($rows);
        $index = $this->trackingIndex($orders);
        $updates = [];
        $unmatched = [];

        foreach ($parsed['records'] as $record) {
            $tracking = (string) ($record['match_tracking'] ?? '');
            $matches = $index[$tracking] ?? [];
            if (!$matches) {
                $unmatched[] = $record;
                continue;
            }

            foreach ($matches as $match) {
                $updates[] = [
                    'row' => $record['row'],
                    'tracking' => $tracking,
                    'order_id' => $match['order_id'],
                    'platform_order_id' => $match['platform_order_id'],
                    'item_id' => $match['item_id'],
                    'item_code' => $match['item_code'],
                    'changes' => $record['changes'],
                ];
            }
        }

        return [
            'updates' => $updates,
            'unmatched' => $unmatched,
            'parsed' => $parsed,
        ];
    }

    /**
     * @param array<int|string, mixed> $row
     * @return array{ok: bool, message?: string, record?: array<string, mixed>}
     */
    private function warehouseRow(array $row, int $rowNumber): array
    {
        $values = $this->rowValues($row);
        if (count($values) < 5) {
            return ['ok' => false, 'message' => 'YD 表至少需要 5 列：订单号、运单号、件数、重量、国际运费。'];
        }

        $orderId = trim((string) ($values[0] ?? ''));
        $tracking = $this->normalizeTracking((string) ($values[1] ?? ''));
        if ($tracking === '') {
            return ['ok' => false, 'message' => '运单号为空。'];
        }

        $qty = $this->intValue($values[2] ?? '');
        $weight = $this->cleanScalar($values[3] ?? '');
        $fee = $this->cleanScalar($values[4] ?? '');
        $assignee = $this->cleanScalar($values[5] ?? '');
        $outStatus = $this->cleanScalar($values[6] ?? '');
        $warehouseId = $this->cleanScalar($values[7] ?? '');

        $changes = array_filter([
            'intl_number' => $tracking,
            'ship_quantity' => $qty,
            'intl_qty' => $qty,
            'weight' => $weight,
            'intl_weight' => $weight,
            'com_amount' => $fee,
            'intl_fee' => $fee,
            'assignee' => $assignee,
            'out_status' => $outStatus,
            'jp_warehouse_id' => $warehouseId,
        ], static fn (mixed $value): bool => $value !== '' && $value !== 0 && $value !== null);

        return [
            'ok' => true,
            'record' => [
                'row' => $rowNumber,
                'match_tracking' => $tracking,
                'identity' => [
                    'platform_order_id' => $orderId,
                ],
                'changes' => $changes,
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function trackingIndex(array $orders): array
    {
        $index = [];
        foreach ($orders as $order) {
            foreach (array_values(array_filter($order['items'] ?? [], 'is_array')) as $item) {
                foreach (['intl_number', 'ship_number'] as $field) {
                    foreach ($this->trackingTokens((string) ($item[$field] ?? '')) as $tracking) {
                        $index[$tracking][] = [
                            'order_id' => (int) ($order['id'] ?? 0),
                            'platform_order_id' => (string) ($order['platform_order_id'] ?? ''),
                            'item_id' => (int) ($item['id'] ?? 0),
                            'item_code' => (string) (($item['item_code'] ?? '') ?: ($item['lot_number'] ?? '')),
                        ];
                    }
                }
            }
        }

        return $index;
    }

    /** @return array<int, string> */
    private function trackingTokens(string $value): array
    {
        $value = $this->normalizeTracking($value);
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    public function normalizeTracking(string $tracking): string
    {
        $tracking = trim($tracking);
        $tracking = preg_replace('/[^a-zA-Z0-9\-_\,]+/', ',', $tracking) ?? $tracking;
        $tracking = preg_replace('/,+/', ',', $tracking) ?? $tracking;

        return trim($tracking, ',');
    }

    /** @param array<int|string, mixed> $row */
    private function looksLikeHeader(array $row): bool
    {
        $joined = strtolower(implode(' ', array_map('strval', $this->rowValues($row))));

        return str_contains($joined, 'yd')
            || str_contains($joined, 'ship')
            || str_contains($joined, '运单')
            || str_contains($joined, '重量');
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

    private function intValue(mixed $value): int
    {
        $value = str_replace([',', ' '], '', trim((string) $value));
        return is_numeric($value) ? (int) $value : 0;
    }
}
