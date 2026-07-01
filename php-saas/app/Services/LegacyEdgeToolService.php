<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class LegacyEdgeToolService
{
    public function __construct(private readonly StoreInterface $store)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array{name: string, filename: string, headers: array<int, string>, rows: array<int, array<int, mixed>>}
     */
    public function brushOrderDataset(string $tenantKey, array $orders): array
    {
        $headers = ['平台', '店铺', '订单号', '订单时间', '收件人', '商品ID', '商品名', '数量', '单价', '状态', '备注'];
        $rows = [];
        foreach ($orders as $order) {
            foreach (array_values(array_filter($order['items'] ?? [], 'is_array')) as $item) {
                if (!$this->isBrushOrder($order, $item)) {
                    continue;
                }
                $rows[] = array_map($this->safeCell(...), [
                    (string) ($order['platform'] ?? ''),
                    (string) ($order['store'] ?? ''),
                    (string) ($order['platform_order_id'] ?? ''),
                    (string) ($order['order_date'] ?? ''),
                    (string) ($order['customer']['name'] ?? ''),
                    (string) (($item['item_code'] ?? '') ?: ($item['lot_number'] ?? '')),
                    (string) ($item['title'] ?? ''),
                    (int) ($item['quantity'] ?? 1),
                    (string) (($item['unit_price'] ?? '') ?: ($item['amount'] ?? '')),
                    (string) (($item['purchase_status'] ?? '') ?: ($order['status'] ?? '')),
                    (string) (($item['comment'] ?? '') ?: ($item['tranship_comment'] ?? '')),
                ]);
            }
        }

        return [
            'name' => '刷单订单 CSV',
            'filename' => 'brush-orders-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $tenantKey) . '-' . date('Ymd-His') . '.csv',
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    /**
     * @param array<int, array<int|string, mixed>> $rows
     * @return array{row_count: int, inserted: int, errors: array<int, string>, records: array<int, array<string, mixed>>, preview: array<int, array<string, mixed>>}
     */
    public function parseExternalInsertRows(array $rows): array
    {
        $records = [];
        $errors = [];
        $rowNumber = 0;
        $skippedHeader = false;
        foreach ($rows as $row) {
            $rowNumber++;
            if ($rowNumber === 1 && $this->looksLikeHeader($row)) {
                $skippedHeader = true;
                continue;
            }

            $values = array_values($row);
            if (count($values) < 3) {
                $errors[] = '第 ' . $rowNumber . ' 行：至少需要平台、订单号、运单号 3 列。';
                continue;
            }
            $platform = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($values[0] ?? '')) ?: 'external';
            $orderNo = trim((string) ($values[1] ?? ''));
            $tracking = $this->normalizeTracking((string) ($values[2] ?? ''));
            if ($orderNo === '' || $tracking === '') {
                $errors[] = '第 ' . $rowNumber . ' 行：订单号或运单号为空。';
                continue;
            }

            $records[] = [
                'row' => $rowNumber,
                'platform' => $platform,
                'platform_order_id' => $orderNo,
                'tracking' => $tracking,
                'customer_name' => trim((string) ($values[3] ?? '')),
                'phone' => trim((string) ($values[4] ?? '')),
                'address' => trim((string) ($values[5] ?? '')),
                'item_code' => trim((string) ($values[6] ?? '')),
                'quantity' => max(1, (int) ($values[7] ?? 1)),
            ];
        }

        return [
            'row_count' => max(0, $rowNumber - ($skippedHeader ? 1 : 0)),
            'inserted' => count($records),
            'errors' => array_slice($errors, 0, 50),
            'records' => $records,
            'preview' => array_slice($records, 0, 5),
        ];
    }

    /** @return array<int, array<string, string>> */
    public function logisticsFallbackProviders(): array
    {
        $settings = $this->store->globalSettings();
        $showapi = is_array($settings['showapi'] ?? null) ? $settings['showapi'] : [];

        return [
            [
                'name' => 'ShowAPI',
                'status' => !empty($showapi['enabled']) && trim((string) ($showapi['app_id'] ?? '')) !== '' ? 'configured' : 'missing_config',
                'note' => '主查询通道，密钥只允许从全局设置或环境变量配置。',
            ],
            [
                'name' => 'Baidu',
                'status' => 'placeholder',
                'note' => '旧系统备用查询方案仅保留降级入口；未复制旧密钥或代理。',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function componentIniReport(string $legacyRoot): array
    {
        $files = [];
        foreach (['component.ini', 'component-ini.php', 'component_ini.php'] as $name) {
            $path = rtrim($legacyRoot, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (is_file($path)) {
                $files[] = str_replace('\\', '/', $path);
            }
        }

        return [
            'needed' => $files !== [],
            'files' => $files,
            'decision' => $files ? '发现旧组件配置，建议后续按租户功能开关重建，不直接迁移动态 include。' : '未发现仍需迁移的 component-ini 文件。',
        ];
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $item */
    private function isBrushOrder(array $order, array $item): bool
    {
        $text = implode(' ', [
            (string) ($order['status'] ?? ''),
            (string) ($item['purchase_status'] ?? ''),
            (string) ($item['comment'] ?? ''),
            (string) ($item['tranship_comment'] ?? ''),
        ]);

        return str_contains($text, '刷单');
    }

    /** @param array<int|string, mixed> $row */
    private function looksLikeHeader(array $row): bool
    {
        $joined = strtolower(implode(' ', array_map('strval', array_values($row))));

        return str_contains($joined, 'platform')
            || str_contains($joined, 'order')
            || str_contains($joined, '运单')
            || str_contains($joined, '订单');
    }

    private function normalizeTracking(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9\-_\,]+/', ',', trim($value)) ?? $value;
        $value = preg_replace('/,+/', ',', $value) ?? $value;

        return trim($value, ',');
    }

    private function safeCell(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : $value;
    }
}
