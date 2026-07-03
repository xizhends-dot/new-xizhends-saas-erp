<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';
require $basePath . '/app/Services/ExportFieldRegistry.php';
require $basePath . '/app/Services/SpreadsheetExportService.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use Xizhen\Services\SpreadsheetExportService;

$missingExtensions = array_values(array_filter(
    ['zip', 'xml', 'xmlwriter', 'mbstring', 'gd'],
    static fn (string $extension): bool => !extension_loaded($extension)
));
if ($missingExtensions !== []) {
    echo 'Shipping XLSX workflow test skipped: missing PHP extension(s): ' . implode(', ', $missingExtensions) . ".\n";
    exit(0);
}

$dataset = [
    'name' => '测试发货单',
    'filename' => 'shipping-20260703-000000.xlsx',
    'format' => 'xlsx',
    'headers' => ['订单号', '图片', '数量'],
    'rows' => [
        ['Y-2001', 'img/no_such_image.jpg', 2],
        ['Y-2002', '', 1],
    ],
    'imageColumns' => [1],
];

$service = new SpreadsheetExportService($basePath);
$file = $service->shippingWorkbook('erp', $dataset, '测试员');

$failures = [];
if (!is_file((string) ($file['path'] ?? ''))) {
    $failures[] = 'shippingWorkbook 未生成文件: ' . var_export($file, true);
} else {
    $sheet = IOFactory::load($file['path'])->getActiveSheet();
    if ((string) $sheet->getCell('A1')->getValue() !== '订单号') {
        $failures[] = 'A1 表头不对: ' . $sheet->getCell('A1')->getValue();
    }
    if ((string) $sheet->getCell('A2')->getValue() !== 'Y-2001') {
        $failures[] = 'A2 数据不对: ' . $sheet->getCell('A2')->getValue();
    }
    if ((string) $sheet->getCell('B2')->getValue() !== 'img/no_such_image.jpg') {
        $failures[] = 'B2 应保留图片路径文本(嵌图失败降级): ' . $sheet->getCell('B2')->getValue();
    }
    if ((int) ($file['rows'] ?? 0) !== 2) {
        $failures[] = 'rows 计数应为 2';
    }
    @unlink($file['path']);
}

if ($failures !== []) {
    echo "Shipping XLSX workflow test FAILED:\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}
echo "Shipping XLSX workflow test OK\n";
