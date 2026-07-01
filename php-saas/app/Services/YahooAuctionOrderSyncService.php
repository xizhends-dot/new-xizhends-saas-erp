<?php

declare(strict_types=1);

namespace Xizhen\Services;

final class YahooAuctionOrderSyncService extends AbstractPlatformOrderSyncService
{
    private const DEFAULT_PAGE_SIZE = 100;
    private const DEFAULT_MAX_PAGES = 1;

    public function platformCode(): string
    {
        return 'yp';
    }

    public function platformName(): string
    {
        return 'Yahoo Auction';
    }

    /**
     * @return array{ok: bool, message: string, searched: int, inserted: int, updated: int, skipped: int, items_inserted: int, items_updated: int}
     */
    public function sync(string $tenantKey, int $storeId, int $days, string $operator): array
    {
        $store = $this->store->store($tenantKey, $storeId);
        if (!$store || (string) ($store['platform'] ?? '') !== 'yp') {
            return $this->result(false, '请选择 Yahoo Auction 店铺后再同步。');
        }

        $config = $this->apiConfig($store);
        $endpoint = $this->configValue($config, ['endpoint', 'orders_endpoint', 'api_endpoint', 'url'], 'YAHOO_AUCTION_API_ENDPOINT');
        if ($endpoint === '') {
            return $this->markFailure($tenantKey, $storeId, 'Yahoo Auction API 未配置：缺少 endpoint。');
        }

        $credentials = $this->credentials($config);
        if (!$this->hasAuthConfig($config, $credentials)) {
            return $this->markFailure($tenantKey, $storeId, 'Yahoo Auction API 未配置：缺少 token/client_id/client_secret/refresh_token 等鉴权配置。');
        }

        $days = max(1, min(30, $days));
        $range = $this->dateRange($days);

        try {
            $ordersRaw = $this->fetchOrders($endpoint, $config, $credentials, $range, $store);
            $searched = count($ordersRaw);
            if ($searched === 0) {
                $message = 'Yahoo Auction 没有需要同步的订单。';
                $this->store->markStoreSync($tenantKey, $storeId, '同步完成', $message);
                return $this->result(true, $message);
            }

            $orders = [];
            $skipped = 0;
            foreach ($ordersRaw as $rawOrder) {
                $order = $this->mapOrder($rawOrder, $store, $config, $operator);
                if ($order === null) {
                    $skipped++;
                    continue;
                }
                $orders[] = $order;
            }

            if (!$orders) {
                $message = 'Yahoo Auction API 返回数据缺少可识别的订单号，未写入订单。';
                $this->store->markStoreSync($tenantKey, $storeId, '同步异常', $message);
                return $this->result(false, $message, ['skipped' => $skipped], $searched);
            }

            $summary = ['inserted' => 0, 'updated' => 0, 'skipped' => $skipped, 'items_inserted' => 0, 'items_updated' => 0];
            foreach (array_chunk($orders, 100) as $chunk) {
                $step = $this->store->upsertPlatformOrders($tenantKey, $chunk, $operator);
                foreach ($summary as $key => $_) {
                    $summary[$key] += (int) ($step[$key] ?? 0);
                }
            }

            $message = sprintf(
                'Yahoo Auction 同步完成：检索 %d 单，新增 %d 单，更新 %d 单，新增商品 %d 件，更新商品 %d 件，跳过 %d 单。',
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
            $message = 'Yahoo Auction 同步失败：' . $error->getMessage();
            $this->store->markStoreSync($tenantKey, $storeId, '同步异常', $message);
            return $this->result(false, $message);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array{token: string, client_id: string, client_secret: string, refresh_token: string, token_endpoint: string, api_key: string}
     */
    private function credentials(array $config): array
    {
        return [
            'token' => $this->configValue($config, ['token', 'access_token', 'bearer_token'], 'YAHOO_AUCTION_API_TOKEN'),
            'client_id' => $this->configValue($config, ['client_id', 'clientId', 'app_id', 'appid'], 'YAHOO_AUCTION_CLIENT_ID'),
            'client_secret' => $this->configValue($config, ['client_secret', 'clientSecret', 'app_secret', 'secret'], 'YAHOO_AUCTION_CLIENT_SECRET'),
            'refresh_token' => $this->configValue($config, ['refresh_token', 'refreshToken'], 'YAHOO_AUCTION_REFRESH_TOKEN'),
            'token_endpoint' => $this->configValue($config, ['token_endpoint', 'oauth_token_endpoint'], 'YAHOO_AUCTION_TOKEN_ENDPOINT'),
            'api_key' => $this->configValue($config, ['api_key', 'apiKey'], 'YAHOO_AUCTION_API_KEY'),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array{token: string, client_id: string, client_secret: string, refresh_token: string, token_endpoint: string, api_key: string} $credentials
     */
    private function hasAuthConfig(array $config, array $credentials): bool
    {
        $auth = strtolower($this->configValue($config, ['auth', 'auth_type']));
        if ($auth === 'none') {
            return true;
        }

        if ($credentials['token'] !== '' || $credentials['api_key'] !== '') {
            return true;
        }

        if ($credentials['client_id'] !== '' && $credentials['client_secret'] !== '') {
            return true;
        }

        return $credentials['refresh_token'] !== ''
            && $credentials['token_endpoint'] !== ''
            && $credentials['client_id'] !== ''
            && $credentials['client_secret'] !== '';
    }

    /**
     * @param array<string, mixed> $config
     * @param array{token: string, client_id: string, client_secret: string, refresh_token: string, token_endpoint: string, api_key: string} $credentials
     * @param array{from: \DateTimeImmutable, to: \DateTimeImmutable} $range
     * @param array<string, mixed> $store
     * @return array<int, array<string, mixed>>
     */
    private function fetchOrders(string $endpoint, array $config, array $credentials, array $range, array $store): array
    {
        $token = $this->accessToken($config, $credentials);
        $page = max(1, (int) ($config['page_start'] ?? 1));
        $maxPages = max(1, (int) ($config['max_pages'] ?? self::DEFAULT_MAX_PAGES));
        $pageParam = $this->configValue($config, ['page_param']);
        $cursorParam = $this->configValue($config, ['cursor_param']);
        $nextCursorPath = $this->configValue($config, ['next_cursor_path']);
        $cursor = '';
        $all = [];

        for ($i = 0; $i < $maxPages; $i++) {
            $options = $this->requestOptions($config, $credentials, $token, $range, $store, $page, $cursor);
            $data = $this->requestJson($endpoint, $options);
            $batch = $this->extractOrderList($data, $config);
            foreach ($batch as $order) {
                $all[] = $order;
            }

            $nextCursor = $nextCursorPath !== '' ? $this->stringValue($this->valueAtPath($data, $nextCursorPath)) : '';
            if ($cursorParam !== '' && $nextCursor !== '' && $nextCursor !== $cursor) {
                $cursor = $nextCursor;
                continue;
            }

            if ($pageParam === '') {
                break;
            }
            if (!$batch || count($batch) < $this->pageSize($config)) {
                break;
            }
            $page++;
        }

        return $all;
    }

    /**
     * @param array<string, mixed> $config
     * @param array{token: string, client_id: string, client_secret: string, refresh_token: string, token_endpoint: string, api_key: string} $credentials
     */
    private function accessToken(array $config, array $credentials): string
    {
        if ($credentials['token'] !== '') {
            return $credentials['token'];
        }

        if ($credentials['token_endpoint'] === '' || $credentials['refresh_token'] === '') {
            return '';
        }

        $data = $this->requestJson($credentials['token_endpoint'], [
            'method' => 'POST',
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $credentials['refresh_token'],
                'client_id' => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
            ],
        ]);

        $token = $this->firstString($data, ['access_token', 'token', 'data.access_token']);
        if ($token === '') {
            throw new \RuntimeException('token_endpoint 未返回 access_token。');
        }

        return $token;
    }

    /**
     * @param array<string, mixed> $config
     * @param array{token: string, client_id: string, client_secret: string, refresh_token: string, token_endpoint: string, api_key: string} $credentials
     * @param array{from: \DateTimeImmutable, to: \DateTimeImmutable} $range
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    private function requestOptions(array $config, array $credentials, string $token, array $range, array $store, int $page, string $cursor): array
    {
        $method = strtoupper($this->configValue($config, ['method']) ?: 'GET');
        $params = array_replace(
            $this->arrayConfig($config, 'query'),
            $this->arrayConfig($config, 'params'),
            $this->dateParams($config, $range)
        );

        $storeParam = $this->configValue($config, ['store_param', 'seller_param', 'account_param']);
        if ($storeParam !== '') {
            $params[$storeParam] = $this->configValue($config, ['seller_id', 'sellerId', 'account_id', 'accountId', 'shop_id', 'shopId'])
                ?: (string) ($store['legacy_dpid'] ?? $store['name'] ?? '');
        }

        $pageParam = $this->configValue($config, ['page_param']);
        if ($pageParam !== '') {
            $params[$pageParam] = $page;
        }

        $perPageParam = $this->configValue($config, ['per_page_param', 'page_size_param', 'limit_param']);
        if ($perPageParam !== '') {
            $params[$perPageParam] = $this->pageSize($config);
        }

        $cursorParam = $this->configValue($config, ['cursor_param']);
        if ($cursorParam !== '' && $cursor !== '') {
            $params[$cursorParam] = $cursor;
        }

        $headers = $this->headers($config, $credentials, $token, $params);
        $options = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => max(1, (int) ($config['timeout'] ?? 30)),
            'connect_timeout' => max(1, (int) ($config['connect_timeout'] ?? 15)),
        ];

        if ($method === 'GET') {
            $options['query'] = $params;
            return $options;
        }

        if (strtolower($this->configValue($config, ['body_format'])) === 'json') {
            $headers[] = 'Content-Type: application/json; charset=utf-8';
            $options['headers'] = $headers;
            $options['body'] = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        } else {
            $options['body'] = $params;
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $config
     * @param array{from: \DateTimeImmutable, to: \DateTimeImmutable} $range
     * @return array<string, string>
     */
    private function dateParams(array $config, array $range): array
    {
        $dateFilter = strtolower($this->configValue($config, ['date_filter']));
        if (in_array($dateFilter, ['0', 'false', 'no', 'off'], true)) {
            return [];
        }

        $format = $this->configValue($config, ['date_format']) ?: 'Y-m-d H:i:s';
        $fromParam = $this->configValue($config, ['from_param', 'start_param', 'date_from_param']) ?: 'from';
        $toParam = $this->configValue($config, ['to_param', 'end_param', 'date_to_param']) ?: 'to';

        return [
            $fromParam => $range['from']->format($format),
            $toParam => $range['to']->format($format),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array{token: string, client_id: string, client_secret: string, refresh_token: string, token_endpoint: string, api_key: string} $credentials
     * @param array<string, mixed> $params
     * @return array<int, string>
     */
    private function headers(array $config, array $credentials, string $token, array &$params): array
    {
        $headers = [];
        foreach ($this->arrayConfig($config, 'headers') as $name => $value) {
            if (is_string($name) && trim($name) !== '' && is_scalar($value)) {
                $headers[] = trim($name) . ': ' . trim((string) $value);
            } elseif (is_scalar($value) && trim((string) $value) !== '') {
                $headers[] = trim((string) $value);
            }
        }

        $auth = strtolower($this->configValue($config, ['auth', 'auth_type']));
        $scheme = $this->configValue($config, ['auth_scheme']) ?: 'Bearer';
        if ($token !== '' && $auth !== 'none') {
            $headers[] = 'Authorization: ' . $scheme . ' ' . $token;
        } elseif ($auth === 'basic' && $credentials['client_id'] !== '' && $credentials['client_secret'] !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode($credentials['client_id'] . ':' . $credentials['client_secret']);
        }

        if ($credentials['api_key'] !== '') {
            $apiKeyQuery = $this->configValue($config, ['api_key_query']);
            if ($apiKeyQuery !== '') {
                $params[$apiKeyQuery] = $credentials['api_key'];
            } else {
                $apiKeyHeader = $this->configValue($config, ['api_key_header']) ?: 'X-API-Key';
                $headers[] = $apiKeyHeader . ': ' . $credentials['api_key'];
            }
        }

        $clientIdQuery = $this->configValue($config, ['client_id_query']);
        if ($clientIdQuery !== '' && $credentials['client_id'] !== '') {
            $params[$clientIdQuery] = $credentials['client_id'];
        }

        $clientSecretQuery = $this->configValue($config, ['client_secret_query']);
        if ($clientSecretQuery !== '' && $credentials['client_secret'] !== '') {
            $params[$clientSecretQuery] = $credentials['client_secret'];
        }

        if ($token === '' && $auth !== 'none' && $auth !== 'basic') {
            $clientIdHeader = $this->configValue($config, ['client_id_header']) ?: 'X-Client-Id';
            if ($credentials['client_id'] !== '' && $clientIdQuery === '') {
                $headers[] = $clientIdHeader . ': ' . $credentials['client_id'];
            }

            $clientSecretHeader = $this->configValue($config, ['client_secret_header']) ?: 'X-Client-Secret';
            if ($credentials['client_secret'] !== '' && $clientSecretQuery === '') {
                $headers[] = $clientSecretHeader . ': ' . $credentials['client_secret'];
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $config
     * @return array<int, array<string, mixed>>
     */
    private function extractOrderList(array $data, array $config): array
    {
        $paths = array_values(array_filter([
            $this->configValue($config, ['orders_path', 'list_path', 'data_path']),
            'orders',
            'order',
            'data.orders',
            'data.items',
            'data.list',
            'data',
            'items',
            'list',
            'results',
            'records',
        ]));

        if ($this->isList($data)) {
            return array_values(array_filter($data, 'is_array'));
        }

        foreach ($paths as $path) {
            $value = $this->valueAtPath($data, $path);
            if (is_array($value) && $this->isList($value)) {
                return array_values(array_filter($value, 'is_array'));
            }
            if (is_array($value) && $this->looksLikeOrder($value)) {
                return [$value];
            }
        }

        return $this->looksLikeOrder($data) ? [$data] : [];
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $store
     * @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
    private function mapOrder(array $raw, array $store, array $config, string $operator): ?array
    {
        $itemsRaw = $this->extractItems($raw, $config);
        $firstItem = is_array($itemsRaw[0] ?? null) ? $itemsRaw[0] : [];
        $orderId = $this->firstString($raw, ['platform_order_id', 'order_id', 'orderId', 'OrderId', 'id', 'transaction_id', 'transactionId', 'auction_order_id', 'auctionOrderId'])
            ?: $this->firstString($firstItem, ['order_id', 'orderId', 'OrderId']);
        $lotNumber = $this->firstString($raw, ['lotnumber', 'lot_number', 'auction_id', 'auctionId', 'YahooAuctionId', 'ItemYahooAucId'])
            ?: $this->firstString($firstItem, ['lotnumber', 'lot_number', 'auction_id', 'auctionId', 'YahooAuctionId', 'ItemYahooAucId']);

        if ($orderId === '') {
            $orderId = $lotNumber;
        }
        if ($orderId === '') {
            return null;
        }

        $orderTime = $this->formatDate($this->firstRawValue($raw, ['order_date', 'orderDate', 'OrderTime', 'order_time', 'created_at', 'createdAt', 'closed_at', 'closedAt', 'PayDate']));
        $status = $this->firstString($raw, ['status', 'order_status', 'orderStatus', 'OrderStatus', 'transaction_status', 'seller_status']);
        $customer = $this->customer($raw);
        $importedAt = date('Y-m-d H:i');
        $mappedItems = [];

        foreach ($itemsRaw as $index => $itemRaw) {
            if (!is_array($itemRaw)) {
                continue;
            }
            $mappedItems[] = $this->mapItem($itemRaw, $raw, $orderId, $lotNumber, $index, $orderTime, $operator);
        }

        if (!$mappedItems) {
            $mappedItems[] = $this->mapItem($raw, $raw, $orderId, $lotNumber, 0, $orderTime, $operator);
        }

        $quantityDetail = [];
        foreach ($mappedItems as $item) {
            $quantityDetail[] = 'L' . (string) ($item['line_id'] ?? '1') . '=' . (int) ($item['quantity'] ?? 0);
        }

        $platformExtra = [
            'OrderId' => $orderId,
            'myid' => $orderId,
            'OrderTime' => $orderTime,
            'OrderStatus' => $status,
            'lotnumber' => $lotNumber,
            'EntryPoint' => $this->auctionUrl($lotNumber),
            'senderName' => $customer['name'],
            'senderKana' => $customer['kana'],
            'shipping_postal_code' => $customer['zip'],
            'shipping_address_1' => $customer['address'],
            'senderPhoneNumber1' => $customer['phone'],
            'mailAddress' => $customer['mail'],
            'settlementName' => $this->firstString($raw, ['pay_method', 'payMethod', 'PayMethodName', 'settlementName']),
            'deliveryName' => $this->firstString($raw, ['ship_method', 'shipMethod', 'ShipMethodName', 'deliveryName']),
            'totalItemPrice' => $this->money($this->firstRawValue($raw, ['total_item_price', 'totalItemPrice', 'subtotal', 'item_total'])),
            'postagePrice' => $this->money($this->firstRawValue($raw, ['postage_price', 'postagePrice', 'shipping_fee', 'ShipCharge'])),
            'QuantityDetail' => implode('&', $quantityDetail),
            'cdate' => $importedAt,
            'user_name' => $operator,
            'beizhu' => '未处理的订单',
            'raw' => $raw,
        ];

        return [
            'platform' => 'yp',
            'platform_order_id' => $orderId,
            'order_detail_id' => $this->firstString($raw, ['order_detail_id', 'orderDetailId', 'detail_id']),
            'store_id' => (int) ($store['id'] ?? 0),
            'store' => (string) ($store['name'] ?? ''),
            'order_date' => $orderTime,
            'status' => $status,
            'customer' => $customer,
            'pay_method' => $platformExtra['settlementName'],
            'ship_method' => $platformExtra['deliveryName'],
            'total_item_price' => (float) $platformExtra['totalItemPrice'],
            'postage_price' => (float) $platformExtra['postagePrice'],
            'pay_charge' => $this->money($this->firstRawValue($raw, ['pay_charge', 'payCharge', 'PayCharge', 'fee'])),
            'total' => $this->money($this->firstRawValue($raw, ['total', 'total_price', 'totalPrice', 'TotalPrice', 'amount_total'])),
            'platform_extra' => $platformExtra,
            'items' => $mappedItems,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function mapItem(array $item, array $order, string $orderId, string $orderLotNumber, int $index, string $orderTime, string $operator): array
    {
        $lineId = $this->firstString($item, ['line_id', 'lineId', 'LineId']) ?: (string) ($index + 1);
        $orderDetailId = $this->firstString($item, ['order_detail_id', 'orderDetailId', 'detail_id', 'OrderDetailId']) ?: $this->firstString($order, ['order_detail_id', 'orderDetailId', 'detail_id']);
        $lotNumber = $this->firstString($item, ['lotnumber', 'lot_number', 'auction_id', 'auctionId', 'YahooAuctionId', 'ItemYahooAucId']) ?: $orderLotNumber;
        $itemCode = $this->firstString($item, ['item_code', 'itemCode', 'ItemId', 'ItemYahooAucId', 'product_id', 'ProductId']) ?: $lotNumber;
        $quantity = $this->quantity($this->firstRawValue($item, ['quantity', 'qty', 'unit', 'Quantity', 'units']));
        $lineTotal = $this->money($this->firstRawValue($item, ['line_total', 'lineTotal', 'totalItemPrice', 'item_total', 'amount', 'price']));
        $unitPrice = $this->money($this->firstRawValue($item, ['unit_price', 'unitPrice', 'UnitPrice', 'current_price', 'CurrentPrice']));
        if ($unitPrice <= 0 && $lineTotal > 0) {
            $unitPrice = $lineTotal / max(1, $quantity);
        }

        $extra = [
            'OrderId' => $orderId,
            'myid' => $orderId,
            'LineId' => $lineId,
            'orderDetailId' => $orderDetailId,
            'lotnumber' => $lotNumber,
            'ItemId' => $itemCode,
            'Quantity' => $quantity,
            'UnitPrice' => $unitPrice,
            'TotalPrice' => $lineTotal,
            'OrderTime' => $orderTime,
            'OrderStatus' => $this->firstString($order, ['status', 'order_status', 'orderStatus', 'OrderStatus']),
            'EntryPoint' => $this->auctionUrl($lotNumber),
            'zhutu' => $this->firstString($item, ['image', 'image_url', 'ImageUrl', 'thumbnail', 'zhutu', 'main_image']),
            'ItemOption' => $this->firstString($item, ['option', 'item_option', 'itemOption', 'ItemOption', 'SubCodeOption']),
            'cdate' => date('Y-m-d H:i'),
            'user_name' => $operator,
            'beizhu' => '未处理的订单',
            'raw' => $item,
        ];

        return [
            'order_detail_id' => $orderDetailId,
            'line_id' => $lineId,
            'item_code' => $itemCode,
            'lot_number' => $lotNumber,
            'item_management_id' => $this->firstString($item, ['item_management_id', 'itemManagementId', 'ItemManagerId']),
            'title' => $this->firstString($item, ['title', 'Title', 'product_title', 'item_title', 'name']),
            'option' => $extra['ItemOption'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'postage_price' => $this->money($this->firstRawValue($item, ['postage_price', 'postagePrice', 'shipping_fee', 'ShipCharge'])),
            'pay_charge' => $this->money($this->firstRawValue($item, ['pay_charge', 'payCharge', 'PayCharge', 'fee'])),
            'line_total' => $lineTotal,
            'purchase_status' => '未处理的订单',
            'image' => $extra['zhutu'],
            'main_image' => $extra['zhutu'],
            'sku_image' => $this->firstString($item, ['sku_image', 'skuimg', 'skuImage']),
            'platform_extra' => $extra,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $config
     * @return array<int, array<string, mixed>>
     */
    private function extractItems(array $raw, array $config): array
    {
        $paths = array_values(array_filter([
            $this->configValue($config, ['items_path']),
            'items',
            'Items',
            'order_items',
            'details',
            'lines',
            'itemList',
            'ItemList',
        ]));

        foreach ($paths as $path) {
            $value = $this->valueAtPath($raw, $path);
            if (is_array($value) && $this->isList($value)) {
                return array_values(array_filter($value, 'is_array'));
            }
        }

        return [$raw];
    }

    /** @param array<string, mixed> $raw @return array{name: string, kana: string, phone: string, zip: string, address: string, mail: string} */
    private function customer(array $raw): array
    {
        $address = $this->firstString($raw, ['customer.address', 'buyer_address', 'shipping_address', 'senderAddress', 'shipping_address_1', 'ShipAddress1']);
        if ($address === '') {
            $address = trim(implode('', array_filter([
                $this->firstString($raw, ['ShipPrefecture', 'shipping_state', 'prefecture']),
                $this->firstString($raw, ['ShipCity', 'shipping_city', 'city']),
                $this->firstString($raw, ['ShipAddress1', 'shipping_address_1', 'address1']),
                $this->firstString($raw, ['ShipAddress2', 'shipping_address_2', 'address2']),
            ])));
        }

        return [
            'name' => $this->firstString($raw, ['customer.name', 'buyer_name', 'buyerName', 'senderName', 'ShipName']),
            'kana' => $this->firstString($raw, ['customer.kana', 'senderKana', 'buyer_kana', 'buyerKana']),
            'phone' => $this->firstString($raw, ['customer.phone', 'buyer_phone', 'buyerPhone', 'senderPhoneNumber1', 'ShipPhoneNumber']),
            'zip' => $this->firstString($raw, ['customer.zip', 'buyer_zip', 'shipping_postal_code', 'senderZipCode', 'ShipZipCode']),
            'address' => $address,
            'mail' => $this->firstString($raw, ['customer.mail', 'customer.email', 'buyer_email', 'buyerEmail', 'mailAddress', 'BillMailAddress']),
        ];
    }

    /** @return array{from: \DateTimeImmutable, to: \DateTimeImmutable} */
    private function dateRange(int $days): array
    {
        $timezone = new \DateTimeZone('Asia/Tokyo');
        $to = new \DateTimeImmutable('now', $timezone);

        return [
            'from' => $to->modify("-{$days} days")->setTime(0, 0, 0),
            'to' => $to,
        ];
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    private function arrayConfig(array $config, string $key): array
    {
        $value = $config[$key] ?? [];
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /** @param array<string, mixed> $data */
    private function firstString(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $this->stringValue($this->valueAtPath($data, (string) $key));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** @param array<string, mixed> $data */
    private function firstRawValue(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = $this->valueAtPath($data, (string) $key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function valueAtPath(array $data, string $path): mixed
    {
        if ($path === '') {
            return null;
        }
        if (array_key_exists($path, $data)) {
            return $data[$path];
        }

        $current = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    private function stringValue(mixed $value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private function money(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return 0.0;
        }

        $normalized = preg_replace('/[^\d.\-]/', '', str_replace(',', '', $raw));
        return $normalized === '' || $normalized === '-' ? 0.0 : (float) $normalized;
    }

    private function quantity(mixed $value): int
    {
        $quantity = (int) $this->money($value);
        return $quantity > 0 ? $quantity : 1;
    }

    private function pageSize(array $config): int
    {
        return max(1, (int) ($config['page_size'] ?? $config['limit'] ?? self::DEFAULT_PAGE_SIZE));
    }

    /** @param array<mixed> $value */
    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /** @param array<string, mixed> $value */
    private function looksLikeOrder(array $value): bool
    {
        return $this->firstString($value, ['platform_order_id', 'order_id', 'orderId', 'OrderId', 'id', 'lotnumber', 'auction_id', 'auctionId']) !== '';
    }

    private function auctionUrl(string $lotNumber): string
    {
        $lotNumber = trim($lotNumber);
        return $lotNumber !== '' ? 'https://auctions.yahoo.co.jp/jp/auction/' . rawurlencode($lotNumber) : '';
    }

    private function formatDate(mixed $value, string $format = 'Y-m-d H:i:s'): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return '';
        }

        if (is_numeric($raw)) {
            $timestamp = (int) $raw;
            if ($timestamp > 0) {
                if ($timestamp > 9999999999) {
                    $timestamp = (int) floor($timestamp / 1000);
                }
                return (new \DateTimeImmutable('@' . $timestamp))
                    ->setTimezone(new \DateTimeZone('Asia/Tokyo'))
                    ->format($format);
            }
        }

        try {
            return (new \DateTimeImmutable($raw))
                ->setTimezone(new \DateTimeZone('Asia/Tokyo'))
                ->format($format);
        } catch (\Throwable) {
            $timestamp = strtotime($raw);
            return $timestamp !== false ? date($format, $timestamp) : '';
        }
    }
}
