<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $basePath);
}
require $basePath . '/vendor/autoload.php';
require $basePath . '/app/Core/helpers.php';

use Xizhen\Core\JsonStore;

$jsonPath = sys_get_temp_dir() . '/xizhen-order-item-save-receipt-city-' . bin2hex(random_bytes(6)) . '.json';
$data = [
    'admins' => [],
    'platforms' => [],
    'tenants' => [],
    'announcements' => [],
    'orders' => ['erp' => [[
        'id' => 10,
        'platform' => 'y',
        'platform_order_id' => 'Y-RECEIPT-CITY-1',
        'items' => [[
            'id' => 200,
            'source_type' => 'cn_purchase',
            'purchase_status' => '国内采购-准备',
            'ship_quantity' => 0,
            'receipt_city' => '',
            'logs' => [],
        ]],
    ]]],
    'stores' => ['erp' => []],
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

$failures = [];
$assertSame = static function (string $label, mixed $expected, mixed $actual) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
};

try {
    $store = new JsonStore($jsonPath);
    $store->updateOrderItem('erp', 200, [
        'receipt_city' => '深圳威通',
        'ship_quantity' => '3',
    ], '测试员', '保存明细');
    $saved = json_decode((string) file_get_contents($jsonPath), true) ?: [];
    $item = $saved['orders']['erp'][0]['items'][0] ?? [];

    $assertSame('签收地保存到订单子项', '深圳威通', $item['receipt_city'] ?? null);
    $assertSame('件数按整数保存', 3, $item['ship_quantity'] ?? null);
    $logFields = array_map(static fn (array $log): string => (string) ($log['field'] ?? ''), $item['logs'] ?? []);
    $assertSame('签收地写入操作日志', true, in_array('receipt_city', $logFields, true));
} finally {
    @unlink($jsonPath);
}

if ($failures !== []) {
    fwrite(STDERR, "Order item save receipt city test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Order item save receipt city test OK\n";
