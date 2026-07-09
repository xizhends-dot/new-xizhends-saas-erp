<?php

declare(strict_types=1);

namespace Xizhen\Services;

final class WowmaOrderSyncService extends AbstractPlatformOrderSyncService
{
    private const SEARCH_URL = 'https://api.manager.wowma.jp/wmshopapi/searchTradeInfoListProc';
    private const DEFAULT_TOTAL_COUNT = 500;
    private const MAX_TOTAL_COUNT = 500;

    public function platformCode(): string
    {
        return 'w';
    }

    public function platformName(): string
    {
        return 'Wowma';
    }

    /**
     * @param array<string, mixed> $options
     * @return array{ok: bool, message: string, searched: int, inserted: int, updated: int, skipped: int, items_inserted: int, items_updated: int}
     */
    public function sync(string $tenantKey, int $storeId, int $days, string $operator, array $options = []): array
    {
        $store = $this->store->store($tenantKey, $storeId);
        if (!$store || (string) ($store['platform'] ?? '') !== $this->platformCode()) {
            return $this->result(false, '请选择 Wowma 店铺后再同步。');
        }

        $credentials = $this->credentials($store);
        $selectedStatus = trim((string) ($options['order_status'] ?? $options['folder'] ?? ''));
        if ($selectedStatus !== '') {
            $credentials['statuses'] = [$selectedStatus];
        }
        $statusLabel = $selectedStatus !== '' ? '【' . $selectedStatus . '】' : '';
        if ($credentials['token'] === '') {
            return $this->markFailure(
                $tenantKey,
                $storeId,
                '缺少 Wowma API 的 bearer/access token，请在店铺 API 配置中保存 bearer_token/access_token/token，或设置 WOWMA_BEARER_TOKEN。'
            );
        }
        if ($credentials['shopId'] === '') {
            return $this->markFailure(
                $tenantKey,
                $storeId,
                '缺少 Wowma API 的 shopId/memberId/dpid，请在店铺 API 配置中保存 shop_id/member_id/dpid，或设置 WOWMA_SHOP_ID。'
            );
        }

        $days = max(1, min(30, $days));
        $timezone = new \DateTimeZone('Asia/Tokyo');
        $end = new \DateTimeImmutable('now', $timezone);
        $start = $end->modify("-{$days} days")->setTime(0, 0, 0);

        try {
            $ordersById = [];
            foreach ($credentials['statuses'] as $status) {
                $xml = $this->requestOrders($credentials, $start, $end, $status);
                foreach ($this->parseOrderNodes($xml) as $orderNode) {
                    $order = $this->mapOrder($orderNode, $store, $operator);
                    $orderId = (string) ($order['platform_order_id'] ?? '');
                    if ($orderId !== '') {
                        $ordersById[$orderId] = $order;
                    }
                }
            }

            $orders = array_values($ordersById);
            $searched = count($orders);
            if (!$orders) {
                $message = 'Wowma' . $statusLabel . '没有需要同步的新订单。';
                $this->store->markStoreSync($tenantKey, $storeId, '同步完成', $message);
                return $this->result(true, $message);
            }

            $summary = $this->store->upsertPlatformOrders($tenantKey, $orders, $operator);
            $message = sprintf(
                'Wowma%s同步完成：检索 %d 单，新增 %d 单，更新 %d 单，新增商品 %d 件，更新商品 %d 件，跳过 %d 单。',
                $statusLabel,
                $searched,
                (int) ($summary['inserted'] ?? 0),
                (int) ($summary['updated'] ?? 0),
                (int) ($summary['items_inserted'] ?? 0),
                (int) ($summary['items_updated'] ?? 0),
                (int) ($summary['skipped'] ?? 0)
            );
            $this->store->markStoreSync($tenantKey, $storeId, '同步完成', $message);

            return $this->result(true, $message, $summary, $searched);
        } catch (\Throwable $error) {
            $message = 'Wowma' . $statusLabel . '同步失败：' . $error->getMessage();
            $this->store->markStoreSync($tenantKey, $storeId, '同步异常', $message);
            return $this->result(false, $message);
        }
    }

