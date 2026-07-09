<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class RakutenOrderService implements PlatformOrderSyncInterface
{
    private const SEARCH_URL = 'https://api.rms.rakuten.co.jp/es/2.0/order/searchOrder/';
    private const GET_URL = 'https://api.rms.rakuten.co.jp/es/2.0/order/getOrder/';
    private const PAGE_SIZE = 1000;
    private const DETAIL_BATCH_SIZE = 5;

    public function __construct(private readonly StoreInterface $store)
    {
    }

    public function platformCode(): string
    {
        return 'r';
    }

    public function platformName(): string
    {
        return '乐天 RMS';
    }

    /**
     * @return array{ok: bool, message: string, searched: int, inserted: int, updated: int, skipped: int, items_inserted: int, items_updated: int}
     */
    public function sync(string $tenantKey, int $storeId, int $days, string $operator): array
    {
        $store = $this->store->store($tenantKey, $storeId);
        if (!$store || ($store['platform'] ?? '') !== 'r') {
            return $this->result(false, '请选择乐天店铺后再同步。');
        }

        $credentials = $this->credentials($store);
        if (($credentials['Secret'] ?? '') === '' || ($credentials['Key'] ?? '') === '') {
            $message = '缺少乐天 RMS 的 Secret/Key，请先在店铺 API 配置里保存。';
            $this->store->markStoreSync($tenantKey, $storeId, '同步异常', $message);
            return $this->result(false, $message);
        }

        $days = max(1, min(30, $days));
        $start = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')))
            ->modify("-{$days} days")
            ->setTime(0, 0, 0);
        $end = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo'));

        try {
            $orderNumbers = $this->searchOrderNumbers($credentials, $start, $end);
            if (!$orderNumbers) {
                $message = '乐天 RMS 没有需要同步的新订单。';
                $this->store->markStoreSync($tenantKey, $storeId, '同步完成', $message);
                return $this->result(true, $message);
            }

            $summary = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'items_inserted' => 0, 'items_updated' => 0];
            foreach (array_chunk($orderNumbers, self::DETAIL_BATCH_SIZE) as $batch) {
                $models = $this->getOrders($credentials, $batch);
                $orders = array_map(
                    fn (array $model): array => $this->mapOrderModel($model, $store, $operator),
                    $models
                );
                $step = $this->store->upsertPlatformOrders($tenantKey, $orders, $operator);
                foreach ($summary as $key => $_) {
                    $summary[$key] += (int) ($step[$key] ?? 0);
                }
            }

            $message = sprintf(
                '乐天 RMS 同步完成：检索 %d 单，新增 %d 单，更新 %d 单，新增商品 %d 件，更新商品 %d 件，跳过 %d 单。',
                count($orderNumbers),
                $summary['inserted'],
                $summary['updated'],
                $summary['items_inserted'],
                $summary['items_updated'],
                $summary['skipped']
            );
            $this->store->markStoreSync($tenantKey, $storeId, '同步完成', $message);

            return [
                'ok' => true,
                'message' => $message,
                'searched' => count($orderNumbers),
                'inserted' => $summary['inserted'],
                'updated' => $summary['updated'],
                'skipped' => $summary['skipped'],
                'items_inserted' => $summary['items_inserted'],
                'items_updated' => $summary['items_updated'],
            ];
        } catch (\Throwable $error) {
            $message = '乐天 RMS 同步失败：' . $error->getMessage();
            $this->store->markStoreSync($tenantKey, $storeId, '同步异常', $message);
            return $this->result(false, $message);
        }
    }

    /** @param array<string, mixed> $store @return array{Secret: string, Key: string} */
    private function credentials(array $store): array
    {
        $config = [];
        $raw = (string) ($store['api_config'] ?? '');
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $config = $decoded;
        } else {
            foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
                if (!str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $config[trim($key)] = trim($value);
            }
        }

        foreach (['serviceSecret' => 'Secret', 'service_secret' => 'Secret', 'licenseKey' => 'Key', 'license_key' => 'Key'] as $from => $to) {
            if (($config[$to] ?? '') === '' && ($config[$from] ?? '') !== '') {
                $config[$to] = $config[$from];
            }
        }

        return [
            'Secret' => trim((string) ($config['Secret'] ?? '')),
            'Key' => trim((string) ($config['Key'] ?? '')),
        ];
    }

    /**
     * @param array{Secret: string, Key: string} $credentials
     * @return array<int, string>
     */
    private function searchOrderNumbers(array $credentials, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $body = [
            'dateType' => 3,
            'orderProgressList' => [300],
            'startDatetime' => $start->format('Y-m-d\TH:i:sO'),
            'endDatetime' => $end->format('Y-m-d\TH:i:sO'),
            'PaginationRequestModel' => [
                'requestRecordsAmount' => self::PAGE_SIZE,
                'requestPage' => 1,
                'SortModelList' => [
                    ['sortColumn' => 1, 'sortDirection' => 1],
                ],
            ],
        ];

        $data = $this->request($credentials, self::SEARCH_URL, $body);
        $numbers = is_array($data['orderNumberList'] ?? null) ? $data['orderNumberList'] : [];

        return array_values(array_unique(array_filter(array_map('strval', $numbers))));
    }

    /**
     * @param array{Secret: string, Key: string} $credentials
     * @param array<int, string> $orderNumbers
     * @return array<int, array<string, mixed>>
     */
    private function getOrders(array $credentials, array $orderNumbers): array
    {
        $data = $this->request($credentials, self::GET_URL, [
            'orderNumberList' => array_values($orderNumbers),
            'version' => 8,
        ]);

        return is_array($data['OrderModelList'] ?? null) ? array_values(array_filter($data['OrderModelList'], 'is_array')) : [];
    }

    /**
     * @param array{Secret: string, Key: string} $credentials
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function request(array $credentials, string $url, array $body): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('当前 PHP 环境缺少 curl 扩展。');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: ESA ' . base64_encode($credentials['Secret'] . ':' . $credentials['Key']),
                'Content-Type: application/json; charset=utf-8',
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException($error !== '' ? $error : "RMS API HTTP {$status}");
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('RMS API 返回不是有效 JSON。');
        }
        if (isset($data['Results']['errorCode'])) {
            throw new \RuntimeException((string) $data['Results']['errorCode'] . ': ' . (string) ($data['Results']['message'] ?? ''));
        }

        return $data;
    }

    /** @param array<string, mixed> $model @param array<string, mixed> $store @return array<string, mixed> */
    private function mapOrderModel(array $model, array $store, string $operator): array
    {
        $package = is_array($model['PackageModelList'][0] ?? null) ? $model['PackageModelList'][0] : [];
        $sender = is_array($package['SenderModel'] ?? null) ? $package['SenderModel'] : [];
        $orderer = is_array($model['OrdererModel'] ?? null) ? $model['OrdererModel'] : [];
        $settlement = is_array($model['SettlementModel'] ?? null) ? $model['SettlementModel'] : [];
        $delivery = is_array($model['DeliveryModel'] ?? null) ? $model['DeliveryModel'] : [];
        $items = is_array($package['ItemModelList'] ?? null) ? array_values(array_filter($package['ItemModelList'], 'is_array')) : [];
        $quantityDetail = [];

        foreach ($items as $index => $item) {
            $lineId = (string) ($index + 1);
            $quantity = (int) ($item['units'] ?? 0);
            $quantityDetail[] = "L{$lineId}={$quantity}";
        }

        $orderTime = $this->formatDate($model['orderDatetime'] ?? null);
        $importedAt = date('Y-m-d H:i');
        $quantityDetailText = implode('&', $quantityDetail);
        $mappedItems = [];
        foreach ($items as $index => $item) {
            $lineId = (string) ($index + 1);
            $mappedItems[] = $this->mapItemModel($item, $model, $package, $store, $lineId, $operator, $orderTime, $importedAt, $quantityDetailText);
        }

        $requestPrice = (int) ($model['TaxSummaryModelList'][0]['reqPrice'] ?? $model['requestPrice'] ?? 0);
        $firstItem = is_array($items[0] ?? null) ? $items[0] : [];
        $firstItemId = (string) ($firstItem['manageNumber'] ?? $firstItem['itemNumber'] ?? $firstItem['itemId'] ?? '');
        $extra = [
            'OrderId' => (string) ($model['orderNumber'] ?? ''),
            'myid' => (string) ($model['orderNumber'] ?? ''),
            'OrderTime' => $orderTime,
            'OrderStatus' => (string) ($model['orderProgress'] ?? ''),
            'EntryPoint' => RakutenUrlHelper::rakutenItemUrl($store, $firstItemId),
            'ShipName' => $this->personName($sender),
            'senderKana' => $this->personKana($sender),
            'ShipAddress1' => (string) ($sender['subAddress'] ?? ''),
            'ShipAddress2' => (string) ($delivery['deliveryName'] ?? ''),
            'ShipCity' => (string) ($sender['city'] ?? ''),
            'ShipPrefecture' => (string) ($sender['prefecture'] ?? ''),
            'ShipZipCode' => $this->zip($sender),
            'ShipPhoneNumber' => $this->phone($sender),
            'ShipRequestDate' => $this->formatDate($model['shippingInstDatetime'] ?? null, 'Y-m-d'),
            'ShipRequestTime' => $this->formatDate($model['shippingInstDatetime'] ?? null, 'H:i:s'),
            'ShipNotes' => (string) ($package['noshi'] ?? ''),
            'BillMailAddress' => (string) ($orderer['emailAddress'] ?? ''),
            'PayMethodName' => (string) ($settlement['settlementMethod'] ?? ''),
            'PayStatus' => '',
            'PayDate' => '',
            'QuantityDetail' => $quantityDetailText,
            'ShipCharge' => (int) ($model['postagePrice'] ?? 0),
            'PayCharge' => (int) ($model['deliveryPrice'] ?? 0),
            'TotalPrice' => (int) ($model['totalPrice'] ?? 0),
            'requestPrice' => $requestPrice,
            'cdate' => $importedAt,
            'user_name' => $operator,
            'beizhu' => '未处理的订单',
            'basketId' => (int) ($package['basketId'] ?? 0),
            'raw' => $model,
        ];

        return [
            'platform' => 'r',
            'platform_order_id' => (string) ($model['orderNumber'] ?? ''),
            'store_id' => (int) ($store['id'] ?? 0),
            'store' => (string) ($store['name'] ?? ''),
            'order_date' => $orderTime,
            'status' => (string) ($model['orderProgress'] ?? ''),
            'customer' => [
                'name' => $this->personName($sender),
                'kana' => $this->personKana($sender),
                'phone' => $this->phone($sender),
                'zip' => $this->zip($sender),
                'address' => $this->address($sender),
                'mail' => (string) ($orderer['emailAddress'] ?? ''),
            ],
            'pay_method' => (string) ($settlement['settlementMethod'] ?? ''),
            'ship_method' => (string) ($delivery['deliveryName'] ?? ''),
            'total_item_price' => (float) ($model['goodsPrice'] ?? 0),
            'postage_price' => (float) ($model['postagePrice'] ?? 0),
            'pay_charge' => (float) ($model['deliveryPrice'] ?? 0),
            'total' => (float) ($model['totalPrice'] ?? 0),
            'platform_extra' => $extra,
            'items' => $mappedItems,
        ];
    }

    /** @param array<string, mixed> $item @param array<string, mixed> $model @param array<string, mixed> $package @param array<string, mixed> $store @return array<string, mixed> */
    private function mapItemModel(array $item, array $model, array $package, array $store, string $lineId, string $operator, string $orderTime, string $importedAt, string $quantityDetail): array
    {
        $skuTexts = [];
        foreach (is_array($item['SkuModelList'] ?? null) ? $item['SkuModelList'] : [] as $sku) {
            if (is_array($sku) && trim((string) ($sku['skuInfo'] ?? '')) !== '') {
                $skuTexts[] = preg_replace('/\s+/', ' ', trim((string) $sku['skuInfo']));
            }
        }
        $subCodeOption = implode('; ', array_filter($skuTexts));
        $selectedChoice = str_replace('\n', PHP_EOL, (string) ($item['selectedChoice'] ?? ''));
        $unitPrice = (float) ($item['price'] ?? 0);
        $quantity = (int) ($item['units'] ?? 0);
        $itemId = (string) ($item['manageNumber'] ?? '');
        $itemCode = (string) ($item['manageNumber'] ?? $item['itemNumber'] ?? $item['itemId'] ?? '');
        $entryPoint = RakutenUrlHelper::rakutenItemUrl($store, $itemId !== '' ? $itemId : $itemCode);
        $mainImage = RakutenUrlHelper::rakutenMainImageUrl($store, $itemId !== '' ? $itemId : $itemCode);

        return [
            'order_detail_id' => (string) ($item['itemDetailId'] ?? ''),
            'line_id' => $lineId,
            'item_code' => $itemCode,
            'item_management_id' => (string) ($item['itemNumber'] ?? ''),
            'title' => (string) ($item['itemName'] ?? ''),
            'option' => $subCodeOption !== '' ? $subCodeOption : $selectedChoice,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'postage_price' => (float) ($model['postagePrice'] ?? 0),
            'pay_charge' => (float) ($model['deliveryPrice'] ?? 0),
            'line_total' => $unitPrice * max(1, $quantity),
            'purchase_status' => '未处理的订单',
            'image' => $mainImage,
            'main_image' => '',
            'sku_image' => '',
            'platform_extra' => [
                'OrderId' => (string) ($model['orderNumber'] ?? ''),
                'myid' => (string) ($model['orderNumber'] ?? ''),
                'LineId' => $lineId,
                'ItemId' => $itemId,
                'ItemManagerId' => (string) ($item['itemNumber'] ?? ''),
                'Quantity' => $quantity,
                'SubCodeOption' => $subCodeOption,
                'selectedChoice' => $selectedChoice,
                'delvdateInfo' => (string) ($item['delvdateInfo'] ?? ''),
                'OrderTime' => $orderTime,
                'OrderStatus' => (string) ($item['orderProgress'] ?? $model['orderProgress'] ?? ''),
                'QuantityDetail' => $quantityDetail,
                'UnitPrice' => $unitPrice,
                'TotalPrice' => (float) ($model['totalPrice'] ?? 0),
                'requestPrice' => (int) ($model['TaxSummaryModelList'][0]['reqPrice'] ?? $model['requestPrice'] ?? 0),
                'cdate' => $importedAt,
                'user_name' => $operator,
                'beizhu' => '未处理的订单',
                'EntryPoint' => $entryPoint,
                'zhutu' => $mainImage,
                'skuimg' => '',
                'basketId' => (int) ($package['basketId'] ?? 0),
                'raw' => $item,
            ],
        ];
    }

    /** @param array<string, mixed> $person */
    private function personName(array $person): string
    {
        return trim((string) ($person['familyName'] ?? '') . ' ' . (string) ($person['firstName'] ?? ''));
    }

    /** @param array<string, mixed> $person */
    private function personKana(array $person): string
    {
        return trim((string) ($person['familyNameKana'] ?? '') . ' ' . (string) ($person['firstNameKana'] ?? ''));
    }

    /** @param array<string, mixed> $person */
    private function zip(array $person): string
    {
        return trim((string) ($person['zipCode1'] ?? '') . '-' . (string) ($person['zipCode2'] ?? ''), '-');
    }

    /** @param array<string, mixed> $person */
    private function phone(array $person): string
    {
        return trim((string) ($person['phoneNumber1'] ?? '') . '-' . (string) ($person['phoneNumber2'] ?? '') . '-' . (string) ($person['phoneNumber3'] ?? ''), '-');
    }

    /** @param array<string, mixed> $person */
    private function address(array $person): string
    {
        return trim((string) ($person['prefecture'] ?? '') . (string) ($person['city'] ?? '') . (string) ($person['subAddress'] ?? ''));
    }

    private function formatDate(mixed $value, string $format = 'Y-m-d H:i:s'): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($raw))
                ->setTimezone(new \DateTimeZone('Asia/Tokyo'))
                ->format($format);
        } catch (\Throwable) {
            return strtotime($raw) !== false ? date($format, strtotime($raw)) : '';
        }
    }

    /**
     * @return array{ok: bool, message: string, searched: int, inserted: int, updated: int, skipped: int, items_inserted: int, items_updated: int}
     */
    private function result(bool $ok, string $message): array
    {
        return [
            'ok' => $ok,
            'message' => $message,
            'searched' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'items_inserted' => 0,
            'items_updated' => 0,
        ];
    }
}
