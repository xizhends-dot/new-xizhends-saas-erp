<?php

declare(strict_types=1);

namespace Xizhen\Services;

final class PlatformExportService
{
    /** @var array<string, array{name: string, source: string, platform: string, note: string}> */
    private const VARIANTS = [
        'riya' => [
            'name' => '日亚发货单 CSV',
            'source' => 'old/*/outexcel-riya.php',
            'platform' => 'y',
            'note' => '保留旧表头和字段顺序；图片列输出图片 URL/路径，不嵌入图片。',
        ],
        'sx' => [
            'name' => '盛欣发货单 CSV',
            'source' => 'old/ordery/outexcel-sx.php',
            'platform' => 'y',
            'note' => '旧版 HTML Excel 的合并单元格改为每行重复订单号。',
        ],
        'wd' => [
            'name' => '万达发货单 CSV',
            'source' => 'old/ordery/outexcel-wd.php',
            'platform' => 'y',
            'note' => '字段与盛欣模板一致，CSV 不保留单元格颜色。',
        ],
        'qoo10' => [
            'name' => 'Qoo10 出荷表 CSV',
            'source' => 'old/orderq/outexcel_qoo10.php',
            'platform' => 'q',
            'note' => '按 Qoo10 订购号码、配送会社、送り状番号、国家列输出。',
        ],
        'wowma' => [
            'name' => 'Wowma 出荷表 CSV',
            'source' => 'old/orderw/outexcel_wowma.php',
            'platform' => 'w',
            'note' => '保留 Wowma 更新列；shippingCarrier 使用现有物流公司或运单前缀推断值。',
        ],
    ];

