<?php

declare(strict_types=1);

namespace Xizhen\Services;

final class YahooShopOrderSyncService extends AbstractPlatformOrderSyncService
{
    private const TOKEN_URL = 'https://auth.login.yahoo.co.jp/yconnect/v2/token';
    private const DEFAULT_API_BASE_URL = 'https://circus.shopping.yahooapis.jp';
    private const ORDER_LIST_LIMIT = 2000;
    private const DETAIL_BATCH_SIZE = 5;

    /** @var array<int, string> */
    private const ORDER_INFO_FIELDS = [
        'OrderId',
        'OrderTime',
        'OrderStatus',
        'EntryPoint',
        'ShipFirstName',
        'ShipFirstNameKana',
        'ShipLastName',
        'ShipLastNameKana',
        'ShipMethodName',
        'ShipAddress1',
        'ShipAddress2',
        'ShipCity',
        'ShipPrefecture',
        'ShipZipCode',
        'ShipPhoneNumber',
        'ShipRequestDate',
        'ShipRequestTime',
        'ShipNotes',
        'BillMailAddress',
        'PayMethodName',
        'PayStatus',
        'PayDate',
        'ShipCharge',
        'PayCharge',
        'TotalPrice',
        'SettleAmount',
        'SettlePayAmount',
        'UsePoint',
        'Discount',
        'GiftCardDiscount',
        'TotalMallCouponDiscount',
        'TotalImmediateBonusAmount',
        'LineId',
        'ItemId',
        'Title',
        'ItemOption',
        'Quantity',
        'SubCode',
        'SubCodeOption',
        'UnitPrice',
        'ImageId',
    ];

    public function platformCode(): string
    {
        return 'y';
    }

    public function platformName(): string
    {
        return 'Yahoo Shop';
    }

