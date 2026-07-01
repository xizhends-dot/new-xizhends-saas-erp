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
                '',
                $this->address1($customer),
                $this->address2($customer),
                (string) ($customer['city'] ?? ''),
                (string) ($customer['prefecture'] ?? ''),
                $this->zip((string) ($customer['zip'] ?? '')),
                $this->phone((string) ($customer['phone'] ?? '')),
                (string) ($customer['mail'] ?? ''),
                (string) (($order['order_date'] ?? '') ?: ($order['imported_at'] ?? '')),
            ]);
        }

        $today = date('Ymd-His');

        return [
            'name' => '客户资料 CSV',
            'filename' => "customers-legacy-{$tenantKey}-{$today}.csv",
            'headers' => $this->headers(),
            'rows' => $rows,
            'source' => 'old/*/custinfo_export.php',
            'note' => '只迁移 CSV 结构；冻结首行、列宽、保护工作表等 Excel 样式需用户确认引库。',
        ];
    }

    /** @return array<int, string> */
    public function headers(): array
    {
        return [
            'ShipName',
            'ShipName1',
            'ShipAddress1',
            'ShipAddress2',
            'ShipCity',
            'ShipPrefecture',
            'ShipZipCode',
            'ShipPhoneNumber',
            'BillMailAddress',
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
    private function address1(array $customer): string
    {
        return (string) (($customer['address1'] ?? '') ?: ($customer['address'] ?? ''));
    }

    /** @param array<string, mixed> $customer */
    private function address2(array $customer): string
    {
        $address1 = (string) (($customer['address1'] ?? '') ?: ($customer['address'] ?? ''));
        $address2 = (string) ($customer['address2'] ?? '');

        return $address2 !== '' ? $address1 . ',' . $address2 : $address1;
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