    /** @return array<string, array{name: string, source: string, platform: string, note: string}> */
    public function variants(): array
    {
        return self::VARIANTS;
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @param array<string, mixed> $options
     * @return array{name: string, filename: string, headers: array<int, string>, rows: array<int, array<int, mixed>>, source: string, note: string}
     */
    public function exportDataset(string $tenantKey, string $variant, array $orders, array $options = []): array
    {
        $variant = strtolower(trim($variant));
        if (!isset(self::VARIANTS[$variant])) {
            $variant = 'riya';
        }

        $meta = self::VARIANTS[$variant];
        $today = date('Ymd-His');
        $orders = $this->filterOrdersByVariantPlatform($orders, $meta['platform'], (bool) ($options['strict_platform'] ?? false));
        [$headers, $rows] = match ($variant) {
            'sx', 'wd' => $this->sxRows($orders),
            'qoo10' => $this->qoo10Rows($orders),
            'wowma' => $this->wowmaRows($orders),
            default => $this->riyaRows($orders),
        };

        return [
            'name' => $meta['name'],
            'filename' => "{$variant}-{$tenantKey}-{$today}.csv",
            'headers' => $headers,
            'rows' => $this->safeRows($rows),
            'source' => $meta['source'],
            'note' => $meta['note'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    private function riyaRows(array $orders): array
    {
        $headers = [
            '日期',
            '国内单号',
            '产品图片',
            '渠道名称（普货/带电）',
            '备注1',
            '备注2',
            '订单号',
            '佐川单号',
            '发件公司名英文',
            '发件公司电话',
            '发件公司地址',
            '收件电话',
            '收件人',
            '收件人2',
            '收件人邮编',
            '收件人地址',
            '重量',
            '长（CM）',
            '宽（CM）',
            '高（CM）',
            '申报商品数量',
            '申报币种',
            '第一品名（英文）',
            '材质（英文）',
            '数量',
            '单价（USD）',
        ];

        $rows = [];
        foreach ($orders as $order) {
            $customer = $this->customer($order);
            foreach ($this->items($order) as $item) {
                $rows[] = [
                    date('m-d'),
                    $this->domesticTracking($item),
                    $this->image($item),
                    '',
                    (string) ($item['option'] ?? ''),
                    (string) ($item['comment'] ?? ''),
                    (string) ($order['platform_order_id'] ?? ''),
                    '',
                    '',
                    '',
                    '',
                    $this->phone((string) ($customer['phone'] ?? '')),
                    (string) ($customer['name'] ?? ''),
                    '',
                    $this->zip((string) ($customer['zip'] ?? '')),
                    $this->address($customer),
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    $this->quantity($item),
                    $this->usdUnitPrice($item),
                ];
            }
        }

        return [$headers, $rows];
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    private function sxRows(array $orders): array
    {
        $headers = [
            '日期',
            '订单号',
            '派送单号',
            '重量',
            '收货人',
            '收货人电话',
            '收货人地址',
            '收货人邮编',
            '国内快递单号',
            '图片',
            '数量',
            '品名',
            '颜色',
            '备注',
            '西阵电商公司备注',
        ];

        $rows = [];
        foreach ($orders as $order) {
            $customer = $this->customer($order);
            foreach ($this->items($order) as $item) {
                $rows[] = [
                    '',
                    (string) ($order['platform_order_id'] ?? ''),
                    '',
                    (string) ($item['weight'] ?? ''),
                    (string) ($customer['name'] ?? ''),
                    $this->phone((string) ($customer['phone'] ?? '')),
                    $this->address($customer),
                    $this->zip((string) ($customer['zip'] ?? '')),
                    $this->domesticTracking($item),
                    $this->image($item),
                    $this->quantity($item),
                    (string) ($item['title'] ?? ''),
                    (string) (($item['chinese_option'] ?? '') ?: ($item['option'] ?? '')),
                    (string) ($item['tranship_comment'] ?? ''),
                    (string) ($item['comment'] ?? ''),
                ];
            }
        }

        return [$headers, $rows];
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    private function qoo10Rows(array $orders): array
    {
        $headers = ['订购号码', '运送公司', '运送单号', '订购国家'];
        $rows = [];
        foreach ($orders as $order) {
            foreach ($this->items($order) as $item) {
                $rows[] = [
                    (string) (($item['order_detail_id'] ?? '') ?: ($order['platform_order_id'] ?? '')),
                    (string) (($item['ship_company'] ?? '') ?: ($order['ship_method'] ?? '')),
                    $this->internationalTracking($item),
                    'JP',
                ];
            }
        }

        return [$headers, $rows];
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    private function wowmaRows(array $orders): array
    {
        $headers = [
            'controlType',
            'orderId',
            'orderStatus',
            'printStatus',
            'shipStatus',
            'shippingDate',
            'shippingCarrier',
            'shippingNumber',
            '国际运单状态（需删除）',
            '店铺名（需删除）',
            '订单时间',
        ];

        $rows = [];
        foreach ($orders as $order) {
            foreach ($this->items($order) as $item) {
                $tracking = $this->internationalTracking($item);
                $rows[] = [
                    'U',
                    (string) ($order['platform_order_id'] ?? ''),
                    'Finish_send',
                    'Y',
                    'Y',
                    date('Y/m/d'),
                    $this->carrierCode($tracking, (string) ($item['ship_company'] ?? '')),
                    $tracking,
                    (string) (($item['intl_status'] ?? '') ?: ($item['logistics'] ?? '')),
                    (string) ($order['store'] ?? ''),
                    (string) ($order['order_date'] ?? ''),
                ];
            }
        }

        return [$headers, $rows];
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private function filterOrdersByVariantPlatform(array $orders, string $platform, bool $strict): array
    {
        if (!$strict || $platform === '') {
            return $orders;
        }

        return array_values(array_filter(
            $orders,
            static fn (array $order): bool => (string) ($order['platform'] ?? '') === $platform
        ));
    }

    /** @param array<string, mixed> $order @return array<string, mixed> */
    private function customer(array $order): array
    {
        return is_array($order['customer'] ?? null) ? $order['customer'] : [];
    }

    /** @param array<string, mixed> $order @return array<int, array<string, mixed>> */
    private function items(array $order): array
    {
        return array_values(array_filter($order['items'] ?? [], 'is_array'));
    }

    /** @param array<string, mixed> $customer */
    private function address(array $customer): string
    {
        $parts = array_filter([
            (string) ($customer['prefecture'] ?? ''),
            (string) ($customer['city'] ?? ''),
            (string) ($customer['address1'] ?? ''),
            (string) ($customer['address2'] ?? ''),
        ], static fn (string $value): bool => trim($value) !== '');

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

    /** @param array<string, mixed> $item */
    private function domesticTracking(array $item): string
    {
        return trim(implode(' ', array_filter([
            (string) ($item['ship_company'] ?? ''),
            (string) ($item['ship_number'] ?? ''),
        ], static fn (string $value): bool => trim($value) !== '')));
    }

    /** @param array<string, mixed> $item */
    private function internationalTracking(array $item): string
    {
        return trim((string) (($item['intl_number'] ?? '') ?: ($item['ship_number'] ?? '')));
    }

    /** @param array<string, mixed> $item */
    private function image(array $item): string
    {
        return (string) (($item['sku_image'] ?? '') ?: (($item['main_image'] ?? '') ?: ($item['image'] ?? '')));
    }

    /** @param array<string, mixed> $item */
    private function quantity(array $item): int
    {
        return max(1, (int) ($item['quantity'] ?? 1));
    }

    /** @param array<string, mixed> $item */
    private function usdUnitPrice(array $item): string
    {
        $price = $this->money((string) ($item['unit_price'] ?? '0'));
        if ($price <= 0) {
            return '';
        }

        return (string) round($price / 2 / 145, 2);
    }

    private function carrierCode(string $tracking, string $fallback): string
    {
        $tracking = trim($tracking);
        if ($tracking === '') {
            return $fallback;
        }

        $prefixMap = [
            '368' => '1',
            '28' => '1',
            '654' => '1',
            '597' => '1',
            '763' => '1',
            '766' => '1',
            '281' => '1',
            '44' => '1',
            '47' => '1',
            '361' => '2',
            '35' => '2',
            '01' => '2',
            '51' => '2',
            '56' => '2',
            '32' => '6',
            '42' => '6',
            '52' => '6',
            '82' => '6',
            '48' => '1',
            '37' => '1',
            '36' => '2',
            '39' => '1',
        ];
        foreach ($prefixMap as $prefix => $code) {
            if (str_starts_with($tracking, (string) $prefix)) {
                return $code;
            }
        }

        return $fallback;
    }

    private function money(string $value): float
    {
        $value = str_replace([',', '¥', '￥', '円', ' '], '', trim($value));
        return is_numeric($value) ? (float) $value : 0.0;
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