    /**
     * @return array{ok: bool, message: string, searched: int, inserted: int, updated: int, skipped: int, items_inserted: int, items_updated: int}
     */
    public function sync(string $tenantKey, int $storeId, int $days, string $operator): array
    {
        $store = $this->store->store($tenantKey, $storeId);
        if (!$store) {
            return $this->result(false, '请选择 Yahoo Shop 店铺后再同步。');
        }
        if ((string) ($store['platform'] ?? '') !== 'y') {
            return $this->markFailure($tenantKey, $storeId, '请选择 Yahoo Shop 店铺后再同步。');
        }

        $credentials = $this->credentials($store);
        $missing = $this->missingCredentialMessage($credentials);
        if ($missing !== '') {
            return $this->markFailure($tenantKey, $storeId, $missing);
        }

        $days = max(1, min(30, $days));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo'));
        $start = $now->modify("-{$days} days")->setTime(0, 0, 0);

        try {
            if ($credentials['access_token'] === '') {
                $token = $this->refreshAccessToken($credentials);
                $credentials['access_token'] = $token['access_token'];
            }

            $orderIds = $this->withTokenRetry(
                $credentials,
                fn (string $token): array => $this->searchOrderIds($credentials, $token, $start, $now)
            );

            if (!$orderIds) {
                $message = 'Yahoo Shop 没有需要同步的新订单。';
                $this->store->markStoreSync($tenantKey, $storeId, '同步完成', $message);
                return $this->result(true, $message);
            }

            $summary = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'items_inserted' => 0, 'items_updated' => 0];
            foreach (array_chunk($orderIds, self::DETAIL_BATCH_SIZE) as $batch) {
                $orders = [];
                foreach ($batch as $orderId) {
                    $detail = $this->withTokenRetry(
                        $credentials,
                        fn (string $token): array => $this->getOrderInfo($credentials, $token, $orderId)
                    );
                    $orders[] = $this->mapOrder($detail, $store, $credentials, $operator);
                }

                $step = $this->store->upsertPlatformOrders($tenantKey, $orders, $operator);
                foreach ($summary as $key => $_) {
                    $summary[$key] += (int) ($step[$key] ?? 0);
                }
            }

            $message = sprintf(
                'Yahoo Shop 同步完成：检索 %d 单，新增 %d 单，更新 %d 单，新增商品 %d 件，更新商品 %d 件，跳过 %d 单。',
                count($orderIds),
                $summary['inserted'],
                $summary['updated'],
                $summary['items_inserted'],
                $summary['items_updated'],
                $summary['skipped']
            );
            $this->store->markStoreSync($tenantKey, $storeId, '同步完成', $message);

            return $this->result(true, $message, $summary, count($orderIds));
        } catch (\Throwable $error) {
            return $this->markFailure($tenantKey, $storeId, 'Yahoo Shop 同步失败：' . $error->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $store
     * @return array{access_token: string, refresh_token: string, client_id: string, client_secret: string, seller_id: string, api_base_url: string}
     */
    private function credentials(array $store): array
    {
        $config = $this->apiConfig($store);
        $apiBaseUrl = $this->firstConfig($config, ['api_base_url', 'base_url', 'endpoint_base'], ['YAHOO_SHOP_API_BASE_URL']);

        return [
            'access_token' => $this->firstConfig($config, ['access_token', 'accessToken', 'AccessToken', 'token'], ['YAHOO_SHOP_ACCESS_TOKEN', 'YAHOO_ACCESS_TOKEN']),
            'refresh_token' => $this->firstConfig($config, ['refresh_token', 'refreshToken', 'RefreshToken'], ['YAHOO_SHOP_REFRESH_TOKEN', 'YAHOO_REFRESH_TOKEN']),
            'client_id' => $this->firstConfig($config, ['client_id', 'clientId', 'app_id', 'appId', 'AppID', 'appid', 'application_id'], ['YAHOO_SHOP_CLIENT_ID', 'YAHOO_SHOP_APP_ID', 'YAHOO_CLIENT_ID', 'YAHOO_APP_ID']),
            'client_secret' => $this->firstConfig($config, ['client_secret', 'clientSecret', 'app_secret', 'appSecret', 'Secret', 'secret'], ['YAHOO_SHOP_CLIENT_SECRET', 'YAHOO_SHOP_APP_SECRET', 'YAHOO_CLIENT_SECRET', 'YAHOO_APP_SECRET']),
            'seller_id' => $this->firstConfig($config, ['seller_id', 'sellerId', 'SellerId', 'store_account', 'storeAccount', 'store_id', 'shop_id', 'shopId', 'seller', 'dpid'], ['YAHOO_SHOP_SELLER_ID', 'YAHOO_SHOP_STORE_ACCOUNT', 'YAHOO_SELLER_ID']),
            'api_base_url' => $apiBaseUrl !== '' ? rtrim($apiBaseUrl, '/') : self::DEFAULT_API_BASE_URL,
        ];
    }

    /** @param array<string, string> $credentials */
    private function missingCredentialMessage(array $credentials): string
    {
        if ($credentials['seller_id'] === '') {
            return '缺少 Yahoo Shop API 配置：seller_id 或 store_account。';
        }
        if ($credentials['access_token'] !== '') {
            return '';
        }

        $missing = [];
        foreach (['refresh_token', 'client_id', 'client_secret'] as $key) {
            if ($credentials[$key] === '') {
                $missing[] = $key;
            }
        }

        return $missing
            ? '缺少 Yahoo Shop API 配置：access_token，或 refresh_token + client_id/app_id + client_secret。缺失项：' . implode(', ', $missing) . '。'
            : '';
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, string> $keys
     * @param array<int, string> $envNames
     */
    private function firstConfig(array $config, array $keys, array $envNames = []): string
    {
        $lower = [];
        foreach ($config as $key => $value) {
            $lower[strtolower((string) $key)] = $value;
        }

        foreach ($keys as $key) {
            $value = $config[$key] ?? $lower[strtolower($key)] ?? null;
            if (is_scalar($value)) {
                $value = trim((string) $value);
                if ($value !== '') {
                    return $value;
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
     * @param array<string, string> $credentials
     * @return array{access_token: string, refresh_token?: string, expires_in?: int}
     */
    private function refreshAccessToken(array $credentials): array
    {
        $data = $this->requestJson(self::TOKEN_URL, [
            'method' => 'POST',
            'headers' => [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode($credentials['client_id'] . ':' . $credentials['client_secret']),
            ],
            'body' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $credentials['refresh_token'],
                'client_id' => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
            ],
        ]);

        $accessToken = trim((string) ($data['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new \RuntimeException('Yahoo token endpoint 未返回 access_token。');
        }

        return [
            'access_token' => $accessToken,
            'refresh_token' => trim((string) ($data['refresh_token'] ?? '')),
            'expires_in' => (int) ($data['expires_in'] ?? 0),
        ];
    }

    /**
     * @template T
     * @param array<string, string> $credentials
     * @param callable(string): T $callback
     * @return T
     */
    private function withTokenRetry(array &$credentials, callable $callback): mixed
    {
        try {
            return $callback($credentials['access_token']);
        } catch (\RuntimeException $error) {
            if (!$this->isAuthError($error) || !$this->canRefresh($credentials)) {
                throw $error;
            }

            $token = $this->refreshAccessToken($credentials);
            $credentials['access_token'] = $token['access_token'];
            if (($token['refresh_token'] ?? '') !== '') {
                $credentials['refresh_token'] = $token['refresh_token'];
            }

            return $callback($credentials['access_token']);
        }
    }

    /** @param array<string, string> $credentials */
    private function canRefresh(array $credentials): bool
    {
        return $credentials['refresh_token'] !== ''
            && $credentials['client_id'] !== ''
            && $credentials['client_secret'] !== '';
    }

    private function isAuthError(\RuntimeException $error): bool
    {
        $message = $error->getMessage();

        return str_contains($message, 'HTTP 400')
            || str_contains($message, 'HTTP 401')
            || str_contains($message, 'AccessToken')
            || str_contains($message, 'expired')
            || str_contains($message, 'valid credentials');
    }

    /**
     * @param array<string, string> $credentials
     * @return array<int, string>
     */
    private function searchOrderIds(array $credentials, string $token, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $fields = implode(',', ['OrderId', 'OrderTime', 'OrderStatus', 'PayMethod']);
        $limit = (string) self::ORDER_LIST_LIMIT;
        $sellerId = $this->xml($credentials['seller_id']);
        $body = <<<XML
<Req>
 <Search>
  <Result>{$limit}</Result>
  <Start>1</Start>
  <Sort>+order_time</Sort>
  <Condition>
   <OrderTimeFrom>{$start->format('YmdHis')}</OrderTimeFrom>
   <OrderTimeTo>{$end->format('YmdHis')}</OrderTimeTo>
   <OrderStatus>2</OrderStatus>
   <PayStatus>1</PayStatus>
   <SettleStatus>2,3,5</SettleStatus>
   <ShipStatus>1</ShipStatus>
   <IsSeen>true</IsSeen>
  </Condition>
  <Field>{$fields}</Field>
 </Search>
 <SellerId>{$sellerId}</SellerId>
</Req>
XML;

        $decoded = $this->requestYahoo($credentials['api_base_url'] . '/ShoppingWebService/V1/orderList', $token, $body);
        $this->ensureYahooOk($decoded);
        $search = $this->arrayAt($decoded, [
            'Result.Search',
            'ResultSet.Result.Search',
            'ResultSet.Search',
            'Search',
        ]);
        if (!$search) {
            return [];
        }

        $total = (int) $this->value($search, ['TotalCount']);
        if ($total > self::ORDER_LIST_LIMIT) {
            throw new \RuntimeException('Yahoo Shop orderList 返回超过 ' . self::ORDER_LIST_LIMIT . ' 单，请缩短同步天数后重试。');
        }

        $rows = $this->listValue($search['OrderInfo'] ?? []);
        $orderIds = [];
        foreach ($rows as $row) {
            $orderId = $this->value($row, ['OrderId']);
            if ($orderId !== '') {
                $orderIds[] = $orderId;
            }
        }

        return array_values(array_unique($orderIds));
    }

    /**
     * @param array<string, string> $credentials
     * @return array<string, mixed>
     */
    private function getOrderInfo(array $credentials, string $token, string $orderId): array
    {
        $sellerId = $this->xml($credentials['seller_id']);
        $orderIdXml = $this->xml($orderId);
        $fields = implode(',', self::ORDER_INFO_FIELDS);
        $body = <<<XML
<Req>
 <Target>
  <OrderId>{$orderIdXml}</OrderId>
  <Field>{$fields}</Field>
 </Target>
 <SellerId>{$sellerId}</SellerId>
</Req>
XML;

        $decoded = $this->requestYahoo($credentials['api_base_url'] . '/ShoppingWebService/V1/orderInfo', $token, $body);
        $this->ensureYahooOk($decoded);
        $orderInfo = $this->arrayAt($decoded, [
            'ResultSet.Result.OrderInfo',
            'ResultSet.OrderInfo',
            'Result.OrderInfo',
            'OrderInfo',
        ]);

        if (!$orderInfo) {
            throw new \RuntimeException('Yahoo Shop orderInfo 未返回订单 ' . $orderId . ' 的 OrderInfo。');
        }

        return $orderInfo;
    }

    /**
     * @param array<string, mixed> $store
     * @param array<string, string> $credentials
     * @param array<string, mixed> $orderInfo
     * @return array<string, mixed>
     */
    private function mapOrder(array $orderInfo, array $store, array $credentials, string $operator): array
    {
        $items = $this->orderItems($orderInfo);
        $orderId = $this->value($orderInfo, ['OrderId']);
        $orderTime = $this->formatDate($this->value($orderInfo, ['OrderTime']));
        $status = $this->orderStatusName($this->value($orderInfo, ['OrderStatus']));
        $shipLastName = $this->value($orderInfo, ['Ship.ShipLastName', 'ShipLastName']);
        $shipFirstName = $this->value($orderInfo, ['Ship.ShipFirstName', 'ShipFirstName']);
        $shipLastKana = $this->value($orderInfo, ['Ship.ShipLastNameKana', 'ShipLastNameKana']);
        $shipFirstKana = $this->value($orderInfo, ['Ship.ShipFirstNameKana', 'ShipFirstNameKana']);
        $shipPrefecture = $this->value($orderInfo, ['Ship.ShipPrefecture', 'ShipPrefecture']);
        $shipCity = $this->value($orderInfo, ['Ship.ShipCity', 'ShipCity']);
        $shipAddress1 = $this->value($orderInfo, ['Ship.ShipAddress1', 'ShipAddress1']);
        $shipAddress2 = $this->value($orderInfo, ['Ship.ShipAddress2', 'ShipAddress2']);
        $shipCharge = $this->money($this->value($orderInfo, ['Detail.ShipCharge', 'ShipCharge']));
        $payCharge = $this->money($this->value($orderInfo, ['Detail.PayCharge', 'PayCharge']));
        $apiTotal = $this->money($this->value($orderInfo, ['Detail.TotalPrice', 'TotalPrice']));
        $mallCoupon = $this->money($this->value($orderInfo, ['Detail.TotalMallCouponDiscount', 'TotalMallCouponDiscount']));
        $immediateBonus = $this->money($this->value($orderInfo, ['Detail.TotalImmediateBonusAmount', 'TotalImmediateBonusAmount']));
        $total = $apiTotal + $mallCoupon + $immediateBonus;
        $quantityDetail = $this->quantityDetail($items);
        $mappedItems = [];
        $totalItemPrice = 0.0;

        foreach ($items as $index => $item) {
            $mappedItem = $this->mapItem($item, $orderInfo, $credentials, $index, $orderTime, $status, $quantityDetail, $operator);
            $totalItemPrice += (float) $mappedItem['line_total'];
            $mappedItems[] = $mappedItem;
        }

        $extra = [
            'OrderId' => $orderId,
            'myid' => $orderId,
            'OrderTime' => $orderTime,
            'OrderStatus' => $status,
            'EntryPoint' => $this->value($orderInfo, ['EntryPoint']),
            'ShipName' => trim($shipLastName . ' ' . $shipFirstName),
            'senderKana' => trim($shipLastKana . ' ' . $shipFirstKana),
            'ShipAddress1' => $shipAddress1,
            'ShipAddress2' => $shipAddress2,
            'ShipCity' => $shipCity,
            'ShipPrefecture' => $shipPrefecture,
            'ShipZipCode' => $this->value($orderInfo, ['Ship.ShipZipCode', 'ShipZipCode']),
            'ShipPhoneNumber' => $this->value($orderInfo, ['Ship.ShipPhoneNumber', 'ShipPhoneNumber']),
            'ShipRequestDate' => $this->value($orderInfo, ['Ship.ShipRequestDate', 'ShipRequestDate']),
            'ShipRequestTime' => $this->value($orderInfo, ['Ship.ShipRequestTime', 'ShipRequestTime']),
            'ShipNotes' => $this->value($orderInfo, ['Ship.ShipNotes', 'ShipNotes']),
            'BillMailAddress' => $this->value($orderInfo, ['Pay.BillMailAddress', 'BillMailAddress']),
            'PayMethodName' => $this->value($orderInfo, ['Pay.PayMethodName', 'PayMethodName']),
            'PayStatus' => $this->value($orderInfo, ['Pay.PayStatus', 'PayStatus']),
            'PayDate' => $this->value($orderInfo, ['Pay.PayDate', 'PayDate']),
            'QuantityDetail' => $quantityDetail,
            'ShipCharge' => $shipCharge,
            'PayCharge' => $payCharge,
            'TotalPrice' => $total,
            'SettleAmount' => $this->money($this->value($orderInfo, ['Detail.SettleAmount', 'SettleAmount'])),
            'SettlePayAmount' => $this->money($this->value($orderInfo, ['Detail.SettlePayAmount', 'SettlePayAmount'])),
            'UsePoint' => $this->money($this->value($orderInfo, ['Detail.UsePoint', 'UsePoint'])),
            'Discount' => $this->money($this->value($orderInfo, ['Detail.Discount', 'Discount'])),
            'GiftCardDiscount' => $this->money($this->value($orderInfo, ['Detail.GiftCardDiscount', 'GiftCardDiscount'])),
            'TotalMallCouponDiscount' => $mallCoupon,
            'TotalImmediateBonusAmount' => $immediateBonus,
            'cdate' => date('Y-m-d H:i'),
            'user_name' => $operator,
            'beizhu' => '未处理的订单',
            'raw' => $orderInfo,
        ];

        return [
            'platform' => 'y',
            'platform_order_id' => $orderId,
            'store_id' => (int) ($store['id'] ?? 0),
            'store' => (string) ($store['name'] ?? ''),
            'order_date' => $orderTime,
            'status' => $status,
            'customer' => [
                'name' => trim($shipLastName . ' ' . $shipFirstName),
                'kana' => trim($shipLastKana . ' ' . $shipFirstKana),
                'phone' => $this->value($orderInfo, ['Ship.ShipPhoneNumber', 'ShipPhoneNumber']),
                'zip' => $this->value($orderInfo, ['Ship.ShipZipCode', 'ShipZipCode']),
                'address' => trim($shipPrefecture . $shipCity . $shipAddress1 . $shipAddress2),
                'mail' => $this->value($orderInfo, ['Pay.BillMailAddress', 'BillMailAddress']),
            ],
            'pay_method' => $this->value($orderInfo, ['Pay.PayMethodName', 'PayMethodName']),
            'ship_method' => $this->value($orderInfo, ['Ship.ShipMethodName', 'ShipMethodName']),
            'total_item_price' => $totalItemPrice,
            'postage_price' => $shipCharge,
            'pay_charge' => $payCharge,
            'total' => $total,
            'platform_extra' => $extra,
            'items' => $mappedItems,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $orderInfo
     * @param array<string, string> $credentials
     * @return array<string, mixed>
     */
    private function mapItem(array $item, array $orderInfo, array $credentials, int $index, string $orderTime, string $status, string $quantityDetail, string $operator): array
    {
        $orderId = $this->value($orderInfo, ['OrderId']);
        $lineId = $this->value($item, ['LineId']);
        if ($lineId === '') {
            $lineId = (string) ($index + 1);
        }
        $itemId = $this->value($item, ['ItemId']);
        $quantity = max(0, (int) $this->money($this->value($item, ['Quantity'])));
        $unitPrice = $this->money($this->value($item, ['UnitPrice']));
        $option = $this->itemOptionText($item);
        $lineTotal = $unitPrice * max(1, $quantity);
        $entryPoint = $this->itemUrl($credentials['seller_id'], $itemId);

        return [
            'order_detail_id' => $orderId . '-' . $lineId,
            'line_id' => $lineId,
            'item_code' => $itemId,
            'item_management_id' => $this->value($item, ['SubCode']),
            'title' => $this->value($item, ['Title']),
            'option' => $option,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'postage_price' => $this->money($this->value($orderInfo, ['Detail.ShipCharge', 'ShipCharge'])),
            'pay_charge' => $this->money($this->value($orderInfo, ['Detail.PayCharge', 'PayCharge'])),
            'line_total' => $lineTotal,
            'purchase_status' => '未处理的订单',
            'image' => '',
            'main_image' => '',
            'sku_image' => '',
            'platform_extra' => [
                'OrderId' => $orderId,
                'myid' => $orderId,
                'LineId' => $lineId,
                'ItemId' => $itemId,
                'ItemManagerId' => $this->value($item, ['SubCode']),
                'Title' => $this->value($item, ['Title']),
                'Quantity' => $quantity,
                'SubCode' => $this->value($item, ['SubCode']),
                'SubCodeOption' => $option,
                'ItemOption' => $item['ItemOption'] ?? null,
                'OrderTime' => $orderTime,
                'OrderStatus' => $status,
                'QuantityDetail' => $quantityDetail,
                'UnitPrice' => $unitPrice,
                'TotalPrice' => $this->money($this->value($orderInfo, ['Detail.TotalPrice', 'TotalPrice'])),
                'cdate' => date('Y-m-d H:i'),
                'user_name' => $operator,
                'beizhu' => '未处理的订单',
                'EntryPoint' => $entryPoint,
                'ImageId' => $this->value($item, ['ImageId']),
                'raw' => $item,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function itemOptionText(array $item): string
    {
        $options = $this->listValue($item['ItemOption'] ?? []);
        $values = [];
        foreach ($options as $option) {
            $value = $this->value($option, ['Value']);
            if ($value === '') {
                $value = $this->scalar($option);
            }
            if ($value !== '') {
                $values[] = $value;
            }
        }

        if ($values) {
            return implode(';', $values);
        }

        return $this->value($item, ['SubCodeOption', 'SubCode']);
    }

    private function itemUrl(string $sellerId, string $itemId): string
    {
        if ($sellerId === '' || $itemId === '') {
            return '';
        }

        return 'https://store.shopping.yahoo.co.jp/' . rawurlencode($sellerId) . '/' . rawurlencode($itemId) . '.html';
    }

    /**
     * @param array<string, mixed> $orderInfo
     * @return array<int, array<string, mixed>>
     */
    private function orderItems(array $orderInfo): array
    {
        foreach (['Item', 'Items.Item', 'Detail.Item', 'OrderItems.Item'] as $path) {
            $items = $this->arrayAt($orderInfo, [$path]);
            if ($items) {
                return $this->listValue($items);
            }
        }

        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function quantityDetail(array $items): string
    {
        $parts = [];
        foreach ($items as $index => $item) {
            $lineId = $this->value($item, ['LineId']);
            if ($lineId === '') {
                $lineId = (string) ($index + 1);
            }
            $parts[] = 'L' . $lineId . '=' . (int) $this->money($this->value($item, ['Quantity']));
        }

        return implode('&', $parts);
    }

    private function orderStatusName(string $status): string
    {
        return match ($status) {
            '1' => '予約中',
            '2' => '処理中',
            '3' => '保留',
            '4' => 'キャンセル',
            '5' => '完了',
            default => $status,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function requestYahoo(string $url, string $token, string $body): array
    {
        $response = $this->requestText($url, [
            'method' => 'POST',
            'headers' => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/xml; charset=utf-8',
            ],
            'body' => $body,
        ]);

        return $this->decodeYahooResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeYahooResponse(string $response): array
    {
        $decoded = json_decode($response, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (!function_exists('simplexml_load_string')) {
            throw new \RuntimeException('Yahoo Shop API 返回 XML，但当前 PHP 环境缺少 SimpleXML 扩展。');
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new \RuntimeException('Yahoo Shop API 返回不是有效 JSON 或 XML。');
        }

        $json = json_encode($xml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $decoded */
    private function ensureYahooOk(array $decoded): void
    {
        $status = $this->value($decoded, ['Result.Status', 'ResultSet.Result.Status', 'ResultSet.Status', 'Status']);
        if ($status === 'OK' || $status === '') {
            $message = $this->value($decoded, ['Error.Message', 'Message', 'Result.Error.Message', 'ResultSet.Result.Message']);
            if ($status === '' && $message !== '') {
                $code = $this->value($decoded, ['Error.Code', 'Code', 'Result.Error.Code']);
                throw new \RuntimeException(trim($code . ' ' . $message));
            }
            return;
        }

        $message = $this->value($decoded, ['Result.Message', 'ResultSet.Result.Message', 'Message']);
        throw new \RuntimeException($message !== '' ? $status . ': ' . $message : 'Yahoo Shop API 状态异常：' . $status);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $paths
     * @return array<string, mixed>
     */
    private function arrayAt(array $data, array $paths): array
    {
        foreach ($paths as $path) {
            $current = $data;
            foreach (explode('.', $path) as $part) {
                if (!is_array($current) || !array_key_exists($part, $current)) {
                    $current = null;
                    break;
                }
                $current = $current[$part];
            }
            if (is_array($current)) {
                return $current;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $paths
     */
    private function value(array $data, array $paths): string
    {
        foreach ($paths as $path) {
            $current = $data;
            foreach (explode('.', $path) as $part) {
                if (!is_array($current) || !array_key_exists($part, $current)) {
                    $current = null;
                    break;
                }
                $current = $current[$part];
            }

            $value = $this->scalar($current);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listValue(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            return [];
        }

        if (array_is_list($value)) {
            return array_values(array_filter($value, 'is_array'));
        }

        return [$value];
    }

    private function scalar(mixed $value): string
    {
        if ($value === null || $value === false || $value === []) {
            return '';
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        if (is_array($value)) {
            if (array_key_exists(0, $value) && count($value) === 1) {
                return $this->scalar($value[0]);
            }
            if (array_key_exists('Value', $value)) {
                return $this->scalar($value['Value']);
            }
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return is_string($json) ? $json : '';
        }

        return '';
    }

    private function money(string $value): float
    {
        $value = str_replace([',', ' '], '', trim($value));

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function formatDate(string $value, string $format = 'Y-m-d H:i:s'): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $timezone = new \DateTimeZone('Asia/Tokyo');
        $compact = \DateTimeImmutable::createFromFormat('YmdHis', $value, $timezone);
        if ($compact instanceof \DateTimeImmutable) {
            return $compact->format($format);
        }

        try {
            return (new \DateTimeImmutable($value, $timezone))->setTimezone($timezone)->format($format);
        } catch (\Throwable) {
            $timestamp = strtotime($value);
            return $timestamp !== false ? date($format, $timestamp) : '';
        }
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
