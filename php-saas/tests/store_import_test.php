<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';
require $basePath . '/app/Core/helpers.php';

use Xizhen\Core\JsonStore;
use Xizhen\Services\CsvImportService;

$failures = [];
$assertSame = static function (string $label, mixed $expected, mixed $actual) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
};
$assert = static function (string $label, bool $ok) use (&$failures): void {
    if (!$ok) {
        $failures[] = $label;
    }
};

$jsonPath = sys_get_temp_dir() . '/xizhen-store-import-' . bin2hex(random_bytes(6)) . '.json';
$data = [
    'admins' => [],
    'platforms' => [
        ['code' => 'r', 'name' => 'Rakuten', 'short' => 'Rakuten', 'color' => '#2563eb'],
    ],
    'tenants' => [[
        'id' => 1,
        'key' => 'erp',
        'company_name' => '测试租户',
        'short_name' => '测试',
        'subdomain' => 'erp',
        'db_name' => 'xizhen_tenant_erp',
        'plan' => 'basic',
        'status' => 'active',
        'staff_count' => 0,
        'balance' => 0,
        'contact' => '',
        'phone' => '',
        'platforms' => [['code' => 'r', 'enabled' => true, 'locked' => false]],
        'features' => [],
    ]],
    'announcements' => [],
    'orders' => ['erp' => []],
    'stores' => ['erp' => [
        ['id' => 1, 'legacy_dpid' => '1', 'platform' => 'r', 'short' => 'R-01', 'name' => '乐天一店', 'status' => 'visible', 'api_status' => '已配置', 'api_config' => '', 'profit_deduction' => 70, 'hidden_reason' => '', 'created_by' => 'test', 'created_at' => '2026-07-04 00:00'],
        ['id' => 2, 'legacy_dpid' => '2', 'platform' => 'r', 'short' => 'R-02', 'name' => '乐天二店', 'status' => 'visible', 'api_status' => '已配置', 'api_config' => '', 'profit_deduction' => 70, 'hidden_reason' => '', 'created_by' => 'test', 'created_at' => '2026-07-04 00:00'],
    ]],
    'users' => ['erp' => []],
    'assignments' => ['erp' => []],
    'attachments' => ['erp' => []],
    'settings' => ['global' => [], 'tenant' => []],
    'import_export_logs' => ['erp' => []],
    'purchase_status_events' => ['erp' => []],
    'billing' => ['ledger' => ['erp' => []], 'subscriptions' => ['erp' => []]],
    'mail' => ['accounts' => ['erp' => []], 'folders' => ['erp' => []], 'messages' => ['erp' => []], 'replies' => ['erp' => []], 'rules' => ['erp' => []], 'settings' => ['erp' => []]],
];
file_put_contents($jsonPath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$store = new JsonStore($jsonPath);
$importer = new CsvImportService();
$selectedStore = $store->store('erp', 1);
$otherStore = $store->store('erp', 2);

$parse = static function (array $rows, array $context) use ($importer): array {
    $path = tempnam(sys_get_temp_dir(), 'xizhen-store-import-csv-');
    $handle = fopen($path, 'w');
    fputcsv($handle, ['平台', '店铺', '订单号', '订单日期', '商品编码', '商品名', '数量', '单价', '合计', '收件人']);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);
    $parsed = $importer->parseFile($path, 'platform_orders_import', $context);
    @unlink($path);
    return $parsed;
};

$context = [
    'platform' => 'r',
    'store_id' => 1,
    'platform_names' => ['r' => 'Rakuten'],
    'stores' => [$selectedStore],
    'restrict_to_store_id' => true,
];

$emptyStore = $parse([
    ['r', '', 'STORE-IMPORT-EMPTY', '2026-07-04', 'SKU-1', '商品1', '1', '100', '100', '佐藤'],
], $context);
$assertSame('空店铺列解析一条记录', 1, count($emptyStore['records']));
$store->importPlatformOrders('erp', $emptyStore['records'], 'tester');
$importedEmpty = find_order($store->orders('erp'), 'STORE-IMPORT-EMPTY');
$assertSame('空店铺列归属所选店铺 ID', 1, (int) ($importedEmpty['store_id'] ?? 0));
$assertSame('空店铺列归属所选店铺名', '乐天一店', (string) ($importedEmpty['store'] ?? ''));

$matchedStore = $parse([
    ['r', 'R-01', 'STORE-IMPORT-MATCH', '2026-07-04', 'SKU-2', '商品2', '1', '200', '200', '田中'],
], $context);
$assertSame('店铺缩写匹配解析一条记录', 1, count($matchedStore['records']));
$store->importPlatformOrders('erp', $matchedStore['records'], 'tester');
$importedMatched = find_order($store->orders('erp'), 'STORE-IMPORT-MATCH');
$assertSame('店铺缩写匹配归属所选店铺 ID', 1, (int) ($importedMatched['store_id'] ?? 0));

$mismatchedStore = $parse([
    ['r', '乐天二店', 'STORE-IMPORT-MISMATCH', '2026-07-04', 'SKU-3', '商品3', '1', '300', '300', '山田'],
], $context);
$assertSame('他店行被跳过无记录', 0, count($mismatchedStore['records']));
$assertSame('他店行跳过计数', 1, (int) ($mismatchedStore['store_mismatch_count'] ?? 0));
$assert('他店行错误提示', str_contains(implode('；', $mismatchedStore['errors']), '店铺列与所选店铺不符'));

$globalContext = [
    'platform' => 'r',
    'store_id' => 1,
    'platform_names' => ['r' => 'Rakuten'],
    'stores' => [$selectedStore, $otherStore],
];
$global = $parse([
    ['r', '乐天二店', 'STORE-IMPORT-GLOBAL', '2026-07-04', 'SKU-4', '商品4', '1', '400', '400', '铃木'],
], $globalContext);
$assertSame('无 restrict 时旧逻辑仍按 selectedId 优先', 1, count($global['records']));
$assertSame('无 restrict 时仍归属 selectedId', 1, (int) ($global['records'][0]['order']['store_id'] ?? 0));
$assertSame('无 restrict 时无店铺不匹配计数', 0, (int) ($global['store_mismatch_count'] ?? 0));

@unlink($jsonPath);

if ($failures !== []) {
    fwrite(STDERR, "Store import test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Store import test passed.\n";

/** @param array<int, array<string, mixed>> $orders */
function find_order(array $orders, string $orderNo): array
{
    foreach ($orders as $order) {
        if ((string) ($order['platform_order_id'] ?? '') === $orderNo) {
            return $order;
        }
    }

    return [];
}
