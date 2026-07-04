<?php

declare(strict_types=1);

namespace Xizhen\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use RuntimeException;
use Throwable;

final class CsvImportService
{
    /** @var array<string, array<int, string>> */
    private const ALIASES = [
        'platform' => ['platform', 'mall', '平台', 'モール'],
        'store' => ['store', 'shop', 'dpqz', 'dpquancheng', '店铺', '店铺名', '店铺名称'],
        'order_id' => ['orderid', 'order_id', 'order no', 'orderno', 'oid', 'displayid', '订单号', '订单id', '平台订单号', '注文番号', '受注番号'],
        'order_detail_id' => ['orderdetailid', 'order_detail_id', 'order detail id', '明细id', '子订单id', '注文詳細id'],
        'line_id' => ['lineid', 'line_id', '明细行id', '行id'],
        'order_date' => ['orderdate', 'order_date', 'cdate', '订单日期', '订单时间', '导入时间', '注文日', '注文日時'],
        'order_status' => ['orderstatus', 'order_status', 'status', '订单状态', '注文ステータス'],
        'item_code' => ['itemid', 'item_id', 'itemcode', 'item_code', 'sku', 'lotnumber', 'lot_number', '商品id', '商品编码', 'sku编码', '管理番号'],
        'lot_number' => ['lotnumber', 'lot_number', '货号', '商品番号'],
        'item_management_id' => ['itemmanagerid', 'itemmanagementid', 'item_management_id', '商品管理id'],
        'product_title' => ['itemname', 'item_name', 'itemtitle', 'product_title', 'title', 'product_name', '商品名', '商品名称', '品名'],
        'item_option' => ['selectedchoice', 'subcodeoption', 'itemoption', 'item_option', 'option', '规格', '选项', '商品属性'],
        'chinese_option' => ['chinese_option', '中文属性', '中文规格'],
        'quantity' => ['quantity', 'qty', 'unit', '数量', '件数', '个数'],
        'unit_price' => ['unitprice', 'unit_price', 'itemprice', 'item_price', '单价'],
        'line_total' => ['totalitemprice', 'totalprice', 'line_total', 'item_total', '小计', '金额', '商品总价'],
        'total_item_price' => ['totalitemprice', 'total_item_price', '商品合计'],
        'total_price' => ['totalprice', 'total_price', 'requestprice', 'request_price', '合计', '请求金额', '订单总额'],
        'postage_price' => ['shipcharge', 'postageprice', 'postage_price', 'shipping_fee', '送料', '运费'],
        'pay_charge' => ['paycharge', 'pay_charge', 'payment_fee', '手续费'],
        'customer_name' => ['shipname', 'sendername', 'customer_name', 'name', '收件人', '收货人', '氏名'],
        'customer_kana' => ['senderkana', 'customer_kana', 'フリガナ', 'カナ'],
        'customer_zip' => ['shipzipcode', 'senderzipcode', 'shipping_postal_code', 'customer_zip', '邮编', '郵便番号'],
        'customer_address' => ['senderaddress', 'customer_address', 'shipping_address', '住所', '地址', '收件地址', '收货地址'],
        'customer_address1' => ['shipaddress1', 'address1', 'shipping_address_1', '地址1', '都道府県'],
        'customer_address2' => ['shipaddress2', 'address2', 'shipping_address_2', '地址2', '市区町村'],
        'customer_city' => ['shipcity', 'city', 'shipping_city', '城市'],
        'customer_phone' => ['shipphonenumber', 'senderphonenumber1', 'customer_phone', 'phone', '电话', '電話番号'],
        'customer_mail' => ['billmailaddress', 'mailaddress', 'customer_mail', 'email', '邮箱', 'mailaddress'],
        'pay_method' => ['paymethodname', 'settlementname', 'pay_method', '支付方法', '支付方式'],
        'ship_method' => ['deliveryname', 'ship_method', 'shipping_method', '配送方法', '発送方法'],
        'main_image' => ['zhutu', 'main_image', 'image', '产品图', '主图'],
        'sku_image' => ['skuimg', 'sku_image', 'sku图片', 'sku图'],
        'weight' => ['weight', '重量', '国际重量'],
        'material' => ['material', '材质', '素材'],
        'comment' => ['comment', 'item_comment', '备注', '注文备注', '客服备注', '注释'],
        'purchase_status' => ['purchase_status', 'caigoustatus', 'beizhu', '采购状态', '采购进度', '状态设置'],
        'buyer' => ['buyer', 'caigou_user', 'caigouuser', '采购人', '采购员'],
        'purchase_time' => ['purchase_time', 'caigou_time', 'caigoutime', '采购时间'],
        'purchase_link' => ['purchase_link', 'caigou_link', 'caigoulink', '采购链接', '采购地址'],
        'buhuo_link' => ['buhuo_link', 'buhuolink', '补货链接'],
        'amount' => ['amount', 'purchase_amount', '采购金额', '进货价'],
        'cn_amount' => ['cnamount', 'cn_amount', 'china_amount', '国内金额', '国内采购金额'],
        'com_amount' => ['comamount', 'com_amount', 'international_shipping_fee', '国际运费', '手续费金额'],
        'tabaono' => ['tabaono', '1688订单号', '淘宝订单号', '淘宝订单id', '采购单号'],
        'caigou_ordernums' => ['caigou_ordernums', 'caigouordernums', 'caigou_order_nums', 'purchase_order_nums', '历史1688单号', '历史采购单号'],
        'ship_company' => ['shipcompany', 'ship_company', 'carrier', '物流公司', '配送会社'],
        'ship_number' => ['shipnumber', 'ship_number', 'shipno', 'cn_ship_number', '国内运单号', '追踪番号'],
        'ship_quantity' => ['shipquantity', 'ship_quantity', 'ship_qty', 'shipping_quantity'],
        'logistics' => ['logistics', 'logisticstatus', 'logistic_status', 'jpship_status', 'logisticsstatus', '物流状态', '1688物流状态'],
        'logistic_trace' => ['logistic_trace', 'logisticstrace', 'logistics_trace', 'trace', 'logisticstraces', '物流轨迹', '1688物流轨迹'],
        'intl_number' => ['intl_number', 'international_no', 'international_number', 'ydno', 'yundan', '国际运单号', '国际物流单号'],
        'intl_status' => ['intl_status', 'international_status', '国际状态', '运单状态'],
        'intl_fee' => ['intl_fee', 'international_fee', '国际运费', '运费'],
        'intl_qty' => ['intl_qty', 'international_qty', '包装件数', '件数'],
        'intl_weight' => ['intl_weight', 'international_weight', '国际重量', '重量'],
        'tranship_comment' => ['tranship_comment', 'transhipcomment', '转运备注', '跨境备注'],
        'intl_comment' => ['intl_comment', '国际备注', '备注'],
        'reset_tracking' => ['reset', 'reset_tracking', '是否重置', '重置', '覆盖'],
    ];

