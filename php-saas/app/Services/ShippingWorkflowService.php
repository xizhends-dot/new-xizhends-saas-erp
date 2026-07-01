<?php

declare(strict_types=1);

namespace Xizhen\Services;

final class ShippingWorkflowService
{
    public const STATUS_READY_FOR_JP = '已到货';
    public const STATUS_SENT_TO_JP = '已发日本';
    public const ACTION_SEND_JP = '批量设置已发日本';

    private const DELIVERY_HEADERS = [
        '店铺番号',
        '订单编号',
        '订单日期',
        '产品ID',
        '产品信息',
        '变量1',
        '变量2',
        '数量',
        '送付先 氏名',
        '郵便番号',
        '住所',
        '箱号',
        '信封大小',
        '黑猫配送方式',
        '客人邮箱',
        '电话号码',
    ];

    /**
     * @param array<int, array<string, mixed>> $orders Already tenant-scoped and access-filtered orders.
     * @param array<int, int|string> $itemIds
     * @param array<int, int|string> $orderIds
     * @return array{
     *     item_ids: array<int, int>,
     *     order_ids: array<int, int>,
     *     selected: int,
     *     eligible: int,
     *     skipped: int,
     *     skipped_by_status: array<string, int>,
     *     target_status: string,
     *     action: string
     * }
     */
    public function planSendJapan(
        string $tenantKey,
        array $orders,
        array $itemIds = [],
        array $orderIds = [],
        bool $includeAllWhenNoSelection = false
    ): array {
        $empty = $this->emptySendJapanPlan();
        if (trim($tenantKey) === '') {
            return $empty;
        }

        $itemSet = $this->intSet($itemIds);
        $orderSet = $this->intSet($orderIds);
        if (!$itemSet && !$orderSet && !$includeAllWhenNoSelection) {
            return $empty;
        }

        $plannedItemIds = [];
        $plannedOrderIds = [];
        $skippedByStatus = [];
        $selected = 0;

        foreach ($orders as $order) {
            $orderId = (int) ($order['id'] ?? 0);
            $orderSelected = $includeAllWhenNoSelection || ($orderId > 0 && isset($orderSet[$orderId]));

            foreach ($this->orderItems($order) as $item) {
                $itemId = (int) ($item['id'] ?? 0);
                if ($itemId <= 0) {
                    continue;
                }
                if ($itemSet && !isset($itemSet[$itemId])) {
                    continue;
                }
                if (!$itemSet && !$orderSelected) {
                    continue;
                }

                $selected++;
                $status = trim((string) ($item['purchase_status'] ?? ''));
                if ($status !== self::STATUS_READY_FOR_JP) {
                    $label = $status !== '' ? $status : '(空)';
                    $skippedByStatus[$label] = ($skippedByStatus[$label] ?? 0) + 1;
                    continue;
                }

                $plannedItemIds[$itemId] = $itemId;
                if ($orderId > 0) {
                    $plannedOrderIds[$orderId] = $orderId;
                }
            }
        }

        return [
            'item_ids' => array_values($plannedItemIds),
            'order_ids' => array_values($plannedOrderIds),
            'selected' => $selected,
            'eligible' => count($plannedItemIds),
            'skipped' => array_sum($skippedByStatus),
            'skipped_by_status' => $skippedByStatus,
            'target_status' => self::STATUS_SENT_TO_JP,
            'action' => self::ACTION_SEND_JP,
        ];
    }

    /** @return array<string, string> */
    public function sendJapanChanges(): array
    {
        return ['purchase_status' => self::STATUS_SENT_TO_JP];
    }

