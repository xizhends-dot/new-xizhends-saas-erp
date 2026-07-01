<?php

declare(strict_types=1);

namespace Xizhen\Services;

final class CustomerExportService
{
    /**
     * @param array<int, array<string, mixed>> $orders
     * @param array<string, mixed> $criteria
     * @return array{name: string, filename: string, headers: array<int, string>, rows: array<int, array<int, mixed>>, source: string, note: string}
     */
    public function exportDataset(string $tenantKey, array $orders, array $criteria = []): array
    {
        $rows = [];
        $seen = [];
        foreach ($orders as $order) {
            if (!$this->orderInDateRange($order, (string) ($criteria['date_from'] ?? ''), (string) ($criteria['date_to'] ?? ''))) {
                continue;
            }

            $orderNo = (string) ($order['platform_order_id'] ?? '');
            if ($orderNo !== '' && isset($seen[$orderNo])) {
                continue;
            }
            if ($orderNo !== '') {
                $seen[$orderNo] = true;
            }

            $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
            $rows[] = $this->safeRow([
                (string) ($customer['name'] ?? ''),
                (string) ($customer['kana'] ?? ''),
                $this->address($customer),
                $this->zip((string) ($customer['zip'] ?? '')),
                $this->phone((string) ($customer['phone'] ?? '')),
                (string) ($customer['mail'] ?? ''),
                (string) (($order['order_date'] ?? '') ?: ($order['imported_at'] ?? '')),
            ]);
        }

        $today = date('Ymd-His');

        return [
            'name' => '客户资料',
            'filename' => "customers-legacy-{$tenantKey}-{$today}.xlsx",
            'headers' => $this->headers(),
            'rows' => $rows,
            'source' => 'old/*/custinfo_export.php',
            'note' => '按旧 custinfo_export.php 输出样式化 XLSX。',
        ];
    }

    /** @return array<int, string> */
    public function headers(): array
    {
        return [
            'senderName',
            'senderKana',
            'senderAddress',
            'senderZipCode',
            'senderPhoneNumber1',
            'mailAddress',
            'cdate',
        ];
    }

    /** @return array<int, string> */
    public function excelRequirements(): array
    {
        return [
            'PHPExcel/Spreadsheet writer，用于输出真正的 xls/xlsx。',
            '冻结 A2 首行。',
            '列宽：姓名 15，地址 25，邮箱 20，日期 10。',
            '表头加粗居中、灰底、边框。',
            '工作表保护和数字列格式。',
        ];
    }

    /** @param array<string, mixed> $order */
    private function orderInDateRange(array $order, string $from, string $to): bool
    {
        $date = $this->dateOnly((string) (($order['order_date'] ?? '') ?: ($order['imported_at'] ?? '')));
        $from = $this->dateOnly($from);
        $to = $this->dateOnly($to);
        if ($from === '' && $to === '') {
            return true;
        }
        if ($date === '') {
            return false;
        }

        return ($from === '' || $date >= $from) && ($to === '' || $date <= $to);
    }

    private function dateOnly(string $value): string
    {
        $value = trim(str_replace('/', '-', $value));
        if (!preg_match('/^\d{4}-\d{1,2}-\d{1,2}/', $value)) {
            return '';
        }

        $time = strtotime($value);
        return $time === false ? '' : date('Y-m-d', $time);
    }

    /** @param array<string, mixed> $customer */
    /** @param array<string, mixed> $customer */
    private function address(array $customer): string
    {
        $parts = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            [
                $customer['prefecture'] ?? '',
                $customer['city'] ?? '',
                $customer['address1'] ?? '',
                $customer['address2'] ?? '',
            ]
        )));

        return $parts ? implode('', $parts) : (string) ($customer['address'] ?? '');
    }

    private function phone(string $value): string
    {
        $value = trim($value);
        if ($value !== '' && !str_contains($value, '-') && !str_starts_with($value, '0')) {
            return '0' . $value;
        }

        return $value;
    }

    private function zip(string $value): string
    {
        $value = trim($value);
        if ($value !== '' && !str_contains($value, '-') && strlen($value) !== 7) {
            return str_pad($value, 7, '0', STR_PAD_LEFT);
        }

        return $value;
    }

    /** @param array<int, mixed> $row @return array<int, mixed> */
    private function safeRow(array $row): array
    {
        return array_map(fn (mixed $cell): mixed => $this->safeCell($cell), $row);
    }

    private function safeCell(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : $value;
    }
}