    /**
     * @param array<string, mixed> $context
     * @return array{row_count: int, preview: array<int, array<string, string>>, records: array<int, array<string, mixed>>, errors: array<int, string>, store_mismatch_count: int}
     */
    public function parseFile(string $file, string $job, array $context = []): array
    {
        $context['_job'] = $job;
        try {
            [$rowCount, $preview, $rows] = $this->looksLikeXlsx($file)
                ? $this->readXlsx($file, $context)
                : $this->readCsv($file, $context);
        } catch (RuntimeException $exception) {
            return [
                'row_count' => 0,
                'preview' => [],
                'records' => [],
                'errors' => [$exception->getMessage()],
                'store_mismatch_count' => 0,
            ];
        }
        $errors = [];
        $records = [];
        $storeMismatchCount = 0;

        foreach ($rows as $row) {
            $record = match ($job) {
                'purchase_import' => $this->purchaseRecord($row, $context),
                'shipping_import' => $this->shippingRecord($row, $context),
                default => $this->platformOrderRecord($row, $context),
            };

            if (!$record['ok']) {
                if (($record['code'] ?? '') === 'store_mismatch') {
                    $storeMismatchCount++;
                }
                $errors[] = '第 ' . $row['_row'] . ' 行：' . $record['message'];
                continue;
            }

            $records[] = $record['record'];
        }

        return [
            'row_count' => $rowCount,
            'preview' => $preview,
            'records' => $records,
            'errors' => array_slice($errors, 0, 20),
            'store_mismatch_count' => $storeMismatchCount,
        ];
    }

    /**
     * @return array{0: int, 1: array<int, array<string, string>>, 2: array<int, array<string, mixed>>}
     */
    private function readCsv(string $file, array $context): array
    {
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return [0, [], []];
        }

        $tableRows = [];
        while (($raw = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            $tableRows[] = array_map(
                fn (mixed $value): string => $this->cleanCell((string) $value),
                $this->normalizeDelimitedRow($raw)
            );
        }
        fclose($handle);

        return $this->readTabularRows($tableRows, $context);
    }

