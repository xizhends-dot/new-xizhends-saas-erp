<?php

declare(strict_types=1);

namespace Xizhen\Services;

final class Qoo10OrderSyncService extends AbstractPlatformOrderSyncService
{
    private const MAX_DAYS = 30;

    public function platformCode(): string
    {
        return 'q';
    }

    public function platformName(): string
    {
        return 'Qoo10';
    }

    /**
     * @param array<string, mixed> $options
     * @return array{ok: bool, message: string, searched: int, inserted: int, updated: int, skipped: int, items_inserted: int, items_updated: int}
     */
    public function sync(string $tenantKey, int $storeId, int $days, string $operator, array $options = []): array
    {
        $store = $this->store->store($tenantKey, $storeId);
        if (!$store || ($store['platform'] ?? '') !== $this->platformCode()) {
            return $this->result(false, '请选择 Qoo10 店铺后再同步。');
        }

        $config = $this->apiConfig($store);
        $credentials = $this->credentials($config);
        if ($credentials['endpoint'] === '' || $credentials['token'] === '' || $credentials['seller_id'] === '') {
            return $this->markFailure($tenantKey, $storeId, 'Qoo10 API 未配置：请在店铺 API 配置中设置 endpoint/token/seller_id。');
        }

        $days = max(1, min(self::MAX_DAYS, $days));
        $end = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo'));
        $start = $end->modify("-{$days} days")->setTime(0, 0, 0);

        try {
            $response = $this->fetchOrders($credentials, $config, $start, $end, $days);
            $rows = $this->extractRows($response, $config);
            $mapped = $this->mapOrders($rows, $store, $config, $operator);
            $orders = $mapped['orders'];
            $mappingSkipped = $mapped['skipped'];

            if (!$orders && $rows) {
                return $this->markFailure($tenantKey, $storeId, 'Qoo10 API 返回数据缺少订单号，未写入订单。');
            }

            if (!$orders) {
                $message = 'Qoo10 同步完成：没有需要同步的新订单。';
                $this->store->markStoreSync($tenantKey, $storeId, '同步完成', $message);
                return $this->result(true, $message, [], count($rows));
            }

            $summary = $this->store->upsertPlatformOrders($tenantKey, $orders, $operator);
            $summary['skipped'] = (int) ($summary['skipped'] ?? 0) + $mappingSkipped;
            $message = sprintf(
                'Qoo10 同步完成：检索 %d 条，写入 %d 单，新增 %d 单，更新 %d 单，新增商品 %d 件，更新商品 %d 件，跳过 %d 条。',
                count($rows),
                count($orders),
                (int) ($summary['inserted'] ?? 0),
                (int) ($summary['updated'] ?? 0),
                (int) ($summary['items_inserted'] ?? 0),
                (int) ($summary['items_updated'] ?? 0),
                (int) ($summary['skipped'] ?? 0)
            );
            $this->store->markStoreSync($tenantKey, $storeId, '同步完成', $message);

            return $this->result(true, $message, $summary, count($rows));
        } catch (\Throwable $error) {
            return $this->markFailure($tenantKey, $storeId, 'Qoo10 同步失败：' . $error->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array{endpoint: string, token: string, seller_id: string}
     */
    private function credentials(array $config): array
    {
        return [
            'endpoint' => $this->configValue(
                $config,
                ['endpoint', 'orders_endpoint', 'order_endpoint', 'api_endpoint', 'url', 'api_url', 'ENDPOINT'],
                'QOO10_ORDER_API_ENDPOINT'
            ),
            'token' => $this->configValue(
                $config,
                ['token', 'access_token', 'api_token', 'auth_token', 'key', 'api_key', 'TOKEN'],
                'QOO10_API_TOKEN'
            ),
            'seller_id' => $this->configValue(
                $config,
                ['seller_id', 'sellerId', 'seller', 'shop_id', 'shopId', 'vendor_id', 'vendorId', 'SELLER_ID'],
                'QOO10_SELLER_ID'
            ),
        ];
    }

    /**
     * @param array{endpoint: string, token: string, seller_id: string} $credentials
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function fetchOrders(
        array $credentials,
        array $config,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        int $days
    ): array {
        $method = strtoupper($this->configValue($config, ['method', 'http_method']) ?: 'GET');
        $dateFormat = $this->configValue($config, ['date_format']) ?: 'Y-m-d H:i:s';
        $sellerParam = $this->configValue($config, ['seller_param']) ?: 'seller_id';
        $startParam = $this->configValue($config, ['start_param']) ?: 'start_date';
        $endParam = $this->configValue($config, ['end_param']) ?: 'end_date';
        $daysParam = $this->configValue($config, ['days_param']);
        $tokenParam = $this->configValue($config, ['token_param']);
        $timeout = max(5, (int) ($this->configValue($config, ['timeout']) ?: 30));

        $params = $this->extraParams($config);
        $params[$sellerParam] = $credentials['seller_id'];
        $params[$startParam] = $start->format($dateFormat);
        $params[$endParam] = $end->format($dateFormat);
        if ($daysParam !== '') {
            $params[$daysParam] = $days;
        }
        if ($tokenParam !== '') {
            $params[$tokenParam] = $credentials['token'];
        }

        $headers = $this->headers($config, $credentials['token']);
        $options = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => $timeout,
            'connect_timeout' => min(15, $timeout),
        ];

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $bodyFormat = strtolower($this->configValue($config, ['body_format']) ?: 'json');
            if ($bodyFormat === 'json') {
                $headers[] = 'Content-Type: application/json; charset=utf-8';
                $options['headers'] = $headers;
                $options['body'] = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $options['body'] = $params;
            }
        } else {
            $options['query'] = $params;
        }

        return $this->requestJson($credentials['endpoint'], $options);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, scalar>
     */
    private function extraParams(array $config): array
    {
        foreach (['extra_query', 'static_query', 'query', 'params'] as $key) {
            $raw = $config[$key] ?? null;
            if (is_array($raw)) {
                return $this->scalarParams($raw);
            }
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }

            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $this->scalarParams($decoded);
            }

            parse_str($raw, $parsed);
            if (is_array($parsed) && $parsed) {
                return $this->scalarParams($parsed);
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, scalar>
     */
    private function scalarParams(array $params): array
    {
        $clean = [];
        foreach ($params as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $clean[(string) $key] = $value;
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    private function headers(array $config, string $token): array
    {
        $headers = ['Accept: application/json'];
        $tokenHeader = $this->configValue($config, ['token_header']) ?: 'Authorization';
        if ($tokenHeader === '') {
            return $headers;
        }

        if (strcasecmp($tokenHeader, 'Authorization') === 0) {
            $scheme = $this->configValue($config, ['auth_scheme']) ?: 'Bearer';
            $headers[] = 'Authorization: ' . ($scheme !== '' ? $scheme . ' ' : '') . $token;
        } else {
            $headers[] = $tokenHeader . ': ' . $token;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $config
     * @return array<int, array<string, mixed>>
     */
    private function extractRows(array $data, array $config): array
    {
        if (array_key_exists('success', $data) && $data['success'] === false) {
            throw new \RuntimeException($this->firstString($data, ['message', 'error', 'error_message']) ?: 'API 返回失败状态。');
        }

        $path = $this->configValue($config, ['orders_path', 'order_list_path', 'list_path', 'data_path', 'response_path']);
        if ($path !== '') {
            $value = $this->valueAtPath($data, $path);
            $rows = $this->listOfArrays($value);
            if ($rows === []) {
                throw new \RuntimeException('API 返回中未找到订单列表：' . $path);
            }

            return $rows;
        }

        foreach ([
            'orders',
            'Orders',
            'orderList',
            'OrderList',
            'data.orders',
            'data.orderList',
            'data.list',
            'data.items',
            'data',
            'result.orders',
            'result.list',
            'result.items',
            'result',
            'results',
            'Results',
            'items',
            'Items',
        ] as $candidate) {
            $rows = $this->listOfArrays($this->valueAtPath($data, $candidate));
            if ($rows !== []) {
                return $rows;
            }
        }

        return $this->findFirstList($data);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $store
     * @param array<string, mixed> $config
     * @return array{orders: array<int, array<string, mixed>>, skipped: int}
     */
    private function mapOrders(array $rows, array $store, array $config, string $operator): array
    {
        $orders = [];
        $skipped = 0;

        foreach ($rows as $row) {
            $orderId = $this->orderId($row);
            if ($orderId === '') {
                $skipped++;
                continue;
            }

            if (!isset($orders[$orderId])) {
                $orders[$orderId] = $this->mapOrder($row, $store, $operator);
            }

            $items = $this->itemRows($row, $config);
            if ($items === [] && $this->hasFlatItemData($row)) {
                $items = [$row];
            }
            foreach ($items as $item) {
                $orders[$orderId]['items'][] = $this->mapItem(
                    $item,
                    $row,
                    count($orders[$orderId]['items']) + 1,
                    $operator
                );
            }
        }

        return ['orders' => array_values($orders), 'skipped' => $skipped];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    private function mapOrder(array $row, array $store, string $operator): array
    {
        $orderId = $this->orderId($row);
        $orderTime = $this->dateString($this->firstString($row, [
            'order_date',
            'orderDate',
            'OrderDate',
            'order_time',
            'orderTime',
            'OrderTime',
            'paymentDate',
            'paid_at',
            'created_at',
        ]));
        $status = $this->firstString($row, [
            'order_status',
            'orderStatus',
            'OrderStatus',
            'shippingStatus',
            'ShippingStatus',
            'deliveryStatus',
            'status',
            'Status',
        ]);
        $customerName = $this->firstString($row, [
            'receiverName',
            'recipientName',
            'shippingName',
            'ShipName',
            'buyerName',
            'customerName',
            'senderName',
            'name',
        ]);
        $customerKana = $this->firstString($row, ['receiverKana', 'recipientKana', 'senderKana', 'ShipKana', 'kana']);
        $customerPhone = $this->firstString($row, [
            'receiverPhone',
            'recipientPhone',
            'shippingPhone',
            'ShipPhoneNumber',
            'senderPhoneNumber1',
            'phone',
        ]);
        $customerZip = $this->firstString($row, [
            'receiverZipCode',
            'recipientZip',
            'shippingZip',
            'ShipZipCode',
            'senderZipCode',
            'zip',
        ]);
        $address = trim(implode(' ', array_filter([
            $this->firstString($row, ['receiverAddress', 'recipientAddress', 'shippingAddress', 'senderAddress', 'address']),
            $this->firstString($row, ['prefecture', 'ShipPrefecture']),
            $this->firstString($row, ['city', 'ShipCity']),
            $this->firstString($row, ['address1', 'ShipAddress1']),
            $this->firstString($row, ['address2', 'ShipAddress2']),
        ])));
        $total = $this->firstMoney($row, ['total_price', 'totalPrice', 'TotalPrice', 'orderTotal', 'OrderTotal', 'settlementAmount']);
        $itemTotal = $this->firstMoney($row, ['total_item_price', 'totalItemPrice', 'TotalItemPrice', 'goodsPrice', 'itemTotal']);
        if ($itemTotal <= 0) {
            $itemTotal = $total;
        }

        return [
            'platform' => $this->platformCode(),
            'platform_order_id' => $orderId,
            'store_id' => (int) ($store['id'] ?? 0),
            'store' => (string) ($store['name'] ?? ''),
            'order_date' => $orderTime,
            'status' => $status,
            'customer' => [
                'name' => $customerName,
                'kana' => $customerKana,
                'phone' => $customerPhone,
                'zip' => str_replace("'", '', $customerZip),
                'address' => $address,
                'mail' => $this->firstString($row, ['email', 'mail', 'mailAddress', 'BillMailAddress', 'customerMail']),
            ],
            'pay_method' => $this->firstString($row, ['pay_method', 'payMethod', 'PayMethodName', 'settlementName', 'paymentMethod']),
            'ship_method' => $this->firstString($row, ['ship_method', 'shipMethod', 'deliveryName', 'DeliveryName', 'shippingCompany']),
            'total_item_price' => $itemTotal,
            'postage_price' => $this->firstMoney($row, ['postage_price', 'postagePrice', 'ShipCharge', 'shippingFee', 'deliveryFee']),
            'pay_charge' => $this->firstMoney($row, ['pay_charge', 'payCharge', 'PayCharge', 'settlementFee']),
            'total' => $total,
            'platform_extra' => $this->compactExtra([
                'OrderId' => $orderId,
                'myid' => $orderId,
                'OrderTime' => $orderTime,
                'OrderStatus' => $status,
                'ShipName' => $customerName,
                'senderKana' => $customerKana,
                'ShipAddress1' => $address,
                'ShipZipCode' => str_replace("'", '', $customerZip),
                'ShipPhoneNumber' => $customerPhone,
                'PayMethodName' => $this->firstString($row, ['pay_method', 'payMethod', 'PayMethodName', 'settlementName', 'paymentMethod']),
                'ShipCharge' => $this->firstMoney($row, ['postage_price', 'postagePrice', 'ShipCharge', 'shippingFee', 'deliveryFee']),
                'PayCharge' => $this->firstMoney($row, ['pay_charge', 'payCharge', 'PayCharge', 'settlementFee']),
                'TotalPrice' => $total,
                'cdate' => date('Y-m-d H:i'),
                'user_name' => $operator,
                'beizhu' => '未处理的订单',
                'raw' => $row,
            ]),
            'items' => [],
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function mapItem(array $item, array $order, int $lineNumber, string $operator): array
    {
        $orderId = $this->orderId($order);
        $orderTime = $this->dateString($this->firstString($order, [
            'order_date',
            'orderDate',
            'OrderDate',
            'order_time',
            'orderTime',
            'OrderTime',
            'paymentDate',
            'paid_at',
            'created_at',
        ]));
        $quantity = max(1, $this->firstInt($item, ['quantity', 'qty', 'unit', 'Unit', 'orderQty', 'OrderQty']));
        $unitPrice = $this->firstMoney($item, ['unit_price', 'unitPrice', 'UnitPrice', 'price', 'Price', 'itemPrice']);
        $lineTotal = $this->firstMoney($item, ['line_total', 'lineTotal', 'LineTotal', 'itemTotal', 'ItemTotal', 'totalPrice']);
        if ($lineTotal <= 0 && $unitPrice > 0) {
            $lineTotal = $unitPrice * $quantity;
        }
        $orderDetailId = $this->firstString($item, [
            'order_detail_id',
            'orderDetailId',
            'OrderDetailId',
            'detailId',
            'DetailId',
            'lineOrderNo',
        ]);
        $lineId = $this->firstString($item, ['line_id', 'lineId', 'LineId', 'lineNo', 'LineNo', 'seq', 'Seq']);
        if ($lineId === '') {
            $lineId = (string) $lineNumber;
        }
        $itemCode = $this->firstString($item, [
            'item_code',
            'itemCode',
            'ItemCode',
            'sellerItemCode',
            'SellerItemCode',
            'itemManagementId',
            'ItemManagementId',
            'goodsNo',
            'GoodsNo',
            'itemNo',
            'ItemNo',
            'lotnumber',
        ]);
        $lotNumber = $this->firstString($item, ['lotnumber', 'lot_number', 'goodsNo', 'GoodsNo', 'itemNo', 'ItemNo', 'item_id', 'ItemId']);
        $image = $this->firstString($item, [
            'image',
            'image_url',
            'imageUrl',
            'main_image',
            'mainImage',
            'goodsImage',
            'itemImage',
            'ItemImage',
            'sku_image',
            'skuImage',
        ]);
        $status = $this->firstString($order, ['order_status', 'orderStatus', 'OrderStatus', 'shippingStatus', 'status', 'Status']);
        $option = $this->firstString($item, [
            'option',
            'options',
            'optionInfo',
            'itemOption',
            'ItemOption',
            'sku',
            'skuName',
            'variation',
        ]);

        return [
            'order_detail_id' => $orderDetailId,
            'line_id' => $lineId,
            'item_code' => $itemCode,
            'lot_number' => $lotNumber,
            'item_management_id' => $this->firstString($item, ['item_management_id', 'itemManagementId', 'ItemManagementId', 'sellerItemCode', 'SellerItemCode']) ?: $itemCode,
            'title' => $this->firstString($item, ['title', 'itemName', 'ItemName', 'goodsName', 'GoodsName', 'productName', 'ProductName']),
            'option' => $option,
            'quantity' => $quantity,
            'source_type' => 'pending',
            'purchase_status' => '未处理的订单',
            'unit_price' => $unitPrice,
            'postage_price' => $this->firstMoney($item, ['postage_price', 'postagePrice', 'ShipCharge', 'shippingFee', 'deliveryFee'])
                ?: $this->firstMoney($order, ['postage_price', 'postagePrice', 'ShipCharge', 'shippingFee', 'deliveryFee']),
            'pay_charge' => $this->firstMoney($item, ['pay_charge', 'payCharge', 'PayCharge', 'settlementFee'])
                ?: $this->firstMoney($order, ['pay_charge', 'payCharge', 'PayCharge', 'settlementFee']),
            'line_total' => $lineTotal,
            'image' => $image,
            'main_image' => $image,
            'sku_image' => $this->firstString($item, ['sku_image', 'skuImage', 'SkuImage']) ?: $image,
            'material' => $this->firstString($item, ['material']),
            'weight' => $this->firstMoney($item, ['weight']),
            'comment' => $this->firstString($item, ['comment', 'memo', 'buyerMemo', 'sellerMemo']),
            'platform_extra' => $this->compactExtra([
                'OrderId' => $orderId,
                'myid' => $orderId,
                'LineId' => $lineId,
                'orderDetailId' => $orderDetailId,
                'ItemId' => $lotNumber,
                'ItemManagerId' => $itemCode,
                'Quantity' => $quantity,
                'SubCodeOption' => $option,
                'OrderTime' => $orderTime,
                'OrderStatus' => $status,
                'UnitPrice' => $unitPrice,
                'TotalPrice' => $this->firstMoney($order, ['total_price', 'totalPrice', 'TotalPrice', 'orderTotal', 'OrderTotal', 'settlementAmount']),
                'cdate' => date('Y-m-d H:i'),
                'user_name' => $operator,
                'beizhu' => '未处理的订单',
                'EntryPoint' => $this->qoo10ItemUrl($lotNumber),
                'zhutu' => $image,
                'skuimg' => $this->firstString($item, ['sku_image', 'skuImage', 'SkuImage']) ?: $image,
                'raw' => $item,
            ]),
        ];
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $config @return array<int, array<string, mixed>> */
    private function itemRows(array $row, array $config): array
    {
        $path = $this->configValue($config, ['items_path', 'order_items_path']);
        if ($path !== '') {
            return $this->listOfArrays($this->valueAtPath($row, $path));
        }

        foreach (['items', 'Items', 'orderItems', 'OrderItems', 'OrderItemList', 'itemList', 'ItemList', 'details', 'Details', 'lines'] as $key) {
            $items = $this->listOfArrays($this->valueAtPath($row, $key));
            if ($items !== []) {
                return $items;
            }
        }

        return [];
    }

    /** @param array<string, mixed> $row */
    private function hasFlatItemData(array $row): bool
    {
        return $this->firstString($row, [
            'order_detail_id',
            'orderDetailId',
            'item_code',
            'itemCode',
            'sellerItemCode',
            'goodsNo',
            'lotnumber',
            'itemName',
            'goodsName',
            'quantity',
            'unit',
        ]) !== '';
    }

    /** @param array<string, mixed> $row */
    private function orderId(array $row): string
    {
        return $this->firstString($row, [
            'platform_order_id',
            'order_id',
            'orderId',
            'OrderId',
            'order_no',
            'orderNo',
            'OrderNo',
            'order_number',
            'orderNumber',
            'OrderNumber',
            'orderSn',
            'OrderSn',
            'ShippingNo',
        ]);
    }

    private function qoo10ItemUrl(string $lotNumber): string
    {
        $lotNumber = trim($lotNumber);
        if ($lotNumber === '') {
            return '';
        }

        return 'https://www.qoo10.jp/g/' . rawurlencode($lotNumber);
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $keys
     */
    private function firstString(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $this->valueByKey($source, $key);
            if (!is_scalar($value)) {
                continue;
            }
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $keys
     */
    private function firstMoney(array $source, array $keys): float
    {
        $text = $this->firstString($source, $keys);
        if ($text === '') {
            return 0.0;
        }

        $normalized = preg_replace('/[^\d.\-]/', '', str_replace(',', '', $text));
        return is_string($normalized) && $normalized !== '' ? (float) $normalized : 0.0;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $keys
     */
    private function firstInt(array $source, array $keys): int
    {
        return (int) round($this->firstMoney($source, $keys));
    }

    /** @param array<string, mixed> $source */
    private function valueByKey(array $source, string $key): mixed
    {
        if (array_key_exists($key, $source)) {
            return $source[$key];
        }

        $needle = $this->normalizeKey($key);
        foreach ($source as $sourceKey => $value) {
            if ($this->normalizeKey((string) $sourceKey) === $needle) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $key));
    }

    /** @param array<string, mixed> $source */
    private function valueAtPath(array $source, string $path): mixed
    {
        $current = $source;
        foreach (explode('.', $path) as $part) {
            if (!is_array($current)) {
                return null;
            }
            $value = $this->valueByKey($current, $part);
            if ($value === null) {
                return null;
            }
            $current = $value;
        }

        return $current;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listOfArrays(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * @param array<string, mixed> $source
     * @return array<int, array<string, mixed>>
     */
    private function findFirstList(array $source, int $depth = 0): array
    {
        if ($depth > 4) {
            return [];
        }

        $rows = $this->listOfArrays($source);
        if ($rows !== []) {
            return $rows;
        }

        foreach ($source as $value) {
            if (!is_array($value)) {
                continue;
            }
            $rows = $this->findFirstList($value, $depth + 1);
            if ($rows !== []) {
                return $rows;
            }
        }

        return [];
    }

    private function dateString(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($raw))->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            $timestamp = strtotime($raw);
            return $timestamp === false ? '' : date('Y-m-d H:i:s', $timestamp);
        }
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function compactExtra(array $extra): array
    {
        $clean = [];
        foreach ($extra as $key => $value) {
            if ($key === 'raw') {
                $clean[$key] = $value;
                continue;
            }
            if ($value === '' || $value === null) {
                continue;
            }
            $clean[$key] = $value;
        }

        return $clean;
    }
}
