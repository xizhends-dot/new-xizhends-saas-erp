<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);

require $basePath . '/vendor/autoload.php';
require $basePath . '/app/Services/CsvImportService.php';
require $basePath . '/app/Services/SpreadsheetExportService.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use Xizhen\Services\CsvImportService;
use Xizhen\Services\SpreadsheetExportService;

$missingExtensions = array_values(array_filter(
    ['zip', 'xml', 'xmlwriter', 'mbstring', 'gd'],
    static fn (string $extension): bool => !extension_loaded($extension)
));
if ($missingExtensions !== []) {
    echo 'Purchase XLSX workflow test skipped: missing PHP extension(s): ' . implode(', ', $missingExtensions) . ".\n";
    exit(0);
}

$orders = [[
    'id' => 1,
    'platform' => 'w',
    'platform_order_id' => 'W-202607020001',
    'items' => [[
        'id' => 101,
        'source_type' => 'cn_purchase',
        'order_detail_id' => 'D-001',
        'item_code' => 'ITEM-001',
        'lot_number' => 'LOT-001',
        'option' => 'Color: red',
        'quantity' => 2,
        'line_total' => 120.5,
        'purchase_status' => '国内采购-准备',
        'purchase_time' => '2026-07-02',
        'amount' => 66.6,
        'tabaono' => '168812345678',
        'purchase_link' => 'https://detail.1688.com/offer/123456.html',
        'main_image' => '',
    ]],
]];

$exporter = new SpreadsheetExportService($basePath);
$file = $exporter->purchaseWorkbook('test', $orders, 'purchase_xlsx_test', 'w');

assert_true(is_file($file['path']), 'xlsx file exists');
assert_same(1, $file['rows'], 'export row count');
assert_same('xlsx', $file['format'], 'export format');

$spreadsheet = IOFactory::load($file['path']);
$sheet = $spreadsheet->getActiveSheet();
assert_true($sheet->getProtection()->getSheet(), 'sheet protection is enabled');
assert_same(Protection::PROTECTION_PROTECTED, $sheet->getStyle('A2')->getProtection()->getLocked(), 'identity column is locked');
assert_same(Protection::PROTECTION_UNPROTECTED, $sheet->getStyle('J2')->getProtection()->getLocked(), 'purchase status column is editable');
assert_same('https://detail.1688.com/offer/123456.html', $sheet->getCell('N2')->getHyperlink()->getUrl(), 'purchase link hyperlink');
$spreadsheet->disconnectWorksheets();

$importer = new CsvImportService();
$parsed = $importer->parseFile($file['path'], 'purchase_import', ['platform' => 'w']);
@unlink($file['path']);

assert_same(1, $parsed['row_count'], 'import row count');
assert_same([], $parsed['errors'], 'import parse errors');
assert_same(1, count($parsed['records']), 'import record count');

$record = $parsed['records'][0];
assert_same('w', $record['identity']['platform'] ?? null, 'identity platform');
assert_same('W-202607020001', $record['identity']['platform_order_id'] ?? null, 'identity order id');
assert_same('D-001', $record['identity']['order_detail_id'] ?? null, 'identity detail id');
assert_same('ITEM-001', $record['identity']['item_code'] ?? null, 'identity item code');
assert_same('未采购', $record['changes']['purchase_status'] ?? null, 'purchase status');
assert_same('2026-07-02 00:00:00', $record['changes']['purchase_time'] ?? null, 'purchase time');
assert_same(66.6, $record['changes']['amount'] ?? null, 'purchase amount');
assert_same('168812345678', $record['changes']['tabaono'] ?? null, '1688 order number');
assert_same('https://detail.1688.com/offer/123456.html', $record['changes']['purchase_link'] ?? null, 'purchase link');

echo "Purchase XLSX workflow test passed.\n";

function assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function assert_true(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "{$label}: assertion failed" . PHP_EOL);
        exit(1);
    }
}