    /**
     * @return array{0: int, 1: array<int, array<string, string>>, 2: array<int, array<string, mixed>>}
     */
    private function readXlsx(string $file, array $context): array
    {
        if (!class_exists(IOFactory::class)) {
            throw new RuntimeException('缺少 PhpSpreadsheet 依赖，无法解析 XLSX 文件。');
        }

        try {
            $spreadsheet = IOFactory::load($file);
        } catch (Throwable $exception) {
            throw new RuntimeException('XLSX 文件解析失败：' . $exception->getMessage(), 0, $exception);
        }

        try {
            $sheet = $spreadsheet->getSheet(0);
            $highestRow = $sheet->getHighestDataRow();
            $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            $tableRows = [];

            for ($row = 1; $row <= $highestRow; $row++) {
                $values = [];
                for ($column = 1; $column <= $highestColumn; $column++) {
                    $cell = $sheet->getCell($this->cell($column, $row));
                    $value = $cell->getValue();
                    if (SpreadsheetDate::isDateTime($cell) && is_numeric($value)) {
                        $value = SpreadsheetDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
                    } else {
                        $value = $cell->getFormattedValue();
                    }

                    $value = $this->cleanCell((string) $value);
                    if ($value === '') {
                        $hyperlink = $cell->getHyperlink()->getUrl();
                        if ($hyperlink !== '') {
                            $value = $hyperlink;
                        }
                    }
                    $values[] = $value;
                }
                $tableRows[] = $values;
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return $this->readTabularRows($tableRows, $context);
    }

    /**
     * @param array<int, array<int, string>> $tableRows
     * @return array{0: int, 1: array<int, array<string, string>>, 2: array<int, array<string, mixed>>}
     */
    private function readTabularRows(array $tableRows, array $context): array
    {
        $headers = [];
        $rows = [];
        $preview = [];
        $rowCount = 0;
        $line = 0;

        foreach ($tableRows as $values) {
            $line++;
            if (implode('', $values) === '') {
                continue;
            }

            if ($headers === []) {
                $headers = $values;
                if (isset($headers[0])) {
                    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]) ?? $headers[0];
                }
                if ($this->looksLikeHeader($headers)) {
                    continue;
                }
                $fixedHeaders = $this->fixedHeadersFor($values, $context);
                if ($fixedHeaders !== []) {
                    $headers = $fixedHeaders;
                    if (!$this->fixedFirstRowLooksLikeData($values, $context)) {
                        continue;
                    }
                }
            }

            $rowCount++;
            $assoc = [];
            foreach ($headers as $index => $header) {
                $assoc[$header !== '' ? $header : ('col_' . ($index + 1))] = $values[$index] ?? '';
            }
            $assoc['_row'] = $line;
            $assoc['_raw'] = $values;
            $assoc['_headers'] = $headers;
            $rows[] = $assoc;

            if (count($preview) < 5) {
                $copy = $assoc;
                unset($copy['_row'], $copy['_raw'], $copy['_headers']);
                $preview[] = $copy;
            }
        }

        return [$rowCount, $preview, $rows];
    }

