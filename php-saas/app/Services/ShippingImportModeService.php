<?php

declare(strict_types=1);

namespace Xizhen\Services;

final class ShippingImportModeService
{
    /**
     * @param array<int, array<int|string, mixed>> $rows
     * @return array{row_count: int, records: array<int, array<string, mixed>>, preview: array<int, array<string, mixed>>, errors: array<int, string>, modes: array<string, int>}
     */
    public function parseRows(array $rows): array
    {
        $records = [];
        $preview = [];
        $errors = [];
        $modes = ['append' => 0, 'replace' => 0, 'fuzzy_update' => 0, 'exact_update' => 0];
        $rowNumber = 0;
        $skippedHeader = false;

        foreach ($rows as $row) {
            $rowNumber++;
            if ($rowNumber === 1 && $this->looksLikeHeader($row)) {
                $skippedHeader = true;
                continue;
            }

            $parsed = $this->shippingRow($row, $rowNumber);
            if (!$parsed['ok']) {
                $errors[] = '第 ' . $rowNumber . ' 行：' . $parsed['message'];
                continue;
            }

            $record = $parsed['record'];
            $mode = (string) ($record['mode'] ?? 'append');
            if (isset($modes[$mode])) {
                $modes[$mode]++;
            }
            $records[] = $record;
            if (count($preview) < 5) {
                $preview[] = $record;
            }
        }

        return [
            'row_count' => max(0, $rowNumber - ($skippedHeader ? 1 : 0)),
            'records' => $records,
            'preview' => $preview,
            'errors' => array_slice($errors, 0, 50),
            'modes' => $modes,
        ];
    }

    public function mergeTracking(string $current, string $incoming, bool $replace): string
    {
        $incoming = $this->normalizeTracking($incoming);
        if ($replace || trim($current) === '') {
            return $incoming;
        }

        $numbers = array_values(array_filter(array_map('trim', explode(',', $this->normalizeTracking($current)))));
        foreach (array_values(array_filter(array_map('trim', explode(',', $incoming)))) as $number) {
            if ($number !== '' && !in_array($number, $numbers, true)) {
                $numbers[] = $number;
            }
        }

        return implode(',', $numbers);
    }

    /**
     * @param array<int|string, mixed> $row
     * @return array{ok: bool, message?: string, record?: array<string, mixed>}
     */
    private function shippingRow(array $row, int $rowNumber): array
    {
        $values = $this->rowValues($row);
        $count = count($values);
        if (!in_array($count, [2, 3, 6, 7], true)) {
            return ['ok' => false, 'message' => '国际运单导入只支持 2、3、6、7 列格式。'];
        }

        $orderId = trim((string) ($values[0] ?? ''));
        $tracking = $this->normalizeTracking((string) ($values[1] ?? ''));
        if ($tracking === '' || ($count <= 3 && $orderId === '')) {
            return ['ok' => false, 'message' => '缺少订单号或国际运单号。'];
        }

        if ($count <= 3) {
            $replace = $count === 3 && trim((string) ($values[2] ?? '')) === '1';

            return [
                'ok' => true,
                'record' => [
                    'row' => $rowNumber,
                    'mode' => $replace ? 'replace' : 'append',
                    'identity' => [
                        'platform_order_id' => $orderId,
                    ],
                    'changes' => [
                        'intl_number' => $tracking,
                        'reset_tracking' => $replace,
                    ],
                ],
            ];
        }

        $changes = array_filter([
            'intl_number' => $tracking,
            'ship_quantity' => $this->intValue($values[2] ?? ''),
            'weight' => $this->cleanScalar($values[3] ?? ''),
            'com_amount' => $this->cleanScalar($values[4] ?? ''),
            'purchase_status' => $this->cleanScalar($values[5] ?? ''),
        ], static fn (mixed $value): bool => $value !== '' && $value !== 0 && $value !== null);

        $exactOnly = $count === 7 && trim((string) ($values[6] ?? '')) === '1';
        if ($exactOnly) {
            $changes['reset_tracking'] = true;
        }

        return [
            'ok' => true,
            'record' => [
                'row' => $rowNumber,
                'mode' => $exactOnly ? 'exact_update' : 'fuzzy_update',
                'match_tracking' => $tracking,
                'identity' => [
                    'platform_order_id' => $orderId,
                ],
                'changes' => $changes,
            ],
        ];
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

        return str_contains($joined, 'order')
            || str_contains($joined, 'ship')
            || str_contains($joined, '运单')
            || str_contains($joined, '订单');
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