    /**
     * @param array<int, array<string, mixed>> $orders Already tenant-scoped and access-filtered orders.
     * @param array<int, int|string> $itemIds
     * @param array<int, int|string> $orderIds
     * @return array{name: string, filename: string, headers: array<int, string>, rows: array<int, array<int, mixed>>}
     */
    public function xizhenDeliveryDataset(
        string $tenantKey,
        array $orders,
        string $platformCode = '',
        string $platformName = '',
        array $itemIds = [],
        array $orderIds = [],
        ?\DateTimeInterface $clock = null,
        bool $includeAllWhenNoSelection = false
    ): array {
        $platformCode = trim($platformCode);
        $platformName = trim($platformName) ?: $this->platformLabel($platformCode);
        $stamp = ($clock ?? new \DateTimeImmutable())->format('Ymd-His');
        $safeTenant = preg_replace('/[^a-zA-Z0-9_-]/', '', $tenantKey) ?: 'tenant';
        $safePlatform = preg_replace('/[^a-zA-Z0-9_-]/', '', $platformCode) ?: 'all';

        return [
            'name' => '西阵' . $platformName . '发货表',
            'filename' => "xizhen-delivery-{$safeTenant}-{$safePlatform}-{$stamp}.csv",
            'headers' => self::DELIVERY_HEADERS,
            'rows' => $this->xizhenDeliveryRows($orders, $itemIds, $orderIds, $includeAllWhenNoSelection),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @param array<int, int|string> $itemIds
     * @param array<int, int|string> $orderIds
     * @return array<int, array<int, mixed>>
     */
    public function xizhenDeliveryRows(
        array $orders,
        array $itemIds = [],
        array $orderIds = [],
        bool $includeAllWhenNoSelection = false
    ): array {
        $itemSet = $this->intSet($itemIds);
        $orderSet = $this->intSet($orderIds);
        if (!$itemSet && !$orderSet && !$includeAllWhenNoSelection) {
            return [];
        }

        $rows = [];
        foreach ($orders as $order) {
            $orderId = (int) ($order['id'] ?? 0);
            $orderSelected = $includeAllWhenNoSelection || ($orderId > 0 && isset($orderSet[$orderId]));
            foreach ($this->orderItems($order) as $item) {
                $itemId = (int) ($item['id'] ?? 0);
                if ($itemSet && !isset($itemSet[$itemId])) {
                    continue;
                }
                if (!$itemSet && !$orderSelected) {
                    continue;
                }

                $rows[] = $this->xizhenDeliveryRow($order, $item);
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     * @return array<int, mixed>
     */
    private function xizhenDeliveryRow(array $order, array $item): array
    {
        $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];

        return [
            $this->firstString([$order['store'] ?? '', $this->extraValue($order, ['dpquancheng'])]),
            $this->deliveryOrderNumber($order, $item),
            $this->firstString([$order['order_date'] ?? '', $this->extraValue($order, ['OrderTime', 'orderDate'])]),
            $this->deliveryProductId($order, $item),
            $this->deliveryProductInfo($order, $item),
            $this->deliveryVariableOne($order, $item),
            '',
            $this->positiveInt($item['quantity'] ?? $this->extraValue($item, ['Quantity', 'unit']), 1),
            $this->firstString([$customer['name'] ?? '', $this->extraValue($order, ['ShipName', 'senderName'])]),
            $this->firstString([$customer['zip'] ?? '', $this->extraValue($order, ['ShipZipCode', 'senderZipCode', 'shipping_postal_code'])]),
            $this->firstString([$customer['address'] ?? '', $this->legacyAddress($order)]),
            '',
            '',
            $this->firstString([$order['ship_method'] ?? '']),
            $this->firstString([$customer['mail'] ?? '', $this->extraValue($order, ['BillMailAddress', 'mailAddress'])]),
            $this->firstString([$customer['phone'] ?? '', $this->extraValue($order, ['ShipPhoneNumber', 'senderPhoneNumber1'])]),
        ];
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $item */
    private function deliveryOrderNumber(array $order, array $item): string
    {
        $platform = (string) ($order['platform'] ?? '');
        if ($platform === 'w') {
            return $this->firstString([
                $item['order_detail_id'] ?? '',
                $this->extraValue($item, ['orderDetailId']),
                $order['platform_order_id'] ?? '',
            ]);
        }

        return $this->firstString([
            $order['platform_order_id'] ?? '',
            $this->extraValue($order, ['OrderId', 'orderId']),
        ]);
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $item */
    private function deliveryProductId(array $order, array $item): string
    {
        $platform = (string) ($order['platform'] ?? '');
        $legacyKeys = match ($platform) {
            'r' => ['ItemManagerId', 'ItemId'],
            'w', 'q' => ['itemManagementId'],
            'm', 'yp' => ['itemCode'],
            'y' => ['ItemId'],
            default => ['ItemManagerId', 'ItemId', 'itemManagementId', 'itemCode'],
        };

        return $this->firstString([
            $item['item_management_id'] ?? '',
            $item['item_code'] ?? '',
            $item['lot_number'] ?? '',
            $this->extraValue($item, $legacyKeys),
        ]);
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $item */
    private function deliveryProductInfo(array $order, array $item): string
    {
        $platform = (string) ($order['platform'] ?? '');
        $legacyKeys = in_array($platform, ['r', 'y'], true)
            ? ['SubCodeOption', 'itemOption']
            : ['itemOption', 'SubCodeOption'];

        return $this->firstString([
            $this->extraValue($item, $legacyKeys),
            $item['option'] ?? '',
            $item['title'] ?? '',
        ]);
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $item */
    private function deliveryVariableOne(array $order, array $item): string
    {
        $platform = (string) ($order['platform'] ?? '');
        if ($platform === 'y') {
            return '';
        }
        if (in_array($platform, ['w', 'm', 'yp'], true)) {
            $parts = [];
            for ($i = 1; $i <= 5; $i++) {
                $value = $this->extraValue($item, ['itemOptionCommission' . $i]);
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
            return $this->firstString([implode(' ', $parts), $item['chinese_option'] ?? '']);
        }
        if ($platform === 'q') {
            return $this->firstString([$this->extraValue($item, ['itemOptionCommission1']), $item['chinese_option'] ?? '']);
        }

        return $this->firstString([
            $this->extraValue($item, ['selectedChoice']),
            $item['chinese_option'] ?? '',
        ]);
    }

    /** @param array<string, mixed> $order */
    private function legacyAddress(array $order): string
    {
        $parts = array_filter([
            $this->extraValue($order, ['ShipPrefecture', 'shipping_state']),
            $this->extraValue($order, ['ShipCity', 'shipping_city']),
            $this->extraValue($order, ['ShipAddress1', 'shipping_address_1', 'senderAddress']),
            $this->extraValue($order, ['ShipAddress2', 'shipping_address_2']),
        ], static fn (string $value): bool => $value !== '');

        return trim(implode(' ', $parts));
    }

    /** @return array<string, bool> */
    private function intSet(array $values): array
    {
        $set = [];
        foreach ($values as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $set[$id] = true;
            }
        }

        return $set;
    }

    /** @param array<string, mixed> $order @return array<int, array<string, mixed>> */
    private function orderItems(array $order): array
    {
        return is_array($order['items'] ?? null) ? $order['items'] : [];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function extraValue(array $row, array $keys): string
    {
        $extra = is_array($row['platform_extra'] ?? null) ? $row['platform_extra'] : [];
        foreach ($keys as $key) {
            if (isset($extra[$key]) && trim((string) $extra[$key]) !== '') {
                return trim((string) $extra[$key]);
            }
        }

        return '';
    }

    /** @param array<int, mixed> $values */
    private function firstString(array $values): string
    {
        foreach ($values as $value) {
            $string = trim((string) $value);
            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }

    private function positiveInt(mixed $value, int $default): int
    {
        $number = (int) $value;

        return $number > 0 ? $number : $default;
    }

    private function platformLabel(string $platformCode): string
    {
        return match ($platformCode) {
            'r' => 'Rakuten',
            'y' => 'Yahoo',
            'w' => 'Wowma',
            'm' => 'Mercari',
            'q' => 'Qoo10',
            'yp' => '雅虎拍卖',
            default => '订单',
        };
    }

    /**
     * @return array{
     *     item_ids: array<int, int>,
     *     order_ids: array<int, int>,
     *     selected: int,
     *     eligible: int,
     *     skipped: int,
     *     skipped_by_status: array<string, int>,
     *     target_status: string,
     *     action: string
     * }
     */
    private function emptySendJapanPlan(): array
    {
        return [
            'item_ids' => [],
            'order_ids' => [],
            'selected' => 0,
            'eligible' => 0,
            'skipped' => 0,
            'skipped_by_status' => [],
            'target_status' => self::STATUS_SENT_TO_JP,
            'action' => self::ACTION_SEND_JP,
        ];
    }
}
