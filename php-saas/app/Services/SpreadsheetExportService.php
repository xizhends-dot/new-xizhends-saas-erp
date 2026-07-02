<?php

declare(strict_types=1);

namespace Xizhen\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use RuntimeException;

final class SpreadsheetExportService
{
    private const MAX_IMAGE_BYTES = 5242880;

    /** @var array<int, string> */
    private array $temporaryImageFiles = [];

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array{name: string, filename: string, path: string, rows: int, format: string}
     */
    public function financeWorkbook(string $tenantKey, array $orders, string $operator = '', string $variant = ''): array
    {
        $this->assertRuntime();
        $template = $this->financeTemplate($orders, $variant);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator($operator !== '' ? $operator : 'Xizhen SaaS')
            ->setLastModifiedBy($operator !== '' ? $operator : 'Xizhen SaaS')
            ->setTitle($template['name'])
            ->setSubject($template['name'])
            ->setCategory('Finance');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('财务核算');
        $spreadsheet->getDefaultStyle()->getFont()->setName('等线')->setSize(11);
        $sheet->getDefaultRowDimension()->setRowHeight(22);
        $sheet->freezePane('A2');

        $headers = $template['headers'];
        $widths = $template['widths'];

        foreach ($headers as $index => $header) {
            $column = $index + 1;
            $sheet->setCellValue($this->cell($column, 1), $header);
            $sheet->getColumnDimensionByColumn($column)->setWidth($widths[$index]);
        }

        $lastColumn = count($headers);
        $sheet->getStyle($this->range(1, 1, $lastColumn, 1))->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EBEBEB']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'A9A9A9']]],
        ]);

        $row = 2;
        foreach ($orders as $order) {
            $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
            $attachments = is_array($order['_attachments'] ?? null) ? $order['_attachments'] : [];
            foreach (array_values(array_filter($order['items'] ?? [], 'is_array')) as $item) {
                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                $rowValues = $this->financeRowValues($template['key'], $order, $item, $customer, $quantity);
                foreach ($template['columns'] as $index => $key) {
                    $column = $index + 1;
                    $cell = $this->cell($column, $row);
                    $value = $rowValues[$key] ?? '';
                    if (in_array($key, ['image', 'purchase_evidence'], true)) {
                        continue;
                    }
                    if (in_array($key, $template['text_columns'], true)) {
                        $this->setText($sheet, $cell, (string) $value);
                        continue;
                    }
                    $sheet->setCellValue($cell, $value);
                }

                $sheet->getRowDimension($row)->setRowHeight(78);
                foreach ($template['columns'] as $index => $key) {
                    $cell = $this->cell($index + 1, $row);
                    if ($key === 'image') {
                        $this->embedImage($sheet, $cell, $this->itemImagePath($item), 92, 92);
                    }
                    if ($key === 'purchase_evidence') {
                        $this->embedImage($sheet, $cell, $this->purchaseEvidencePath($item, $attachments), 92, 92);
                    }
                }
                $row++;
            }
        }

        $lastRow = max(1, $row - 1);
        $sheet->getStyle($this->range(1, 1, $lastColumn, $lastRow))->applyFromArray([
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => false,
            ],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D9D9D9']]],
        ]);
        if ($lastRow >= 2) {
            foreach ($template['numeric_columns'] as $key) {
                $index = array_search($key, $template['columns'], true);
                if ($index === false) {
                    continue;
                }
                $range = $this->range((int) $index + 1, 2, (int) $index + 1, $lastRow);
                $sheet->getStyle($range)->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            $quantityIndex = array_search('quantity', $template['columns'], true);
            if ($quantityIndex !== false) {
                $sheet->getStyle($this->range((int) $quantityIndex + 1, 2, (int) $quantityIndex + 1, $lastRow))
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
        }

        return $this->writeWorkbook($spreadsheet, $template['name'], $template['filename_prefix'] . "-{$tenantKey}-" . date('Ymd-His') . '.xlsx', $lastRow - 1);
    }

    /**
     * @param array{name: string, filename: string, headers: array<int, string>, rows: array<int, array<int, mixed>>} $dataset
     * @return array{name: string, filename: string, path: string, rows: int, format: string}
     */
    public function customerWorkbook(string $tenantKey, array $dataset, string $operator = ''): array
    {
        $this->assertRuntime();

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator($operator !== '' ? $operator : 'Xizhen SaaS')
            ->setLastModifiedBy($operator !== '' ? $operator : 'Xizhen SaaS')
            ->setTitle('客户资料表')
            ->setSubject('客户资料表')
            ->setCategory('Customer');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('客户资料');
        $spreadsheet->getDefaultStyle()->getFont()->setName('微软雅黑')->setSize(12);
        $sheet->getDefaultColumnDimension()->setWidth(20);
        $sheet->getDefaultRowDimension()->setRowHeight(20);
        $sheet->freezePane('A2');
        $sheet->getProtection()->setSheet(true);

        $headers = $dataset['headers'];
        $widths = [15, 15, 40, 15, 18, 24, 14];
        foreach ($headers as $index => $header) {
            $column = $index + 1;
            $sheet->setCellValue($this->cell($column, 1), $header);
            $sheet->getColumnDimensionByColumn($column)->setWidth($widths[$index] ?? 20);
        }

        $row = 2;
        foreach ($dataset['rows'] as $values) {
            foreach ($headers as $index => $_header) {
                $column = $index + 1;
                $cell = $this->cell($column, $row);
                $value = $values[$index] ?? '';
                if (in_array($index, [3, 4], true)) {
                    $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
                    $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    continue;
                }

                $sheet->setCellValue($cell, $value);
            }
            $row++;
        }

        $lastRow = max(1, $row - 1);
        $lastColumn = count($headers);
        $sheet->getStyle($this->range(1, 1, $lastColumn, 1))->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EBEBEB']],
        ]);
        $sheet->getStyle($this->range(1, 1, $lastColumn, $lastRow))->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'A9A9A9']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->setSelectedCell('H2');

        return $this->writeWorkbook($spreadsheet, '客户资料 Excel', "customers-legacy-{$tenantKey}-" . date('Ymd-His') . '.xlsx', count($dataset['rows']));
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array{key: string, name: string, filename_prefix: string, headers: array<int, string>, columns: array<int, string>, widths: array<int, int>, numeric_columns: array<int, string>, text_columns: array<int, string>}
     */
    private function financeTemplate(array $orders, string $variant): array
    {
        $key = strtolower(trim($variant));
        $key = str_replace('_', '-', $key);
        if ($key === '') {
            $platforms = array_values(array_unique(array_filter(array_map(
                static fn (array $order): string => strtolower((string) ($order['platform'] ?? '')),
                $orders
            ))));
            $key = count($platforms) === 1 ? $platforms[0] : 'default';
        }
        $key = match ($key) {
            'y', 'ordery', 'yahoo', 'yahoo-shop', 'yahoo-shopping', 'yahooshop' => 'ordery',
            'w', 'orderw', 'wowma' => 'orderw',
            'orderr-weier', 'orderrweier', 'weier' => 'orderr-weier',
            default => 'default',
        };

        $templates = [
            'default' => [
                'name' => '财务图片核算表',
                'filename_prefix' => 'finance-images',
                'headers' => ['订单号', '产品编码', '图片', '数量', '客户姓名', '地址', '国内单号', '国内运费', '国际单号', '国际运费', '采购价格', '产品单价', '产品运费', '采购证据图片', '采购状态', '淘宝订单号', '产品总价'],
                'columns' => ['order_no', 'item_code', 'image', 'quantity', 'customer_name', 'address', 'domestic_tracking', 'domestic_fee', 'intl_number', 'intl_fee', 'purchase_price', 'unit_price', 'product_postage', 'purchase_evidence', 'purchase_status', 'taobao_order_no', 'product_total'],
                'widths' => [18, 18, 16, 8, 15, 42, 22, 12, 22, 12, 12, 12, 12, 16, 14, 20, 12],
                'numeric_columns' => ['domestic_fee', 'intl_fee', 'purchase_price', 'unit_price', 'product_postage', 'product_total'],
                'text_columns' => ['order_no', 'item_code', 'customer_name', 'address', 'domestic_tracking', 'intl_number', 'purchase_status', 'taobao_order_no'],
            ],
            'ordery' => [
                'name' => 'Yahoo购物财务核算表',
                'filename_prefix' => 'finance-yahoo-shop',
                'headers' => ['订单号', '产品编码', '图片', '数量', '客户姓名', '地址', '国际单号', '国际运费', '采购价格', '产品单价', '产品总价（单价*数量）', '产品运费', '利润', '采购状态'],
                'columns' => ['order_no', 'item_code', 'image', 'quantity', 'customer_name', 'address', 'intl_number', 'intl_fee', 'purchase_price', 'unit_price', 'product_total', 'product_postage', 'profit', 'purchase_status'],
                'widths' => [18, 18, 16, 8, 15, 42, 22, 12, 12, 12, 18, 12, 12, 14],
                'numeric_columns' => ['intl_fee', 'purchase_price', 'unit_price', 'product_total', 'product_postage', 'profit'],
                'text_columns' => ['order_no', 'item_code', 'customer_name', 'address', 'intl_number', 'purchase_status'],
            ],
            'orderw' => [
                'name' => 'Wowma财务核算表',
                'filename_prefix' => 'finance-wowma',
                'headers' => ['订单号', '产品编码', '图片', '数量', '客户姓名', '地址', '国内单号', '国内运费', '国际单号', '国际运费', '采购价格', '单价', '产品单价', '产品运费', '采购证据图片', '采购状态', '淘宝订单号', '产品总价'],
                'columns' => ['order_no', 'item_code', 'image', 'quantity', 'customer_name', 'address', 'domestic_tracking', 'domestic_fee', 'intl_number', 'intl_fee', 'purchase_price', 'item_price', 'unit_price', 'product_postage', 'purchase_evidence', 'purchase_status', 'taobao_order_no', 'product_total'],
                'widths' => [18, 18, 16, 8, 15, 42, 22, 12, 22, 12, 12, 12, 12, 12, 16, 14, 20, 12],
                'numeric_columns' => ['domestic_fee', 'intl_fee', 'purchase_price', 'item_price', 'unit_price', 'product_postage', 'product_total'],
                'text_columns' => ['order_no', 'item_code', 'customer_name', 'address', 'domestic_tracking', 'intl_number', 'purchase_status', 'taobao_order_no'],
            ],
            'orderr-weier' => [
                'name' => 'WEIER财务核算表',
                'filename_prefix' => 'finance-weier',
                'headers' => ['订单号', '产品编码', '店铺名称', '数量', '客户姓名', '地址', '国内单号', '国内运费', '国际单号', '国际运费', '采购价格', '产品单价', '产品运费', '采购证据图片', '采购状态', '淘宝订单号', '产品总价'],
                'columns' => ['order_no', 'item_code', 'store_name', 'quantity', 'customer_name', 'address', 'domestic_tracking', 'domestic_fee', 'intl_number', 'intl_fee', 'purchase_price', 'unit_price', 'product_postage', 'purchase_evidence', 'purchase_status', 'taobao_order_no', 'product_total'],
                'widths' => [18, 18, 22, 8, 15, 42, 22, 12, 22, 12, 12, 12, 12, 16, 14, 20, 12],
                'numeric_columns' => ['domestic_fee', 'intl_fee', 'purchase_price', 'unit_price', 'product_postage', 'product_total'],
                'text_columns' => ['order_no', 'item_code', 'store_name', 'customer_name', 'address', 'domestic_tracking', 'intl_number', 'purchase_status', 'taobao_order_no'],
            ],
        ];

        return ['key' => $key] + $templates[$key];
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     * @param array<string, mixed> $customer
     * @return array<string, mixed>
     */
    private function financeRowValues(string $template, array $order, array $item, array $customer, int $quantity): array
    {
        $extra = is_array($item['platform_extra'] ?? null) ? $item['platform_extra'] : [];
        $unitPrice = $this->unitPrice($item, $quantity);
        $itemPrice = $this->money(($extra['itemPrice'] ?? $extra['UnitPrice'] ?? '') ?: ($item['unit_price'] ?? ''));
        $productUnitPriceSource = $template === 'orderw'
            ? (($extra['totalItemPrice'] ?? $extra['TotalPrice'] ?? '') ?: ($item['line_total'] ?? ''))
            : (($item['line_total'] ?? '') ?: ($extra['totalItemPrice'] ?? $extra['TotalPrice'] ?? ''));
        $productUnitPrice = $this->money($productUnitPriceSource);
        if ($productUnitPrice <= 0) {
            $productUnitPrice = $unitPrice;
        }
        $financeUnitPrice = $template === 'orderw' ? $productUnitPrice : $unitPrice;
        if ($template === 'orderw') {
            $productTotal = $productUnitPrice * $quantity;
        } else {
            $productTotal = $unitPrice * $quantity;
        }
        $domesticTracking = trim(implode('', array_filter([
            (string) ($item['ship_company'] ?? ''),
            (string) ($item['ship_number'] ?? ''),
        ], static fn (string $value): bool => $value !== '')));

        return [
            'order_no' => (string) ($order['platform_order_id'] ?? ''),
            'item_code' => (string) (($item['item_code'] ?? '') ?: ($item['lot_number'] ?? '')),
            'quantity' => $quantity,
            'store_name' => (string) ($order['store'] ?? ''),
            'customer_name' => (string) ($customer['name'] ?? ''),
            'address' => (string) ($customer['address'] ?? ''),
            'domestic_tracking' => $domesticTracking,
            'domestic_fee' => $this->money($item['cn_amount'] ?? ''),
            'intl_number' => (string) (($item['intl_number'] ?? '') ?: ($item['ship_number'] ?? '')),
            'intl_fee' => $this->money(($item['intl_fee'] ?? '') ?: ($item['com_amount'] ?? '')),
            'purchase_price' => $this->money($item['amount'] ?? ''),
            'item_price' => $itemPrice,
            'unit_price' => $financeUnitPrice,
            'product_postage' => $this->money($item['postage_price'] ?? ''),
            'purchase_status' => (string) ($item['purchase_status'] ?? ''),
            'taobao_order_no' => (string) ($item['tabaono'] ?? ''),
            'product_total' => $productTotal,
            'profit' => $this->money($item['cn_amount'] ?? ''),
        ];
    }

    private function assertRuntime(): void
    {
        if (!class_exists(Spreadsheet::class)) {
            throw new RuntimeException('缺少 PhpSpreadsheet 依赖，请在 php-saas 目录执行 composer install。');
        }
        foreach (['zip', 'xml', 'xmlwriter', 'mbstring', 'gd'] as $extension) {
            if (!extension_loaded($extension)) {
                throw new RuntimeException("Excel 导出缺少 PHP {$extension} 扩展。");
            }
        }
    }

    private function writeWorkbook(Spreadsheet $spreadsheet, string $name, string $filename, int $rows): array
    {
        $directory = $this->basePath . '/storage/tmp/exports';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = $directory . '/' . uniqid('xlsx-', true) . '.xlsx';
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        try {
            $writer->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
            $this->cleanupTemporaryImages();
        }

        return [
            'name' => $name,
            'filename' => $filename,
            'path' => $path,
            'rows' => $rows,
            'format' => 'xlsx',
        ];
    }

    private function setText(object $sheet, string $cell, string $value): void
    {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }

    private function cell(int $column, int $row): string
    {
        return Coordinate::stringFromColumnIndex($column) . $row;
    }

    private function range(int $startColumn, int $startRow, int $endColumn, int $endRow): string
    {
        return $this->cell($startColumn, $startRow) . ':' . $this->cell($endColumn, $endRow);
    }

    private function itemImagePath(array $item): string
    {
        foreach (['sku_image', 'main_image', 'image', 'zhutu'] as $field) {
            $path = trim((string) ($item[$field] ?? ''));
            if ($path !== '' && $path !== '/assets/no-image.svg') {
                return $path;
            }
        }

        return '';
    }

    /** @param array<int, array<string, mixed>> $attachments */
    private function purchaseEvidencePath(array $item, array $attachments): string
    {
        $itemId = (int) ($item['id'] ?? 0);
        foreach ($attachments as $attachment) {
            if ($itemId > 0 && (int) ($attachment['order_item_id'] ?? 0) !== $itemId) {
                continue;
            }
            if ($this->attachmentLooksLikePurchaseEvidence($attachment)) {
                return (string) ($attachment['path'] ?? '');
            }
        }

        foreach ($attachments as $attachment) {
            if ($this->attachmentLooksLikeImage($attachment)) {
                return (string) ($attachment['path'] ?? '');
            }
        }

        return '';
    }

    /** @param array<string, mixed> $attachment */
    private function attachmentLooksLikePurchaseEvidence(array $attachment): bool
    {
        $text = implode(' ', [
            (string) ($attachment['type'] ?? ''),
            (string) ($attachment['title'] ?? ''),
            (string) ($attachment['source'] ?? ''),
        ]);

        return str_contains($text, '采购') || str_contains($text, '凭证') || str_contains($text, 'ph_img');
    }

    /** @param array<string, mixed> $attachment */
    private function attachmentLooksLikeImage(array $attachment): bool
    {
        $path = strtolower((string) ($attachment['path'] ?? ''));
        return (bool) preg_match('/\.(jpe?g|png|gif|webp)$/i', $path);
    }

    private function embedImage(object $sheet, string $coordinate, string $source, int $maxWidth, int $maxHeight): void
    {
        $path = $this->imageFile($source);
        if ($path === null) {
            return;
        }

        [$width, $height] = $this->scaledSize($path, $maxWidth, $maxHeight);
        $drawing = new Drawing();
        $drawing->setPath($path);
        $drawing->setCoordinates($coordinate);
        $drawing->setOffsetX(4);
        $drawing->setOffsetY(4);
        $drawing->setResizeProportional(true);
        $drawing->setWidth($width);
        if ($height > 0) {
            $drawing->setHeight($height);
        }
        $drawing->setWorksheet($sheet);
    }

    /** @return array{0: int, 1: int} */
    private function scaledSize(string $path, int $maxWidth, int $maxHeight): array
    {
        $size = @getimagesize($path);
        if (!is_array($size)) {
            return [$maxWidth, $maxHeight];
        }

        $width = max(1, (int) ($size[0] ?? $maxWidth));
        $height = max(1, (int) ($size[1] ?? $maxHeight));
        $ratio = min($maxWidth / $width, $maxHeight / $height, 1);

        return [max(1, (int) floor($width * $ratio)), max(1, (int) floor($height * $ratio))];
    }

    private function imageFile(string $source): ?string
    {
        $source = trim($source);
        if ($source === '') {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $source) === 1) {
            return $this->remoteImageFile($source);
        }

        if (str_starts_with($source, '/assets/')) {
            $source = ltrim($source, '/');
        }

        $source = str_replace('\\', '/', $source);
        if (str_contains($source, '..') || preg_match('/^[a-zA-Z]:\//', $source) === 1 || str_starts_with($source, '/')) {
            return null;
        }

        $path = $this->basePath . '/' . ltrim($source, '/');
        if (!is_file($path) || filesize($path) > self::MAX_IMAGE_BYTES) {
            return null;
        }

        return $this->validImagePath($path) ? $path : null;
    }

    private function remoteImageFile(string $url): ?string
    {
        if (!$this->remoteImageUrlAllowed($url)) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'follow_location' => 0,
                'user_agent' => 'XizhenSaaS/1.0',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $bytes = @file_get_contents($url, false, $context, 0, self::MAX_IMAGE_BYTES + 1);
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            return null;
        }
        if (@getimagesizefromstring($bytes) === false) {
            return null;
        }

        $directory = $this->basePath . '/storage/tmp/exports/images';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $extension = $this->extensionFromBytes($bytes);
        if ($extension === '') {
            return null;
        }

        $path = $directory . '/' . sha1($url . "\n" . $bytes) . '.' . $extension;
        file_put_contents($path, $bytes);
        $this->temporaryImageFiles[] = $path;

        return $path;
    }

    private function remoteImageUrlAllowed(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    private function validImagePath(string $path): bool
    {
        return @getimagesize($path) !== false;
    }

    private function extensionFromBytes(string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';

        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => '',
        };
    }

    private function money(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $normalized = str_replace([',', '¥', '￥', '円', ' '], '', (string) $value);
        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    /** @param array<string, mixed> $item */
    private function unitPrice(array $item, int $quantity): float
    {
        $unitPrice = $this->money($item['unit_price'] ?? '');
        if ($unitPrice > 0) {
            return $unitPrice;
        }

        $lineTotal = $this->money($item['line_total'] ?? '');
        if ($lineTotal > 0 && $quantity > 0) {
            return $lineTotal / $quantity;
        }

        return 0.0;
    }

    private function cleanupTemporaryImages(): void
    {
        foreach ($this->temporaryImageFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->temporaryImageFiles = [];
    }
}