    /** @param array<int, string> $headers */
    private function looksLikeHeader(array $headers): bool
    {
        foreach ($headers as $header) {
            $normalized = $this->normalizeKey($header);
            foreach (self::ALIASES as $aliases) {
                foreach ($aliases as $alias) {
                    if ($normalized === $this->normalizeKey($alias)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @param array<int, string> $row @param array<string, mixed> $context @return array<int, string> */
    private function fixedHeadersFor(array $row, array $context): array
    {
        $platform = $this->platformCode((string) ($context['platform'] ?? ''), $context);
        $job = (string) ($context['_job'] ?? '');
        $count = count($row);

        if ($job === 'purchase_import') {
            if (in_array($platform, ['m', 'q', 'w', 'yp'], true) && $count >= 14) {
                return ['legacyId', 'image', 'orderId', 'orderDetailId', 'itemCode', 'lotNumber', 'itemOption', 'quantity', 'lineTotal', 'purchaseStatus', 'purchaseTime', 'amount', 'tabaono', 'purchaseLink'];
            }
            if ($count >= 12) {
                return ['legacyId', 'image', 'orderId', 'itemCode', 'itemOption', 'quantity', 'unitPrice', 'purchaseStatus', 'purchaseTime', 'amount', 'tabaono', 'purchaseLink'];
            }
        }

        if ($job === 'shipping_import') {
            if ($count >= 7) {
                return ['orderId', 'intlNumber', 'shipQuantity', 'weight', 'comAmount', 'purchaseStatus', 'resetTracking'];
            }
            if ($count >= 6) {
                return ['orderId', 'intlNumber', 'shipQuantity', 'weight', 'comAmount', 'purchaseStatus'];
            }
            if ($count >= 3) {
                return ['orderId', 'intlNumber', 'resetTracking'];
            }
            if ($count >= 2) {
                return ['orderId', 'intlNumber'];
            }
        }

        if ($platform === 'y') {
            return $count > 7
                ? ['orderId', 'myid', 'orderDate', 'orderStatus', 'entryPoint', 'customerName', 'address1', 'address2', 'customerCity', 'prefecture', 'customerZip', 'customerPhone', 'deliveryRequest1', 'deliveryRequest2', 'comment', 'customerMail', 'payMethod', 'payStatus', 'payDate', 'totalItemPrice', 'postagePrice', 'payCharge', 'unused22', 'totalPrice']
                : ['orderId', 'lineId', 'itemCode', 'myid', 'quantity', 'itemOption', 'unitPrice'];
        }
        if ($platform === 'r' && $count >= 150) {
            $headers = array_fill(0, $count, 'unused');
            foreach ([0 => 'orderId', 1 => 'orderStatus', 4 => 'orderDate', 12 => 'payMethod', 15 => 'customerAddress2', 27 => 'postagePrice', 28 => 'payCharge', 30 => 'totalPrice', 47 => 'customerMail', 57 => 'comment', 58 => 'customerZip1', 59 => 'customerZip2', 60 => 'customerCity', 61 => 'customerAddress1', 63 => 'customerName1', 64 => 'customerName2', 65 => 'customerKana1', 66 => 'customerKana2', 67 => 'customerPhone1', 68 => 'customerPhone2', 69 => 'customerPhone3', 73 => 'itemManagementId', 74 => 'itemCode', 75 => 'unitPrice', 76 => 'quantity', 80 => 'payDate', 157 => 'itemOption'] as $index => $name) {
                $headers[$index] = $name;
            }
            return $headers;
        }
        if ($platform === 'w' && $count >= 160) {
            $headers = array_fill(0, $count, 'unused');
            foreach ([1 => 'orderId', 2 => 'orderDetailId', 3 => 'orderDate', 4 => 'itemCode', 5 => 'lotNumber', 6 => 'customerName', 7 => 'customerKana', 8 => 'customerAddress', 9 => 'customerZip', 10 => 'customerPhone', 11 => 'payMethod', 12 => 'lineTotal', 13 => 'postagePrice', 16 => 'customerMail', 22 => 'orderStatus', 23 => 'quantity', 30 => 'itemManagementId', 32 => 'itemOption', 109 => 'totalPrice', 160 => 'shipMethod'] as $index => $name) {
                $headers[$index] = $name;
            }
            return $headers;
        }
        if ($platform === 'm' && $count >= 35) {
            $headers = array_fill(0, $count, 'unused');
            foreach ([0 => 'orderId', 1 => 'orderDate', 4 => 'itemCode', 5 => 'productTitle', 6 => 'quantity', 8 => 'lineTotal', 10 => 'postagePrice', 12 => 'shipMethod', 19 => 'customerZip', 20 => 'prefecture', 21 => 'customerCity', 22 => 'customerAddress1', 23 => 'customerAddress2', 24 => 'customerName', 32 => 'customerPhone'] as $index => $name) {
                $headers[$index] = $name;
            }
            return $headers;
        }
        if ($platform === 'm' && $count >= 20) {
            $headers = array_fill(0, $count, 'unused');
            foreach ([0 => 'orderId', 2 => 'orderDate', 3 => 'lineTotal', 4 => 'postagePrice', 6 => 'customerName', 7 => 'customerZip', 8 => 'prefecture', 9 => 'customerCity', 10 => 'customerAddress1', 11 => 'customerAddress2', 12 => 'customerPhone', 15 => 'productTitle', 16 => 'quantity', 17 => 'lotNumber', 18 => 'itemOption', 19 => 'itemCode'] as $index => $name) {
                $headers[$index] = $name;
            }
            return $headers;
        }
        if ($platform === 'q' && $count >= 44) {
            $headers = array_fill(0, $count, 'unused');
            foreach ([0 => 'orderStatus', 1 => 'orderDetailId', 2 => 'orderId', 3 => 'shipMethod', 6 => 'orderDate', 12 => 'lotNumber', 14 => 'quantity', 15 => 'itemOption', 18 => 'customerName', 19 => 'customerKana', 20 => 'customerPhone', 22 => 'customerAddress', 23 => 'customerZip', 28 => 'totalPrice', 38 => 'itemCode'] as $index => $name) {
                $headers[$index] = $name;
            }
            return $headers;
        }
        if ($platform === 'yp' && $count >= 12) {
            return ['orderId', 'orderDetailId', 'productTitle', 'orderDate', 'quantity', 'lineTotal', 'postagePrice', 'customerName', 'customerZip', 'customerAddress', 'unused10', 'shipMethod'];
        }

        return [];
    }

    /** @param array<int, string> $row @param array<string, mixed> $context */
    private function fixedFirstRowLooksLikeData(array $row, array $context): bool
    {
        $platform = $this->platformCode((string) ($context['platform'] ?? ''), $context);
        $job = (string) ($context['_job'] ?? '');

        if ($job === 'purchase_import') {
            return isset($row[2]) && !$this->isHeaderToken($row[2]);
        }
        if ($job === 'shipping_import') {
            return isset($row[1]) && !$this->isHeaderToken($row[0] ?? '') && !$this->isHeaderToken($row[1]);
        }

        $index = match ($platform) {
            'w' => 1,
            'q' => 2,
            default => 0,
        };

        return isset($row[$index]) && !$this->isHeaderToken($row[$index]);
    }

    private function isHeaderToken(string $value): bool
    {
        $value = $this->normalizeKey($value);
        if ($value === '') {
            return true;
        }

        foreach ([
            'id', 'orderid', 'order_id', 'orderno', 'displayid', '订单号', '订单id',
            '注文番号', '受注番号', '国际运单号', 'intlnumber', 'internationalnumber',
        ] as $token) {
            if ($value === $this->normalizeKey($token)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $context */
    private function platformOrderRecord(array $row, array $context): array
    {
        $platform = $this->platformCode($this->value($row, 'platform'), $context)
            ?: $this->platformCode((string) ($context['platform'] ?? ''), $context)
            ?: $this->platformFromHeaders($row);
        $orderId = $this->value($row, 'order_id');
        $itemCode = $this->value($row, 'item_code');
        $title = $this->value($row, 'product_title');

        if ($platform === '') {
            return ['ok' => false, 'message' => '无法识别平台，请在导入表单选择平台或在 CSV 中提供平台列。'];
        }
        if ($orderId === '') {
            return ['ok' => false, 'message' => '缺少订单号 orderId/注文番号/平台订单号。'];
        }

        $store = $this->storeInfo($row, $context, $platform);
        if (!empty($store['restricted_mismatch'])) {
            return ['ok' => false, 'code' => 'store_mismatch', 'message' => '店铺列与所选店铺不符，已跳过。'];
        }
        $customerName = $this->combinedValue($row, ['customer_name', 'customer_name1', 'customer_name2']);
        $customerKana = $this->combinedValue($row, ['customer_kana', 'customer_kana1', 'customer_kana2']);
        $customerZip = $this->combinedValue($row, ['customer_zip', 'customer_zip1', 'customer_zip2'], '-');
        $customerPhone = $this->combinedValue($row, ['customer_phone', 'customer_phone1', 'customer_phone2', 'customer_phone3'], '-');
        $address = trim(implode(' ', array_filter([
            $this->value($row, 'customer_address'),
            $this->value($row, 'prefecture'),
            $this->value($row, 'customer_city'),
            $this->value($row, 'customer_address1'),
            $this->value($row, 'customer_address2'),
        ])));
        $quantity = max(1, $this->intValue($this->value($row, 'quantity'), 1));
        $unitPrice = $this->moneyValue($this->value($row, 'unit_price'));
        $lineTotal = $this->moneyValue($this->value($row, 'line_total'));
        if ($lineTotal <= 0 && $unitPrice > 0) {
            $lineTotal = $unitPrice * $quantity;
        }
        $totalPrice = $this->moneyValue($this->value($row, 'total_price'));
        if ($totalPrice <= 0) {
            $totalPrice = $lineTotal;
        }
        $amountRaw = $this->value($row, 'amount');
        $cnAmountRaw = $this->value($row, 'cn_amount');
        $amount = $this->moneyValue($amountRaw !== '' ? $amountRaw : $cnAmountRaw);
        $cnAmount = $this->moneyValue($cnAmountRaw !== '' ? $cnAmountRaw : $amountRaw);

        return [
            'ok' => true,
            'record' => [
                'row' => (int) $row['_row'],
                'order' => [
                    'platform' => $platform,
                    'platform_order_id' => $orderId,
                    'order_detail_id' => $this->value($row, 'order_detail_id'),
                    'store_id' => $store['id'],
                    'store' => $store['name'],
                    'order_date' => $this->dateValue($this->value($row, 'order_date')),
                    'status' => $this->value($row, 'order_status') ?: '待处理',
                    'customer' => [
                        'name' => $customerName,
                        'kana' => $customerKana,
                        'zip' => $customerZip,
                        'address' => $address,
                        'phone' => $customerPhone,
                        'mail' => $this->value($row, 'customer_mail'),
                    ],
                    'pay_method' => $this->value($row, 'pay_method'),
                    'ship_method' => $this->value($row, 'ship_method'),
                    'total_item_price' => $this->moneyValue($this->value($row, 'total_item_price')) ?: $lineTotal,
                    'postage_price' => $this->moneyValue($this->value($row, 'postage_price')),
                    'pay_charge' => $this->moneyValue($this->value($row, 'pay_charge')),
                    'total' => $totalPrice,
                    'extra' => $this->sourceRow($row),
                ],
                'item' => $itemCode === '' && $title === '' ? [] : [
                    'order_detail_id' => $this->value($row, 'order_detail_id'),
                    'line_id' => $this->value($row, 'line_id'),
                    'item_code' => $itemCode,
                    'lot_number' => $this->value($row, 'lot_number'),
                    'item_management_id' => $this->value($row, 'item_management_id'),
                    'title' => $title ?: '未识别商品',
                    'option' => $this->value($row, 'item_option'),
                    'chinese_option' => $this->value($row, 'chinese_option'),
                    'quantity' => $quantity,
                    'source_type' => 'pending',
                    'purchase_status' => $this->value($row, 'purchase_status') ?: '待处理',
                    'buyer' => $this->value($row, 'buyer'),
                    'purchase_time' => $this->dateValue($this->value($row, 'purchase_time')),
                    'purchase_link' => $this->value($row, 'purchase_link'),
                    'buhuo_link' => $this->value($row, 'buhuo_link'),
                    'amount' => $amount,
                    'cn_amount' => $cnAmount,
                    'com_amount' => $this->moneyValue($this->value($row, 'com_amount')),
                    'tabaono' => $this->value($row, 'tabaono'),
                    'caigou_ordernums' => $this->value($row, 'caigou_ordernums'),
                    'unit_price' => $unitPrice,
                    'postage_price' => $this->moneyValue($this->value($row, 'postage_price')),
                    'pay_charge' => $this->moneyValue($this->value($row, 'pay_charge')),
                    'line_total' => $lineTotal,
                    'weight' => $this->moneyValue($this->value($row, 'weight')),
                    'material' => $this->value($row, 'material'),
                    'comment' => $this->value($row, 'comment'),
                    'ship_company' => $this->value($row, 'ship_company'),
                    'ship_number' => $this->value($row, 'ship_number'),
                    'ship_quantity' => $this->intValue($this->value($row, 'ship_quantity'), 0),
                    'logistics' => $this->value($row, 'logistics'),
                    'logistic_trace' => $this->value($row, 'logistic_trace'),
                    'tranship_comment' => $this->value($row, 'tranship_comment'),
                    'image' => $this->value($row, 'main_image'),
                    'main_image' => $this->value($row, 'main_image'),
                    'sku_image' => $this->value($row, 'sku_image'),
                    'extra' => $this->sourceRow($row),
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $context */
    private function purchaseRecord(array $row, array $context): array
    {
        $identity = $this->identity($row, $context);
        if (($identity['platform_order_id'] ?? '') === '') {
            return ['ok' => false, 'message' => '缺少订单号，无法定位采购明细。'];
        }

        $amountRaw = $this->value($row, 'amount');
        $cnAmountRaw = $this->value($row, 'cn_amount');
        $changes = array_filter([
            'source_type' => 'cn_purchase',
            'purchase_status' => $this->value($row, 'purchase_status'),
            'buyer' => $this->value($row, 'buyer'),
            'purchase_time' => $this->dateValue($this->value($row, 'purchase_time')),
            'purchase_link' => $this->value($row, 'purchase_link'),
            'buhuo_link' => $this->value($row, 'buhuo_link'),
            'amount' => $this->moneyValue($amountRaw !== '' ? $amountRaw : $cnAmountRaw),
            'cn_amount' => $this->moneyValue($cnAmountRaw !== '' ? $cnAmountRaw : $amountRaw),
            'com_amount' => $this->moneyValue($this->value($row, 'com_amount')),
            'tabaono' => $this->value($row, 'tabaono'),
            'caigou_ordernums' => $this->value($row, 'caigou_ordernums'),
            'ship_company' => $this->value($row, 'ship_company'),
            'ship_number' => $this->value($row, 'ship_number'),
            'ship_quantity' => $this->intValue($this->value($row, 'ship_quantity'), 0),
            'logistics' => $this->value($row, 'logistics'),
            'logistic_trace' => $this->value($row, 'logistic_trace'),
            'material' => $this->value($row, 'material'),
            'weight' => $this->moneyValue($this->value($row, 'weight')),
            'chinese_option' => $this->value($row, 'chinese_option'),
            'comment' => $this->value($row, 'comment'),
            'tranship_comment' => $this->value($row, 'tranship_comment'),
        ], static fn (mixed $value): bool => $value !== '' && $value !== 0 && $value !== 0.0 && $value !== null);

        return [
            'ok' => true,
            'record' => [
                'row' => (int) $row['_row'],
                'identity' => $identity,
                'changes' => $changes,
            ],
        ];
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $context */
    private function shippingRecord(array $row, array $context): array
    {
        $identity = $this->identity($row, $context);
        $intlNumber = $this->value($row, 'intl_number') ?: $this->value($row, 'ship_number');
        if (($identity['platform_order_id'] ?? '') === '' && $intlNumber === '') {
            return ['ok' => false, 'message' => '缺少订单号或国际运单号，无法定位国际运单明细。'];
        }

        $shipQuantity = $this->intValue($this->value($row, 'ship_quantity'), 0);
        $intlQty = $this->intValue($this->value($row, 'intl_qty'), 0) ?: $shipQuantity;
        $weight = $this->moneyValue($this->value($row, 'weight'));
        $intlWeight = $this->moneyValue($this->value($row, 'intl_weight')) ?: $weight;
        $comAmount = $this->moneyValue($this->value($row, 'com_amount'));
        $intlFee = $this->moneyValue($this->value($row, 'intl_fee')) ?: $comAmount;

        $changes = array_filter([
            'intl_number' => $intlNumber,
            'intl_status' => $this->value($row, 'intl_status'),
            'purchase_status' => $this->value($row, 'purchase_status'),
            'intl_fee' => $intlFee,
            'intl_qty' => $intlQty,
            'intl_weight' => $intlWeight,
            'ship_quantity' => $shipQuantity,
            'weight' => $weight,
            'com_amount' => $comAmount,
            'tranship_comment' => $this->value($row, 'tranship_comment'),
            'intl_comment' => $this->value($row, 'intl_comment'),
            'reset_tracking' => $this->value($row, 'reset_tracking') === '1',
        ], static fn (mixed $value): bool => $value !== '' && $value !== 0 && $value !== 0.0 && $value !== null && $value !== false);

        return [
            'ok' => true,
            'record' => [
                'row' => (int) $row['_row'],
                'identity' => $identity,
                'changes' => $changes,
            ],
        ];
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $context */
    private function identity(array $row, array $context): array
    {
        return [
            'platform' => $this->platformCode($this->value($row, 'platform'), $context) ?: $this->platformCode((string) ($context['platform'] ?? ''), $context),
            'platform_order_id' => $this->value($row, 'order_id'),
            'order_detail_id' => $this->value($row, 'order_detail_id'),
            'line_id' => $this->value($row, 'line_id'),
            'item_code' => $this->value($row, 'item_code'),
            'store_id' => (int) ($context['store_id'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $row */
    private function value(array $row, string $field): string
    {
        foreach ($row as $key => $value) {
            if ($this->normalizeKey((string) $key) === $this->normalizeKey($field)) {
                return $this->cleanCell((string) $value);
            }
        }

        $wanted = array_flip(array_map(fn (string $name): string => $this->normalizeKey($name), self::ALIASES[$field] ?? [$field]));
        foreach ($row as $key => $value) {
            if (str_starts_with((string) $key, '_')) {
                continue;
            }
            if (isset($wanted[$this->normalizeKey((string) $key)])) {
                return $this->cleanCell((string) $value);
            }
        }

        return '';
    }

    /** @param array<string, mixed> $row @param array<int, string> $fields */
    private function combinedValue(array $row, array $fields, string $separator = ' '): string
    {
        $parts = [];
        foreach ($fields as $field) {
            $value = $this->value($row, $field);
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return implode($separator, $parts);
    }

    private function normalizeKey(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
        $value = preg_replace('/[\s_\-\x{3000}\(\)（）\[\]【】:：\/\\\\]+/u', '', $value) ?? $value;
        return $value;
    }

    private function cleanCell(string $value): string
    {
        if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
            $encoding = mb_detect_encoding($value, ['UTF-8', 'SJIS-win', 'CP932', 'EUC-JP', 'ISO-2022-JP'], true);
            if ($encoding && $encoding !== 'UTF-8') {
                $value = mb_convert_encoding($value, 'UTF-8', $encoding);
            }
        }

        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        return trim($value);
    }

    /** @param array<int, mixed> $raw @return array<int, mixed> */
    private function normalizeDelimitedRow(array $raw): array
    {
        if (count($raw) !== 1 || !is_string($raw[0])) {
            return $raw;
        }

        $line = $raw[0];
        if (str_contains($line, "\t")) {
            return str_getcsv($line, "\t");
        }
        if (str_contains($line, ';') && !str_contains($line, ',')) {
            return str_getcsv($line, ';');
        }

        return $raw;
    }

    private function looksLikeXlsx(string $file): bool
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return false;
        }
        $signature = fread($handle, 4);
        fclose($handle);

        return $signature === "PK\x03\x04";
    }

    private function cell(int $column, int $row): string
    {
        return Coordinate::stringFromColumnIndex($column) . $row;
    }

    /** @param array<string, mixed> $context */
    private function platformCode(string $value, array $context): string
    {
        $value = $this->normalizeKey($value);
        if ($value === '') {
            return '';
        }
        if (in_array($value, ['y', 'r', 'w', 'm', 'q', 'yp'], true)) {
            return $value;
        }

        $known = [
            'yahoo' => 'y',
            'ヤフー' => 'y',
            '雅虎' => 'y',
            'rakuten' => 'r',
            '楽天' => 'r',
            '乐天' => 'r',
            'wowma' => 'w',
            'aupay' => 'w',
            'mercari' => 'm',
            'メルカリ' => 'm',
            'qoo10' => 'q',
            'auction' => 'yp',
            'yauction' => 'yp',
            'ヤフオク' => 'yp',
            '雅虎拍卖' => 'yp',
        ];
        if (isset($known[$value])) {
            return $known[$value];
        }

        foreach ((array) ($context['platform_names'] ?? []) as $code => $name) {
            if ($this->normalizeKey((string) $name) === $value) {
                return (string) $code;
            }
        }

        return '';
    }

    /** @param array<string, mixed> $row */
    private function platformFromHeaders(array $row): string
    {
        $headers = implode(' ', array_map('strval', (array) ($row['_headers'] ?? [])));
        $headers = $this->normalizeKey($headers);
        return match (true) {
            str_contains($headers, 'orderdetailid') && str_contains($headers, 'selectedchoice') => 'r',
            str_contains($headers, 'lotnumber') => 'm',
            str_contains($headers, 'auction') || str_contains($headers, 'ヤフオク') => 'yp',
            default => '',
        };
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $context @return array{id: int, name: string, restricted_mismatch?: bool} */
    private function storeInfo(array $row, array $context, string $platform): array
    {
        $selectedId = (int) ($context['store_id'] ?? 0);
        $storeValue = $this->value($row, 'store');
        if (!empty($context['restrict_to_store_id']) && $selectedId > 0) {
            foreach ((array) ($context['stores'] ?? []) as $store) {
                $storeId = (int) ($store['id'] ?? 0);
                if ($storeId !== $selectedId) {
                    continue;
                }

                $name = (string) (($store['name'] ?? '') ?: ($store['short'] ?? ''));
                if ($storeValue === '' || in_array($this->normalizeKey($storeValue), [
                    $this->normalizeKey((string) ($store['name'] ?? '')),
                    $this->normalizeKey((string) ($store['short'] ?? '')),
                ], true)) {
                    return ['id' => $storeId, 'name' => $name];
                }

                return ['id' => 0, 'name' => '', 'restricted_mismatch' => true];
            }
        }

        foreach ((array) ($context['stores'] ?? []) as $store) {
            $storeId = (int) ($store['id'] ?? 0);
            $name = (string) (($store['name'] ?? '') ?: ($store['short'] ?? ''));
            if ($selectedId > 0 && $storeId === $selectedId) {
                return ['id' => $storeId, 'name' => $name];
            }
            if ($storeValue !== '' && in_array($this->normalizeKey($storeValue), [
                $this->normalizeKey((string) ($store['name'] ?? '')),
                $this->normalizeKey((string) ($store['short'] ?? '')),
            ], true)) {
                return ['id' => $storeId, 'name' => $name];
            }
        }

        return ['id' => 0, 'name' => $storeValue ?: strtoupper($platform) . ' 导入店铺'];
    }

    private function moneyValue(string $value): float
    {
        $value = str_replace([',', '¥', '￥', '円', ' '], '', trim($value));
        if ($value === '' || !is_numeric($value)) {
            return 0.0;
        }

        return round((float) $value, 2);
    }

    private function intValue(string $value, int $default): int
    {
        $value = str_replace([',', ' '], '', trim($value));
        if ($value === '' || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    private function dateValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $time = strtotime(str_replace('/', '-', $value));
        if ($time === false) {
            return $value;
        }

        return date('Y-m-d H:i:s', $time);
    }

    /** @param array<string, mixed> $row @return array<string, string> */
    private function sourceRow(array $row): array
    {
        $source = [];
        foreach ($row as $key => $value) {
            if (str_starts_with((string) $key, '_')) {
                continue;
            }
            $source[(string) $key] = (string) $value;
        }

        return $source;
    }
}