    /**
     * @param array<string, mixed> $store
     * @return array{token: string, shopId: string, url: string, totalCount: int, dateFormat: string, statuses: array<int, string>}
     */
    private function credentials(array $store): array
    {
        $config = $this->apiConfig($store);

        $token = $this->firstConfigValue($config, [
            'bearer',
            'bearerToken',
            'bearer_token',
            'accessToken',
            'access_token',
            'token',
            'api_token',
            'dpapi_config',
            'authorization',
        ], ['WOWMA_BEARER_TOKEN', 'WOWMA_ACCESS_TOKEN']);
        $token = trim((string) (preg_replace('/^Bearer\s+/i', '', $token) ?? $token));

        $shopId = $this->firstConfigValue($config, [
            'shopId',
            'shop_id',
            'shopid',
            'memberId',
            'member_id',
            'memberid',
            'dpid',
            'dp_id',
        ], ['WOWMA_SHOP_ID', 'WOWMA_MEMBER_ID']);
        if ($shopId === '') {
            foreach (['legacy_dpid', 'dpid', 'shop_id', 'member_id'] as $field) {
                $shopId = trim((string) ($store[$field] ?? ''));
                if ($shopId !== '') {
                    break;
                }
            }
        }

        $url = $this->firstConfigValue($config, [
            'searchUrl',
            'search_url',
            'apiUrl',
            'api_url',
            'endpoint',
            'url',
        ], ['WOWMA_SEARCH_URL']);

        $totalCount = (int) $this->firstConfigValue($config, [
            'totalCount',
            'total_count',
            'limit',
        ], ['WOWMA_TOTAL_COUNT']);

        $dateFormat = $this->firstConfigValue($config, [
            'dateFormat',
            'date_format',
        ], ['WOWMA_DATE_FORMAT']);

        return [
            'token' => $token,
            'shopId' => $shopId,
            'url' => $url !== '' ? $url : self::SEARCH_URL,
            'totalCount' => max(1, min(self::MAX_TOTAL_COUNT, $totalCount > 0 ? $totalCount : self::DEFAULT_TOTAL_COUNT)),
            'dateFormat' => $dateFormat !== '' ? $dateFormat : 'Ymd',
            'statuses' => $this->statusList($config),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, string> $keys
     * @param array<int, string> $envNames
     */
    private function firstConfigValue(array $config, array $keys, array $envNames = []): string
    {
        foreach ($keys as $key) {
            foreach ($config as $configKey => $value) {
                if (strcasecmp((string) $configKey, $key) !== 0 || (!is_scalar($value) && $value !== null)) {
                    continue;
                }

                $text = trim((string) $value);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        foreach ($envNames as $envName) {
            $value = trim((string) (getenv($envName) ?: ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    private function statusList(array $config): array
    {
        $raw = $this->firstConfigValue($config, [
            'orderStatus',
            'order_status',
            'orderStatuses',
            'order_statuses',
            'status',
            'statuses',
        ], ['WOWMA_ORDER_STATUS', 'WOWMA_ORDER_STATUSES']);
        if ($raw === '') {
            return [''];
        }

        $values = preg_split('/[,\r\n|]+/', $raw) ?: [];
        $statuses = [];
        foreach ($values as $value) {
            $status = trim($value);
            if ($status !== '') {
                $statuses[$status] = $status;
            }
        }

        return $statuses ? array_values($statuses) : [''];
    }

    /**
     * @param array{token: string, shopId: string, url: string, totalCount: int, dateFormat: string, statuses: array<int, string>} $credentials
     */
    private function requestOrders(array $credentials, \DateTimeImmutable $start, \DateTimeImmutable $end, string $status): string
    {
        return $this->requestText($credentials['url'], [
            'method' => 'GET',
            'query' => [
                'shopId' => $credentials['shopId'],
                'totalCount' => $credentials['totalCount'],
                'startDate' => $start->format($credentials['dateFormat']),
                'endDate' => $end->format($credentials['dateFormat']),
                'orderStatus' => $status,
            ],
            'headers' => [
                'Authorization: Bearer ' . $credentials['token'],
                'Content-Type: application/x-www-form-urlencoded',
            ],
            'timeout' => 45,
            'connect_timeout' => 45,
        ]);
    }

    /**
     * @return array<int, \SimpleXMLElement>
     */
    private function parseOrderNodes(string $body): array
    {
        $xml = $this->loadXml($body);
        $status = $this->xmlText($xml->result ?? null, 'status');
        if ($status !== '' && $status !== '0') {
            $message = $this->xmlText($xml->result ?? null, 'message');
            throw new \RuntimeException('Wowma API 返回状态 ' . $status . ($message !== '' ? '：' . $message : '。'));
        }

        $nodes = $xml->xpath('//orderInfo');
        if (!is_array($nodes)) {
            return [];
        }

        return array_values(array_filter($nodes, fn (mixed $node): bool => $node instanceof \SimpleXMLElement));
    }

    private function loadXml(string $body): \SimpleXMLElement
    {
        if (!function_exists('simplexml_load_string')) {
            throw new \RuntimeException('当前 PHP 环境缺少 SimpleXML 扩展。');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if (!$xml && function_exists('mb_convert_encoding')) {
                $converted = mb_convert_encoding($body, 'UTF-8', 'UTF-8, SJIS-win, EUC-JP, ISO-2022-JP');
                $xml = simplexml_load_string($converted, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$xml) {
            throw new \RuntimeException('Wowma API XML 解析失败。');
        }

        return $xml;
    }

    /** @param array<string, mixed> $store */
    private function mapOrder(\SimpleXMLElement $orderNode, array $store, string $operator): array
    {
        $orderId = $this->xmlText($orderNode, 'orderId');
        $orderDateRaw = $this->xmlText($orderNode, 'orderDate');
        $orderDate = $this->formatDate($orderDateRaw);
        $shippingDate = $this->shippingDate($orderNode, $orderDateRaw);
        $details = $this->detailNodes($orderNode);
        $items = [];
        $quantityDetail = [];

        foreach ($details as $index => $itemNode) {
            $lineId = (string) ($index + 1);
            $item = $this->mapItem($itemNode, $orderNode, $lineId, $operator, $orderDate, $shippingDate);
            $items[] = $item;
            $quantityDetail[] = 'L' . $lineId . '=' . (int) ($item['quantity'] ?? 0);
        }

        $totalItemPrice = $this->money($this->xmlText($orderNode, 'totalItemPrice'));
        if ($totalItemPrice <= 0) {
            foreach ($items as $item) {
                $totalItemPrice += (float) ($item['line_total'] ?? 0);
            }
        }

        $orderStatus = $this->xmlText($orderNode, 'orderStatus');
        $extra = [
            'orderId' => $orderId,
            'orderDetailId' => (string) ($items[0]['order_detail_id'] ?? ''),
            'orderDate' => $orderDateRaw !== '' ? $orderDateRaw : $orderDate,
            'senderName' => $this->xmlText($orderNode, 'senderName'),
            'senderKana' => $this->xmlText($orderNode, 'senderKana'),
            'senderZipCode' => $this->xmlText($orderNode, 'senderZipCode'),
            'senderAddress' => $this->xmlText($orderNode, 'senderAddress'),
            'senderPhoneNumber1' => $this->xmlText($orderNode, 'senderPhoneNumber1'),
            'mailAddress' => $this->xmlText($orderNode, 'mailAddress'),
            'settlementName' => $this->xmlText($orderNode, 'settlementName'),
            'totalItemPrice' => $totalItemPrice,
            'postagePrice' => $this->xmlText($orderNode, 'postagePrice'),
            'totalPrice' => $this->xmlText($orderNode, 'totalPrice'),
            'deliveryName' => $this->xmlText($orderNode, 'deliveryName'),
            'orderStatus' => $orderStatus,
            'shippingDate' => $shippingDate,
            'QuantityDetail' => implode('&', $quantityDetail),
            'cdate' => date('Y-m-d H:i'),
            'user_name' => $operator,
            'beizhu' => '未处理的订单',
            'raw' => $this->xmlToArray($orderNode),
        ];

        return [
            'platform' => $this->platformCode(),
            'platform_order_id' => $orderId,
            'order_detail_id' => (string) ($items[0]['order_detail_id'] ?? ''),
            'store_id' => (int) ($store['id'] ?? 0),
            'store' => (string) ($store['name'] ?? ''),
            'order_date' => $orderDate,
            'status' => $orderStatus,
            'customer' => [
                'name' => $extra['senderName'],
                'kana' => $extra['senderKana'],
                'phone' => $extra['senderPhoneNumber1'],
                'zip' => $extra['senderZipCode'],
                'address' => $extra['senderAddress'],
                'mail' => $extra['mailAddress'],
            ],
            'pay_method' => $extra['settlementName'],
            'ship_method' => $extra['deliveryName'],
            'total_item_price' => $totalItemPrice,
            'postage_price' => $this->money($extra['postagePrice']),
            'pay_charge' => 0.0,
            'total' => $this->money($extra['totalPrice']),
            'platform_extra' => $extra,
            'items' => $items,
        ];
    }

    /**
     * @return array<int, \SimpleXMLElement>
     */
    private function detailNodes(\SimpleXMLElement $orderNode): array
    {
        $details = [];
        foreach ($orderNode->detail as $detail) {
            if ($detail instanceof \SimpleXMLElement) {
                $details[] = $detail;
            }
        }

        return $details;
    }

    private function mapItem(
        \SimpleXMLElement $itemNode,
        \SimpleXMLElement $orderNode,
        string $lineId,
        string $operator,
        string $orderDate,
        string $shippingDate
    ): array {
        $itemOption = $this->xmlText($itemNode, 'itemOption');
        $commissions = $this->optionCommissions($itemNode);
        $optionParts = array_values(array_filter(array_merge([$itemOption], $commissions), fn (string $value): bool => $value !== ''));
        $quantity = $this->quantity($this->xmlText($itemNode, 'unit'));
        $unitPrice = $this->money($this->xmlText($itemNode, 'itemPrice'));
        $lineTotal = $this->money($this->xmlText($itemNode, 'totalItemPrice'));
        if ($lineTotal <= 0 && $unitPrice > 0) {
            $lineTotal = $unitPrice * max(1, $quantity);
        }

        $extra = [
            'orderId' => $this->xmlText($orderNode, 'orderId'),
            'orderDetailId' => $this->xmlText($itemNode, 'orderDetailId'),
            'orderDate' => $this->xmlText($orderNode, 'orderDate') ?: $orderDate,
            'itemCode' => trim($this->xmlText($itemNode, 'itemCode')),
            'lotnumber' => $this->xmlText($itemNode, 'lotnumber'),
            'senderName' => $this->xmlText($orderNode, 'senderName'),
            'senderKana' => $this->xmlText($orderNode, 'senderKana'),
            'senderZipCode' => $this->xmlText($orderNode, 'senderZipCode'),
            'senderAddress' => $this->xmlText($orderNode, 'senderAddress'),
            'senderPhoneNumber1' => $this->xmlText($orderNode, 'senderPhoneNumber1'),
            'mailAddress' => $this->xmlText($orderNode, 'mailAddress'),
            'settlementName' => $this->xmlText($orderNode, 'settlementName'),
            'itemPrice' => $this->xmlText($itemNode, 'itemPrice'),
            'totalItemPrice' => $this->xmlText($itemNode, 'totalItemPrice'),
            'postagePrice' => $this->xmlText($orderNode, 'postagePrice'),
            'totalPrice' => $this->xmlText($orderNode, 'totalPrice'),
            'itemOption' => $itemOption,
            'itemOptionCommission1' => $commissions[0] ?? '',
            'itemOptionCommission2' => $commissions[1] ?? '',
            'itemOptionCommission3' => $commissions[2] ?? '',
            'itemOptionCommission4' => $commissions[3] ?? '',
            'itemOptionCommission5' => $commissions[4] ?? '',
            'deliveryName' => $this->xmlText($orderNode, 'deliveryName'),
            'orderStatus' => $this->xmlText($orderNode, 'orderStatus'),
            'shippingDate' => $shippingDate,
            'unit' => $this->xmlText($itemNode, 'unit'),
            'itemManagementId' => $this->xmlText($itemNode, 'itemManagementId'),
            'cdate' => date('Y-m-d H:i'),
            'user_name' => $operator,
            'beizhu' => '未处理的订单',
            'raw' => $this->xmlToArray($itemNode),
        ];

        $title = $this->xmlTextAny($itemNode, ['itemName', 'itemTitle', 'productName', 'itemManagementName']);

        return [
            'order_detail_id' => $extra['orderDetailId'],
            'line_id' => $lineId,
            'item_code' => $extra['itemCode'],
            'lot_number' => $extra['lotnumber'],
            'item_management_id' => $extra['itemManagementId'],
            'title' => $title !== '' ? $title : $extra['itemCode'],
            'option' => implode(PHP_EOL, $optionParts),
            'quantity' => $quantity,
            'source_type' => 'pending',
            'purchase_status' => '未处理的订单',
            'unit_price' => $unitPrice,
            'postage_price' => $this->money($extra['postagePrice']),
            'pay_charge' => 0.0,
            'line_total' => $lineTotal,
            'amount' => 0.0,
            'material' => '',
            'tranship_comment' => '',
            'platform_extra' => $extra,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function optionCommissions(\SimpleXMLElement $itemNode): array
    {
        $commissions = [];
        foreach ($itemNode->itemOptionCommissions as $commissionNode) {
            if (!$commissionNode instanceof \SimpleXMLElement) {
                continue;
            }
            if (count($commissions) >= 5) {
                break;
            }

            $title = $this->xmlText($commissionNode, 'itemOptionCommissionTitle');
            $values = [];
            foreach ($commissionNode->itemOptionCommissionVal as $valueNode) {
                if (!$valueNode instanceof \SimpleXMLElement) {
                    continue;
                }
                $name = $this->xmlText($valueNode, 'itemOptionCommission');
                if ($name === '') {
                    continue;
                }
                $price = $this->xmlText($valueNode, 'itemOptionCommissionPrice');
                $values[] = $price !== '' ? $name . ':' . $price : $name;
            }

            $text = trim($title . ($values ? '=' . implode('&', $values) : ''), '&=');
            $commissions[] = $text;
        }

        return array_pad($commissions, 5, '');
    }

    private function xmlText(mixed $node, string $field): string
    {
        if (!$node instanceof \SimpleXMLElement || !isset($node->{$field})) {
            return '';
        }

        return trim((string) $node->{$field});
    }

    /** @param array<int, string> $fields */
    private function xmlTextAny(\SimpleXMLElement $node, array $fields): string
    {
        foreach ($fields as $field) {
            $value = $this->xmlText($node, $field);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function shippingDate(\SimpleXMLElement $orderNode, string $orderDateRaw): string
    {
        $raw = $this->xmlText($orderNode, 'shippingDate');
        if ($raw !== '') {
            return $this->formatDate($raw, 'Y-m-d');
        }

        $orderDate = $this->formatDate($orderDateRaw, 'Y-m-d');
        if ($orderDate === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($orderDate, new \DateTimeZone('Asia/Tokyo')))
                ->modify('+1 day')
                ->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }

    private function formatDate(mixed $value, string $format = 'Y-m-d H:i:s'): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return '';
        }

        $timezone = new \DateTimeZone('Asia/Tokyo');
        foreach (['!YmdHis', '!Ymd', '!Y-m-d H:i:s', '!Y-m-d'] as $inputFormat) {
            $date = \DateTimeImmutable::createFromFormat($inputFormat, $raw, $timezone);
            if ($date instanceof \DateTimeImmutable) {
                return $date->setTimezone($timezone)->format($format);
            }
        }

        try {
            return (new \DateTimeImmutable($raw, $timezone))->setTimezone($timezone)->format($format);
        } catch (\Throwable) {
            $timestamp = strtotime($raw);
            return $timestamp !== false ? date($format, $timestamp) : '';
        }
    }

    private function money(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $normalized = preg_replace('/[^\d.\-]+/', '', str_replace(',', '', (string) ($value ?? '')));
        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function quantity(mixed $value): int
    {
        return max(0, (int) $this->money($value));
    }

    /** @return array<string, mixed> */
    private function xmlToArray(\SimpleXMLElement $node): array
    {
        $json = json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $data = is_string($json) ? json_decode($json, true) : null;

        return is_array($data) ? $data : [];
    }
}
