<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $basePath);
}
require $basePath . '/vendor/autoload.php';
require $basePath . '/app/Core/helpers.php';

use Xizhen\Core\JsonStore;
use Xizhen\Services\ProfitService;

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

$jsonPath = sys_get_temp_dir() . '/xizhen-profit-analysis-' . bin2hex(random_bytes(6)) . '.json';
$data = [
    'admins' => [],
    'platforms' => [
        ['code' => 'r', 'name' => '乐天 Rakuten', 'short' => 'Rakuten', 'color' => '#2563eb'],
        ['code' => 'w', 'name' => 'Wowma', 'short' => 'Wowma', 'color' => '#14b8a6'],
    ],
    'tenants' => [[
        'id' => 1,
        'key' => 'erp',
        'company_name' => '测试租户',
        'short_name' => '测试',
        'subdomain' => 'erp',
        'db_name' => 'xizhends_tenant_erp',
        'plan' => 'basic',
        'status' => 'active',
        'staff_count' => 0,
        'balance' => 0,
        'contact' => '',
        'phone' => '',
        'platforms' => [
            ['code' => 'r', 'enabled' => true, 'locked' => false],
            ['code' => 'w', 'enabled' => true, 'locked' => false],
        ],
        'features' => [],
    ]],
    'announcements' => [],
    'orders' => ['erp' => [
        [
            'id' => 10,
            'platform' => 'r',
            'platform_order_id' => 'R-PROFIT-001',
            'store_id' => 1,
            'store' => '乐天一店',
            'order_date' => '2026-07-05 10:00',
            'status' => '未处理的订单',
            'postage_price' => 600,
            'customer' => [],
            'items' => [
                [
                    'id' => 1001,
                    'item_code' => 'RA-1',
                    'lot_number' => 'LOT-A',
                    'item_management_id' => 'SKU-A',
                    'title' => '商品A',
                    'quantity' => 1,
                    'source_type' => 'cn_purchase',
                    'purchase_status' => '国内采购-已采购',
                    'unit_price' => 1000,
                    'postage_price' => 600,
                    'line_total' => 1000,
                    'amount' => 30,
                    'com_amount' => 40,
                    'intl_fee' => 0,
                    'tabaono' => '1688-A',
                ],
                [
                    'id' => 1002,
                    'item_code' => 'RA-2',
                    'lot_number' => 'LOT-B',
                    'item_management_id' => 'SKU-B',
                    'title' => '商品B',
                    'quantity' => 1,
                    'source_type' => 'cn_purchase',
                    'purchase_status' => '国内采购-准备',
                    'unit_price' => 500,
                    'postage_price' => 600,
                    'line_total' => 500,
                    'amount' => 20,
                    'com_amount' => 0,
                    'intl_fee' => 0,
                    'tabaono' => '1688-B',
                ],
            ],
        ],
        [
            'id' => 11,
            'platform' => 'w',
            'platform_order_id' => 'W-PROFIT-002',
            'store_id' => 2,
            'store' => 'Wowma一店',
            'order_date' => '2026-07-04 09:00',
            'status' => '未处理的订单',
            'postage_price' => 0,
            'customer' => [],
            'items' => [[
                'id' => 1003,
                'item_code' => 'WM-1',
                'lot_number' => 'LOT-W',
                'item_management_id' => 'SKU-W',
                'title' => '商品W',
                'quantity' => 1,
                'source_type' => 'cn_purchase',
                'purchase_status' => '国内采购-已采购',
                'unit_price' => 1200,
                'postage_price' => 0,
                'line_total' => 1200,
                'amount' => 35,
                'com_amount' => 0,
                'intl_fee' => 0,
                'tabaono' => '1688-W',
            ]],
        ],
        [
            'id' => 12,
            'platform' => 'r',
            'platform_order_id' => 'R-PROFIT-003',
            'store_id' => 1,
            'store' => '乐天一店',
            'order_date' => '2026-07-06 10:00',
            'status' => '未处理的订单',
            'postage_price' => 0,
            'customer' => [],
            'items' => [[
                'id' => 1004,
                'item_code' => 'RA-CANCEL',
                'lot_number' => 'LOT-C',
                'item_management_id' => 'SKU-C',
                'title' => '取消商品',
                'quantity' => 1,
                'source_type' => 'cn_purchase',
                'purchase_status' => '已取消',
                'unit_price' => 2000,
                'postage_price' => 0,
                'line_total' => 2000,
                'amount' => 10,
                'com_amount' => 0,
                'intl_fee' => 0,
                'tabaono' => '1688-C',
            ]],
        ],
    ]],
    'stores' => ['erp' => [
        ['id' => 1, 'legacy_dpid' => 'r1', 'platform' => 'r', 'short' => 'R-01', 'name' => '乐天一店', 'status' => 'visible', 'api_status' => '已配置', 'api_config' => '', 'profit_deduction' => 70, 'hidden_reason' => '', 'created_by' => 'test', 'created_at' => '2026-07-05 00:00'],
        ['id' => 2, 'legacy_dpid' => 'w1', 'platform' => 'w', 'short' => 'W-01', 'name' => 'Wowma一店', 'status' => 'visible', 'api_status' => '已配置', 'api_config' => '', 'profit_deduction' => 70, 'hidden_reason' => '', 'created_by' => 'test', 'created_at' => '2026-07-05 00:00'],
    ]],
    'users' => ['erp' => []],
    'assignments' => ['erp' => []],
    'attachments' => ['erp' => []],
    'settings' => ['global' => [], 'tenant' => [
        'erp' => [
            'profit' => [
                'exchange_rate' => 0.05,
                'fixed_exchange_rate' => 0.05,
                'default_intl_fee' => 50,
                'store_deduction_enabled' => true,
                'platform_deductions' => ['r' => 70, 'w' => 70],
                'excluded_purchase_statuses' => ['已取消'],
            ],
        ],
    ]],
    'import_export_logs' => ['erp' => []],
    'purchase_status_events' => ['erp' => []],
    'billing' => ['ledger' => ['erp' => []], 'subscriptions' => ['erp' => []]],
    'mail' => ['accounts' => ['erp' => []], 'folders' => ['erp' => []], 'messages' => ['erp' => []], 'replies' => ['erp' => []], 'rules' => ['erp' => []], 'settings' => ['erp' => []]],
];
file_put_contents($jsonPath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$service = new ProfitService(new JsonStore($jsonPath));

$analysis = $service->profitAnalysis('erp', null, [
    'platform' => 'r',
    'profit_threshold' => '5',
    'target_profit_rate' => '15',
    'per_page' => '50',
]);

$assertSame('R 平台默认核算两条明细', 2, $analysis['total_count']);
$assertSame('排除状态不出现在采购状态选项', false, in_array('已取消', $analysis['status_options'], true));
$assertFloat('Y/R 邮费按订单明细平均分摊', 300.0, $analysis['rows'][0]['postage']);
$assertFloat('实际运费按订单明细平均分摊', 20.0, $analysis['rows'][0]['shipping']);
$assertSame('第一条使用实际运费', true, $analysis['rows'][0]['has_actual_shipping']);
$assertFloat('第一条实际利润', -4.5, $analysis['rows'][0]['profit']);
$assertFloat('第一条实际利润率', -6.92, $analysis['rows'][0]['profit_rate']);
$assertSame('低利润时反推建议售价', 1819, $analysis['rows'][0]['suggested_price']);

$actualOnly = $service->profitAnalysis('erp', null, ['platform' => 'r', 'shipping_type' => 'actual']);
$assertSame('实际运费筛选保留同订单两条明细', 2, $actualOnly['total_count']);

$estimateOnly = $service->profitAnalysis('erp', null, ['platform' => 'w', 'shipping_type' => 'estimate']);
$assertSame('预估运费筛选命中 Wowma 明细', 1, $estimateOnly['total_count']);
$assertSame('预估运费来源', false, $estimateOnly['rows'][0]['has_actual_shipping']);
$assertFloat('预估运费使用默认运费', 50.0, $estimateOnly['rows'][0]['shipping']);

$lowOnly = $service->profitAnalysis('erp', null, [
    'platform' => 'r',
    'profit_threshold' => '5',
    'filter_low_profit' => '1',
]);
$assertSame('只显示低利润过滤后仍保留两条亏损明细', 2, $lowOnly['total_count']);

$allStatuses = $service->profitAnalysis('erp', null, [
    'platform' => 'r',
    'status' => '__ALL__',
]);
$assertSame('全部状态仍排除系统设置中的采购状态', 2, $allStatuses['total_count']);

@unlink($jsonPath);

if ($failures !== []) {
    fwrite(STDERR, "Profit analysis test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Profit analysis test passed.\n";
