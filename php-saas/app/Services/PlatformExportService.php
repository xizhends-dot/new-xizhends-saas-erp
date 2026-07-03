<?php

declare(strict_types=1);

namespace Xizhen\Services;

/**
 * 发货单导出统一渲染引擎:模板(预置或租户自定义) + 订单集 → headers/rows。
 * 字段取值逻辑全部在 ExportFieldRegistry;本类只做列遍历、raw 路径、CSV 防注入。
 */
final class PlatformExportService
{
    /**
     * @param array<string, mixed> $template
     * @param array<int, array<string, mixed>> $orders
     * @return array{name: string, filename: string, format: string, headers: array<int, string>, rows: array<int, array<int, mixed>>, imageColumns: array<int, int>}
     */
    public function render(array $template, array $orders): array
    {
        $columns = array_values(array_filter((array) ($template['columns'] ?? []), 'is_array'));
        $format = strtolower((string) ($template['format'] ?? 'csv')) === 'xlsx' ? 'xlsx' : 'csv';
        $fields = ExportFieldRegistry::fields();

        $headers = [];
        $imageColumns = [];
        foreach ($columns as $index => $column) {
            $headers[] = (string) ($column['label'] ?? '');
            if (($column['type'] ?? '') === 'field' && ($fields[(string) ($column['key'] ?? '')]['type'] ?? '') === 'image') {
                $imageColumns[] = $index;
            }
        }

        $rows = [];
        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }
            foreach (array_filter((array) ($order['items'] ?? []), 'is_array') as $item) {
                $row = [];
                foreach ($columns as $column) {
                    $row[] = $this->cellValue($column, $order, $item);
                }
                $rows[] = $row;
            }
        }

        return [
            'name' => (string) ($template['name'] ?? '发货单导出'),
            'filename' => 'shipping-' . date('Ymd-His') . '.' . $format,
            'format' => $format,
            'headers' => $headers,
            'rows' => $this->safeRows($rows),
            'imageColumns' => $imageColumns,
        ];
    }

    /**
     * @param array<string, mixed> $column
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     */
    private function cellValue(array $column, array $order, array $item): mixed
    {
        return match ((string) ($column['type'] ?? '')) {
            'field' => ExportFieldRegistry::resolve((string) ($column['key'] ?? ''), $order, $item),
            'const' => (string) ($column['value'] ?? ''),
            'raw' => $this->rawValue((string) ($column['path'] ?? ''), $order, $item),
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     */
    private function rawValue(string $path, array $order, array $item): string
    {
        $value = null;
        if (str_starts_with($path, 'order.')) {
            $value = $order[substr($path, 6)] ?? null;
        } elseif (str_starts_with($path, 'item.')) {
            $value = $item[substr($path, 5)] ?? null;
        } elseif (str_starts_with($path, 'customer.')) {
            $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
            $value = $customer[substr($path, 9)] ?? null;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /** @param array<int, array<int, mixed>> $rows @return array<int, array<int, mixed>> */
    private function safeRows(array $rows): array
    {
        return array_map(
            fn (array $row): array => array_map(fn (mixed $cell): mixed => $this->safeCell($cell), $row),
            $rows
        );
    }

    private function safeCell(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : $value;
    }
}
