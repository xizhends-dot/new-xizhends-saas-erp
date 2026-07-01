<?php

declare(strict_types=1);

namespace Xizhen\Services;

final class FinanceExportRequirementService
{
    /** @return array<int, array{item: string, reason: string, old_source: string}> */
    public function excelRequirements(): array
    {
        return [
            [
                'item' => '内嵌订单图片/采购证据图片',
                'reason' => 'CSV 只能放图片 URL 或路径，不能把图片作为单元格对象嵌入。',
                'old_source' => 'old/*/outcwexcel.php、old/orderr/outcwexcel-weier.php',
            ],
            [
                'item' => '行高、列宽、图片缩放、单元格样式',
                'reason' => '需要 xls/xlsx writer 才能稳定生成样式。',
                'old_source' => 'old/*/outcwexcel.php',
            ],
            [
                'item' => 'Excel 公式和受控数字格式',
                'reason' => 'CSV 可保留计算后的数值；公式、数字格式需电子表格库。',
                'old_source' => 'old/*/outcwexcel.php',
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array{name: string, filename: string, headers: array<int, string>, rows: array<int, array<int, mixed>>, source: string, note: string}
     */
    public function csvPlaceholderDataset(string $tenantKey, array $orders): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
            foreach (array_values(array_filter($order['items'] ?? [], 'is_array')) as $item) {
                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                $unitPrice = $this->money($item['unit_price'] ?? 0);
                $rows[] = $this->safeRow([
                    (string) ($order['platform_order_id'] ?? ''),
                    (string) (($item['item_code'] ?? '') ?: ($item['lot_number'] ?? '')),
                    (string) (($item['sku_image'] ?? '') ?: (($item['main_image'] ?? '') ?: ($item['image'] ?? ''))),
                    $quantity,
                    (string) ($customer['name'] ?? ''),
                    (string) ($customer['address'] ?? ''),
                    (string) (($item['intl_number'] ?? '') ?: ($item['ship_number'] ?? '')),
                    (string) (($item['intl_fee'] ?? '') ?: ($item['com_amount'] ?? '')),
                    (string) ($item['amount'] ?? ''),
                    $unitPrice,
                    $unitPrice * $quantity,
                    (string) ($item['postage_price'] ?? ''),
                    (string) ($item['cn_amount'] ?? ''),
                    (string) ($item['purchase_status'] ?? ''),
                ]);
            }
        }

        $today = date('Ymd-His');

        return [
            'name' => '财务图片样式表占位 CSV',
            'filename' => "finance-images-placeholder-{$tenantKey}-{$today}.csv",
            'headers' => [
                '订单号',
                '产品编码',
                '图片路径',
                '数量',
                '客户姓名',
                '地址',
                '国际单号',
                '国际运费',
                '采购价格',
                '产品单价',
                '产品总价',
                '产品运费',
                '利润/旧cnamount',
                '采购状态',
            ],
            'rows' => $rows,
            'source' => 'old/*/outcwexcel.php',
            'note' => '这里只输出 CSV 占位结构；图片嵌入和 Excel 样式需用户确认后引入电子表格库。',
        ];
    }

    private function money(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $normalized = str_replace([',', '¥', '￥', '円', ' '], '', (string) $value);
        return is_numeric($normalized) ? (float) $normalized : 0.0;
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
