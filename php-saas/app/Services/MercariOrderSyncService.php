<?php

declare(strict_types=1);

namespace Xizhen\Services;

final class MercariOrderSyncService extends AbstractPlatformOrderSyncService
{
    private const PLATFORM = 'm';

    public function platformCode(): string
    {
        return self::PLATFORM;
    }

    public function platformName(): string
    {
        return 'Mercari';
    }

    /**
     * @param array<string, mixed> $options
     * @return array{ok: bool, message: string, searched: int, inserted: int, updated: int, skipped: int, items_inserted: int, items_updated: int}
     */
    public function sync(string $tenantKey, int $storeId, int $days, string $operator, array $options = []): array
    {
        $store = $this->store->store($tenantKey, $storeId);
        if (!$store || (string) ($store['platform'] ?? '') !== self::PLATFORM) {
            return $this->result(false, '请选择 Mercari 店铺后再同步。');
        }

        $config = $this->apiConfig($store);
        $endpoint = $this->configValue($config, ['endpoint', 'orders_endpoint', 'ordersUrl', 'orders_url', 'url', 'api_url'], 'MERCARI_ORDERS_ENDPOINT');
        $token = $this->configValue($config, ['token', 'access_token', 'bearer_token', 'api_token', 'authorization'], 'MERCARI_API_TOKEN');
        if ($endpoint === '' || $token === '') {
            return $this->failure($tenantKey, $storeId, 'Mercari API 未配置：请在店铺 api_config 中配置 endpoint/token。');
        }

        $days = max(1, min(30, $days));
        $end = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo'));
        $start = $end->modify("-{$days} days")->setTime(0, 0, 0);

        try {
            $payload = $this->requestJson($endpoint, $this->requestOptions($config, $token, $start, $end, $days, $store));
            $rows = $this->extractOrderRows($payload);
            $searched = count($rows);

            if ($searched === 0) {
                $message = 'Mercari 没有需要同步的订单。';
                $this->store->markStoreSync($tenantKey, $storeId, '同步完成', $message);
                return $this->result(true, $message);
            }

            $orders = [];
            $skipped = 0;
            foreach ($rows as $row) {
                $mapped = $this->mapOrder($row, $store, $operator);
                if ($mapped === null) {
                    $skipped++;
                    continue;
                }

                $orders[] = $mapped;
            }

            $summary = ['inserted' => 0, 'updated' => 0, 'skipped' => $skipped, 'items_inserted' => 0, 'items_updated' => 0];
            if ($orders) {
                $step = $this->store->upsertPlatformOrders($tenantKey, $orders, $operator);
                foreach ($summary as $key => $_) {
                    $summary[$key] += (int) ($step[$key] ?? 0);
                }
            }

            $message = sprintf(
                'Mercari 同步完成：检索 %d 单，新增 %d 单，更新 %d 单，新增商品 %d 件，更新商品 %d 件，跳过 %d 单。',
                $searched,
                $summary['inserted'],
                $summary['updated'],
                $summary['items_inserted'],
                $summary['items_updated'],
                $summary['skipped']
            );
            $this->store->markStoreSync($tenantKey, $storeId, '同步完成', $message);

            return $this->result(true, $message, $summary, $searched);
        } catch (\Throwable $error) {
            return $this->failure($tenantKey, $storeId, 'Mercari 同步失败：' . $error->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    private function requestOptions(
        array $config,
        string $token,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        int $days,
        array $store
    ): array {
        $method = strtoupper($this->configValue($config, ['method', 'http_method']));
        if (!in_array($method, ['GET', 'POST', 'PUT'], true)) {
            $method = 'GET';
        }

        $params = $this->requestParams($config, $start, $end, $days, $store);
        $headers = array_merge(
            ['Accept: application/json'],
            $this->authHeaders($config, $token),
            $this->configuredHeaders($config)
        );

        $options = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => $this->intConfig($config, ['timeout'], 30),
            'connect_timeout' => $this->intConfig($config, ['connect_timeout'], 15),
        ];

        if ($method === 'GET') {
            $options['query'] = $params;
            return $options;
        }

        $headers[] = 'Content-Type: application/json; charset=utf-8';
        $options['headers'] = $headers;
        $options['body'] = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $options;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    private function requestParams(
        array $config,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        int $days,
        array $store
    ): array {
        $params = [];
        if ($this->boolConfig($config, 'send_dates', true)) {
            $params[$this->configValue($config, ['start_param', 'from_param']) ?: 'start_date'] = $start->format('Y-m-d H:i:s');
            $params[$this->configValue($config, ['end_param', 'to_param']) ?: 'end_date'] = $end->format('Y-m-d H:i:s');
        }

        if ($this->boolConfig($config, 'send_days', true)) {
            $params[$this->configValue($config, ['days_param']) ?: 'days'] = $days;
        }

        $shopId = $this->configValue($config, ['shop_id', 'shopId', 'seller_id', 'sellerId']);
        $shopParam = $this->configValue($config, ['shop_param', 'seller_param']);
        if ($shopId !== '' && $shopParam !== '') {
            $params[$shopParam] = $shopId;
        }

        $storeParam = $this->configValue($config, ['store_param']);
        if ($storeParam !== '') {
            $params[$storeParam] = (int) ($store['id'] ?? 0);
        }

        foreach (['query', 'params', 'body'] as $key) {
            if (is_array($config[$key] ?? null)) {
                $params = array_replace($params, $config[$key]);
            }
        }

        return $params;
    }

    /** @param array<string, mixed> $config @return array<int, string> */
    private function authHeaders(array $config, string $token): array
    {
        $header = $this->configValue($config, ['token_header', 'auth_header']) ?: 'Authorization';
        $scheme = $this->configValue($config, ['auth_scheme', 'token_type']) ?: 'Bearer';
        $value = $token;

        if (strcasecmp($header, 'Authorization') === 0 && !preg_match('/^(Bearer|Basic|Token|ApiKey)\s+/i', $value)) {
            $value = trim($scheme . ' ' . $value);
        }

        return [$header . ': ' . $value];
    }

    /** @param array<string, mixed> $config @return array<int, string> */
    private function configuredHeaders(array $config): array
    {
        $headers = [];
        $raw = $config['headers'] ?? [];
        if (!is_array($raw)) {
            return $headers;
        }

        foreach ($raw as $key => $value) {
            if (is_int($key)) {
                $line = trim((string) $value);
                if ($line !== '') {
                    $headers[] = $line;
                }
                continue;
            }

            $headers[] = trim((string) $key) . ': ' . trim((string) $value);
        }

        return $headers;
    }

    /** @param array<string, mixed> $config @param array<int, string> $keys */
    private function intConfig(array $config, array $keys, int $default): int
    {
        foreach ($keys as $key) {
            $value = (int) ($config[$key] ?? 0);
            if ($value > 0) {
                return $value;
            }
        }

        return $default;
    }

    /** @param array<string, mixed> $config */
    private function boolConfig(array $config, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $config)) {
            return $default;
        }

        $value = strtolower(trim((string) $config[$key]));
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function extractOrderRows(array $payload): array
    {
        $direct = $this->listFrom($payload);
        if ($direct) {
            return $direct;
        }

        foreach ([
            'orders',
            'data.orders',
            'data.orders.edges',
            'data.orders.nodes',
            'data.items',
            'data.list',
            'data.records',
            'result.orders',
            'result.items',
            'result.list',
            'result.records',
            'results',
            'records',
            'items',
            'list',
        ] as $path) {
            $rows = $this->listFrom($this->valueAt($payload, $path));
            if ($rows) {
                return $rows;
            }
        }

        return $this->looksLikeOrder($payload) ? [$payload] : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listFrom(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        if (is_array($value['edges'] ?? null)) {
            $nodes = [];
            foreach ($value['edges'] as $edge) {
                if (is_array($edge['node'] ?? null)) {
                    $nodes[] = $edge['node'];
                }
            }

            return $nodes;
        }

        if (is_array($value['nodes'] ?? null)) {
            return array_values(array_filter($value['nodes'], 'is_array'));
        }

        if (!array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /** @param array<string, mixed> $row */
    private function looksLikeOrder(array $row): bool
    {
        return $this->pickString($row, ['displayId', 'orderId', 'order_id', 'orderNumber', 'order_number', 'id']) !== '';
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $store
     * @return array<string, mixed>|null
     */
    private function mapOrder(array $row, array $store, string $operator): ?array
    {
        $orderId = $this->pickString($row, ['displayId', 'orderId', 'order_id', 'orderNumber', 'order_number', 'number', 'id']);
        if ($orderId === '') {
            return null;
        }

        $status = $this->pickString($row, ['status', 'orderStatus', 'order_status', 'OrderStatus']);
        $orderDate = $this->formatDate($this->pick($row, ['openedAt', 'orderDate', 'order_date', 'createdAt', 'created_at', 'paidAt', 'paid_at', 'OrderTime']));
        $shipName = $this->shipName($row);
        $zip = $this->pickString($row, ['shipping_postal_code', 'ShipZipCode', 'shipping.shippingAddress.zipCode', 'shippingAddress.zipCode', 'zip', 'zipCode']);
        $state = $this->pickString($row, ['shipping_state', 'ShipPrefecture', 'shipping.shippingAddress.state.name', 'shipping.shippingAddress.state', 'shippingAddress.state.name', 'prefecture', 'state']);
        $city = $this->pickString($row, ['shipping_city', 'ShipCity', 'shipping.shippingAddress.city', 'shippingAddress.city', 'city']);
        $address1 = $this->pickString($row, ['shipping_address_1', 'ShipAddress1', 'shipping.shippingAddress.address1', 'shippingAddress.address1', 'address1', 'address']);
        $address2 = $this->pickString($row, ['shipping_address_2', 'ShipAddress2', 'shipping.shippingAddress.address2', 'shippingAddress.address2', 'address2']);
        $phone = $this->pickString($row, ['shipping_phone_number', 'ShipPhoneNumber', 'senderPhoneNumber1', 'shipping.shippingAddress.phoneNumber', 'shippingAddress.phoneNumber', 'phone', 'phoneNumber']);
        $mail = $this->pickString($row, ['mailAddress', 'BillMailAddress', 'customer.email', 'customerInfo.email', 'email', 'mail']);
        $postage = $this->pickFloat($row, ['shippingFee', 'postagePrice', 'postage_price', 'ShipCharge']);
        $payCharge = $this->pickFloat($row, ['payCharge', 'pay_charge', 'PayCharge']);
        $total = $this->pickFloat($row, ['totalPrice', 'total_price', 'total', 'requestPrice']);
        $totalItemPrice = $this->pickFloat($row, ['totalItemPrice', 'total_item_price', 'goodsPrice', 'itemSubtotal']);
        if ($totalItemPrice <= 0 && $total > 0) {
            $totalItemPrice = max(0, $total - $postage - $payCharge);
        }

        $rawItems = $this->extractItems($row);
        $quantityDetail = $this->quantityDetail($rawItems);
        $items = [];
        foreach ($rawItems as $index => $item) {
            $items[] = $this->mapItem($item, $row, $index, $totalItemPrice, $postage, $payCharge, $quantityDetail);
        }

        $extra = [
            'OrderId' => $orderId,
            'displayId' => $this->pickString($row, ['displayId']),
            'orderId' => $this->pickString($row, ['orderId', 'order_id']),
            'OrderTime' => $orderDate,
            'OrderStatus' => $status,
            'ShipName' => $shipName,
            'ShipAddress1' => $address1,
            'ShipAddress2' => $address2,
            'ShipCity' => $city,
            'ShipPrefecture' => $state,
            'ShipZipCode' => $zip,
            'ShipPhoneNumber' => $phone,
            'BillMailAddress' => $mail,
            'PayMethodName' => $this->pickString($row, ['settlementName', 'payMethodName', 'payment.method', 'pay_method']),
            'PayStatus' => $this->pickString($row, ['payment.status', 'payStatus', 'PayStatus']),
            'QuantityDetail' => $quantityDetail,
            'ShipCharge' => $postage,
            'PayCharge' => $payCharge,
            'TotalPrice' => $total,
            'shippingPayerType' => $this->pickString($row, ['shippingPayerType']),
            'transactionMessageStatus' => $this->pickString($row, ['transactionMessageStatus']),
            'customerNickname' => $this->pickString($row, ['customerNickname', 'customerInfo.nickname']),
            'cdate' => date('Y-m-d H:i'),
            'user_name' => $operator,
            'beizhu' => '未处理的订单',
            'raw' => $row,
        ];

        return [
            'platform' => self::PLATFORM,
            'platform_order_id' => $orderId,
            'store_id' => (int) ($store['id'] ?? 0),
            'store' => (string) ($store['name'] ?? ''),
            'order_date' => $orderDate,
            'status' => $status,
            'customer' => [
                'name' => $shipName,
                'kana' => $this->pickString($row, ['senderKana', 'customer.kana', 'shipping.shippingAddress.kana']),
                'phone' => $phone,
                'zip' => $zip,
                'address' => trim($state . $city . $address1 . $address2),
                'mail' => $mail,
            ],
            'pay_method' => $this->pickString($row, ['settlementName', 'payMethodName', 'payment.method', 'pay_method']),
            'ship_method' => $this->pickString($row, ['deliveryName', 'ship_method', 'shipping_method', 'shipping.method']),
            'total_item_price' => $totalItemPrice,
            'postage_price' => $postage,
            'pay_charge' => $payCharge,
            'total' => $total > 0 ? $total : ($totalItemPrice + $postage + $payCharge),
            'platform_extra' => $extra,
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, array<string, mixed>>
     */
    private function extractItems(array $row): array
    {
        foreach (['orderProducts', 'products', 'items', 'lineItems', 'line_items', 'details', 'orderItems', 'order_items'] as $path) {
            $items = $this->listFrom($this->valueAt($row, $path));
            if ($items) {
                return $items;
            }
        }

        return [$row];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapItem(
        array $item,
        array $row,
        int $index,
        float $orderItemTotal,
        float $postage,
        float $payCharge,
        string $quantityDetail
    ): array {
        $quantity = $this->pickInt($item, ['quantity', 'unit', 'units', 'qty'], 1);
        $unitPrice = $this->pickFloat($item, ['unit_price', 'unitPrice', 'price', 'totalItemPrice', 'productPrice']);
        if ($unitPrice <= 0 && $index === 0 && $orderItemTotal > 0) {
            $unitPrice = $orderItemTotal / max(1, $quantity);
        }

        $lineTotal = $this->pickFloat($item, ['line_total', 'lineTotal', 'totalPrice', 'total_price']);
        if ($lineTotal <= 0) {
            $lineTotal = $unitPrice * max(1, $quantity);
        }

        $lineId = $this->pickString($item, ['line_id', 'lineId', 'LineId']);
        if ($lineId === '') {
            $lineId = (string) ($index + 1);
        }

        $productId = $this->pickString($item, ['productId', 'product_id', 'lotnumber', 'lotNumber', 'lot_number', 'id']);
        $itemCode = $this->pickString($item, ['itemCode', 'item_code', 'original_product_id', 'variant.skuCode', 'skuCode', 'sku', 'code']);
        $title = $this->pickString($item, ['product_name', 'name', 'title', 'productTitle', 'itemName']);
        $option = $this->pickString($item, ['product_option', 'variant.name', 'variantName', 'option', 'itemOption', 'selectedChoice']);
        $mainImage = $this->pickString($item, ['image', 'main_image', 'mainImage', 'thumbnail', 'imageUrl', 'photo.url', 'photos.0']);

        return [
            'order_detail_id' => $this->pickString($item, ['orderDetailId', 'order_detail_id', 'detailId']),
            'line_id' => $lineId,
            'item_code' => $itemCode !== '' ? $itemCode : $productId,
            'lot_number' => $productId,
            'item_management_id' => $this->pickString($item, ['itemManagementId', 'item_management_id', 'managementId']),
            'title' => $title,
            'option' => $option,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'postage_price' => $index === 0 ? $postage : 0,
            'pay_charge' => $index === 0 ? $payCharge : 0,
            'line_total' => $lineTotal,
            'purchase_status' => '未处理的订单',
            'image' => $mainImage,
            'main_image' => $mainImage,
            'sku_image' => $this->pickString($item, ['sku_image', 'skuImage', 'variant.imageUrl']),
            'platform_extra' => [
                'LineId' => $lineId,
                'ItemId' => $productId,
                'ItemManagerId' => $itemCode,
                'Quantity' => $quantity,
                'SubCodeOption' => $option,
                'UnitPrice' => $unitPrice,
                'QuantityDetail' => $quantityDetail,
                'OrderId' => $this->pickString($row, ['displayId', 'orderId', 'order_id']),
                'OrderStatus' => $this->pickString($row, ['status', 'orderStatus', 'order_status']),
                'EntryPoint' => $productId !== '' ? 'https://jp.mercari.com/shops/product/' . rawurlencode($productId) : '',
                'zhutu' => $mainImage,
                'skuimg' => $this->pickString($item, ['sku_image', 'skuImage', 'variant.imageUrl']),
                'raw' => $item,
            ],
        ];
    }

    /** @param array<int, array<string, mixed>> $items */
    private function quantityDetail(array $items): string
    {
        $parts = [];
        foreach ($items as $index => $item) {
            $parts[] = 'L' . ($index + 1) . '=' . $this->pickInt($item, ['quantity', 'unit', 'units', 'qty'], 1);
        }

        return implode('&', $parts);
    }

    /** @param array<string, mixed> $row */
    private function shipName(array $row): string
    {
        $name = $this->pickString($row, ['shipname', 'ShipName', 'senderName', 'customer.name', 'buyer.name', 'recipient.name']);
        if ($name !== '') {
            return $name;
        }

        $last = $this->pickString($row, ['shipping.shippingAddress.lastName', 'shippingAddress.lastName', 'lastName', 'familyName']);
        $first = $this->pickString($row, ['shipping.shippingAddress.firstName', 'shippingAddress.firstName', 'firstName']);

        return trim($last . ' ' . $first);
    }

    /** @param array<string, mixed> $row @param array<int, string> $paths */
    private function pick(array $row, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = $this->valueAt($row, $path);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $row @param array<int, string> $paths */
    private function pickString(array $row, array $paths, string $default = ''): string
    {
        $value = $this->pick($row, $paths);
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return $default;
    }

    /** @param array<string, mixed> $row @param array<int, string> $paths */
    private function pickInt(array $row, array $paths, int $default = 0): int
    {
        $value = $this->pick($row, $paths);
        if ($value === null || $value === '') {
            return $default;
        }

        return max(0, (int) $this->numberValue($value));
    }

    /** @param array<string, mixed> $row @param array<int, string> $paths */
    private function pickFloat(array $row, array $paths, float $default = 0.0): float
    {
        $value = $this->pick($row, $paths);
        if ($value === null || $value === '') {
            return $default;
        }

        return $this->numberValue($value);
    }

    private function numberValue(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $raw = preg_replace('/[^\d.\-]/', '', (string) $value);
        if ($raw === null || $raw === '' || $raw === '-' || $raw === '.') {
            return 0.0;
        }

        return (float) $raw;
    }

    /** @param array<string, mixed> $row */
    private function valueAt(array $row, string $path): mixed
    {
        $current = $row;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current)) {
                return null;
            }

            if (array_key_exists($segment, $current)) {
                $current = $current[$segment];
                continue;
            }

            if (ctype_digit($segment) && array_key_exists((int) $segment, $current)) {
                $current = $current[(int) $segment];
                continue;
            }

            $found = false;
            foreach ($current as $key => $value) {
                if (is_string($key) && strcasecmp($key, $segment) === 0) {
                    $current = $value;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                return null;
            }
        }

        return $current;
    }

    private function formatDate(mixed $value, string $format = 'Y-m-d H:i:s'): string
    {
        if (is_int($value) || is_float($value)) {
            $timestamp = (int) $value;
            if ($timestamp > 9999999999) {
                $timestamp = (int) floor($timestamp / 1000);
            }

            return (new \DateTimeImmutable('@' . $timestamp))
                ->setTimezone(new \DateTimeZone('Asia/Tokyo'))
                ->format($format);
        }

        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return '';
        }

        $normalized = str_replace(['年', '月', '日'], ['-', '-', ''], $raw);
        $normalized = preg_replace('/\s+/', ' ', trim($normalized)) ?: $raw;

        try {
            return (new \DateTimeImmutable($normalized))
                ->setTimezone(new \DateTimeZone('Asia/Tokyo'))
                ->format($format);
        } catch (\Throwable) {
            $timestamp = strtotime($normalized);
            return $timestamp !== false ? date($format, $timestamp) : $raw;
        }
    }

    /**
     * @return array{ok: bool, message: string, searched: int, inserted: int, updated: int, skipped: int, items_inserted: int, items_updated: int}
     */
    private function failure(string $tenantKey, int $storeId, string $message): array
    {
        $this->store->markStoreSync($tenantKey, $storeId, '同步异常', $message);
        return $this->result(false, $message);
    }
}
