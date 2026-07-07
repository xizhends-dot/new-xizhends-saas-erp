<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $basePath);
}
require $basePath . '/vendor/autoload.php';
require $basePath . '/app/Core/helpers.php';

use Xizhen\Core\JsonStore;
use Xizhen\Services\PriceCalculatorService;

$failures = [];
$assertSame = static function (string $label, mixed $expected, mixed $actual) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
};
$assertFloat = static function (string $label, float $expected, mixed $actual) use (&$failures): void {
    if (abs($expected - (float) $actual) > 0.0001) {
        $failures[] = $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
};

$jsonPath = sys_get_temp_dir() . '/xizhen-price-quote-' . bin2hex(random_bytes(6)) . '.json';
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
    'orders' => ['erp' => [[
        'id' => 10,
        'platform' => 'r',
        'platform_order_id' => 'R-PRICE-001',
        'store_id' => 1,
        'store' => '乐天一店',
        'order_date' => '2026-07-05 10:00',
        'status' => '未处理的订单',
        'customer' => [],
        'items' => [
            [
                'id' => 1001,
                'item_code' => 'SKU-1',
                'title' => '商品1',
                'quantity' => 1,
                'unit_price' => 1200,
                'postage_price' => 100,
                'pay_charge' => 0,
                'line_total' => 1300,
                'amount' => 36,
                'cn_amount' => 8,
                'com_amount' => 66,
            ],
            [
                'id' => 1002,
                'item_code' => 'SKU-2',
                'title' => '商品2',
                'quantity' => 1,
                'unit_price' => 800,
                'postage_price' => 0,
                'pay_charge' => 0,
                'line_total' => 800,
                'amount' => 20,
                'cn_amount' => 5,
                'com_amount' => 0,
            ],
        ],
    ]]],
    'stores' => ['erp' => [
        ['id' => 1, 'legacy_dpid' => '1', 'platform' => 'r', 'short' => 'R-01', 'name' => '乐天一店', 'status' => 'visible', 'api_status' => '已配置', 'api_config' => '', 'profit_deduction' => 68, 'hidden_reason' => '', 'created_by' => 'test', 'created_at' => '2026-07-05 00:00'],
    ]],
    'users' => ['erp' => []],
    'assignments' => ['erp' => []],
    'attachments' => ['erp' => []],
    'settings' => ['global' => [], 'tenant' => [
        'erp' => [
            'profit' => [
                'exchange_rate' => 0.05,
                'exchange_rate_mode' => 'fixed',
                'fixed_exchange_rate' => 0.05,
                'default_intl_fee' => 40,
                'store_deduction_enabled' => true,
                'platform_deductions' => ['r' => 70],
            ],
        ],
    ]],
    'import_export_logs' => ['erp' => []],
    'purchase_status_events' => ['erp' => []],
    'billing' => ['ledger' => ['erp' => []], 'subscriptions' => ['erp' => []]],
    'mail' => ['accounts' => ['erp' => []], 'folders' => ['erp' => []], 'messages' => ['erp' => []], 'replies' => ['erp' => []], 'rules' => ['erp' => []], 'settings' => ['erp' => []]],
];
file_put_contents($jsonPath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$store = new JsonStore($jsonPath);
$service = new PriceCalculatorService($store);
$order = $store->orders('erp')[0];
$item = $order['items'][0];

$quote = $service->quoteOrderItem('erp', $order, $item, [
    'sale_price' => 1500,
    'shipping' => 55,
    'deduction' => 72,
    'cost' => 30,
]);
$expected = $service->calculateRow([
    'name' => '商品1',
    'cost' => 30,
    'shipping' => 55,
    'deduction' => 72,
    'exchange_rate' => 0.05,
    'sale_price' => 1500,
], ['exchange_rate' => 0.05, 'shipping' => 40, 'deduction' => 68], 1);
$assertSame('quote 与 calculateRow 利润一致', $expected['profit'], $quote['profit']);
$assertSame('quote 与 calculateRow 利润率一致', $expected['profit_rate'], $quote['profit_rate']);
$assertSame('camelCase realProfit 兼容别名', $quote['profit'], $quote['realProfit']);
$assertSame('camelCase actualIncome 兼容别名', $quote['actual_income'], $quote['actualIncome']);

$actualShippingQuote = $service->quoteOrderItem('erp', $order, $item);
$assertFloat('有 com_amount 时使用实际运费', 66.0, $actualShippingQuote['shipping']);
$assertSame('实际运费来源', 'actual_com_amount', $actualShippingQuote['shipping_source']);
$assertFloat('店铺扣点优先', 68.0, $actualShippingQuote['deduction']);

$orderWithoutShipping = $order;
$orderWithoutShipping['items'][0]['com_amount'] = 0;
$defaultShippingQuote = $service->quoteOrderItem('erp', $orderWithoutShipping, $orderWithoutShipping['items'][0]);
$assertFloat('无 com_amount 时使用租户默认运费', 40.0, $defaultShippingQuote['shipping']);
$assertSame('默认运费来源', 'tenant_default', $defaultShippingQuote['shipping_source']);

$htmlWithoutPermission = render_order_block_for_price_quote([
    'role' => '客服',
    'permissions' => [],
    'permission_overrides' => ['allow' => [], 'deny' => ['订单查看', '订单编辑']],
    'stores' => [],
    'is_company_admin' => false,
], $order);
$assertSame('无订单权限不渲染核价触发标记', false, str_contains($htmlWithoutPermission, 'data-price-quote-trigger'));

$htmlWithPermission = render_order_block_for_price_quote([
    'role' => '客服',
    'permissions' => ['订单查看'],
    'stores' => ['全部店铺'],
    'is_company_admin' => false,
], $order);
$assertSame('有订单查看权限渲染核价触发标记', true, str_contains($htmlWithPermission, 'data-price-quote-trigger'));

@unlink($jsonPath);

if ($failures !== []) {
    fwrite(STDERR, "Price quote test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Price quote test passed.\n";

/**
 * @param array<string, mixed> $currentUser
 * @param array<string, mixed> $order
 */
function render_order_block_for_price_quote(array $currentUser, array $order): string
{
    $tenantKey = 'erp';
    $orderView = 'platform';
    $returnUrl = '/orders?tenant=erp';
    $statusOptions = [];
    $canEditOrders = false;
    $canEditPurchase = false;
    $canEditJp = false;
    $canChangeSource = false;
    $canBatchOperate = false;
    $canBatchPurchase = false;
    $canBatchJp = false;
    $seq = 1;
    foreach ($order['items'] as &$item) {
        $item += [
            'image' => '',
            'title' => '',
            'item_code' => '',
            'jp_warehouse_id' => '',
            'option' => '',
            'purchase_status' => '',
            'source_type' => 'pending',
            'buyer' => '',
            'purchase_time' => '',
            'purchase_link' => '',
            'comment' => '',
            'tabaono' => '',
            'ship_company' => '',
            'ship_number' => '',
        ];
    }
    unset($item);

    ob_start();
    require BASE_PATH . '/app/Views/tenant/partials/order_block.php';
    return (string) ob_get_clean();
}
