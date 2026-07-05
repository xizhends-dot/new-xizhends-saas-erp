<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $basePath);
}
require $basePath . '/vendor/autoload.php';

use Xizhen\Core\JsonStore;
use Xizhen\Services\PurchaseStatsService;

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

$jsonPath = sys_get_temp_dir() . '/xizhen-purchase-performance-' . bin2hex(random_bytes(6)) . '.json';
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
            'platform_order_id' => 'R-CAIGOU-001',
            'store' => '乐天一店',
            'order_date' => '2026-07-01 10:00',
            'items' => [
                [
                    'id' => 1001,
                    'item_code' => 'RA-1',
                    'title' => '商品A',
                    'quantity' => 2,
                    'source_type' => 'cn_purchase',
                    'purchase_status' => '国内采购-已采购',
                    'buyer' => '张三',
                    'purchase_time' => '2026-07-02 09:10:00',
                    'amount' => 120.5,
                    'tabaono' => '1688-A',
                ],
                [
                    'id' => 1002,
                    'item_code' => 'RA-2',
                    'title' => '商品B',
                    'quantity' => 1,
                    'source_type' => 'cn_purchase',
                    'purchase_status' => '国内采购-TB/PDD已采购',
                    'buyer' => '张三',
                    'purchase_time' => '2026-07-02 09:20:00',
                    'amount' => 30,
                    'tabaono' => '1688-A',
                ],
                [
                    'id' => 1003,
                    'item_code' => 'RA-3',
                    'title' => '未完成商品',
                    'quantity' => 1,
                    'source_type' => 'cn_purchase',
                    'purchase_status' => '国内采购-准备',
                    'buyer' => '张三',
                    'purchase_time' => '',
                    'amount' => 999,
                    'tabaono' => '1688-SKIP',
                ],
                [
                    'id' => 1004,
                    'item_code' => 'RA-4',
                    'title' => '未分配商品',
                    'quantity' => 1,
                    'source_type' => 'cn_purchase',
                    'purchase_status' => '国内采购-已采购',
                    'buyer' => '',
                    'purchase_time' => '2026-07-02 09:30:00',
                    'amount' => 888,
                    'tabaono' => '1688-NO-BUYER',
                ],
                [
                    'id' => 1005,
                    'item_code' => 'RA-JP',
                    'title' => '日本仓商品',
                    'quantity' => 1,
                    'source_type' => 'jp_stock',
                    'purchase_status' => '日本仓库已处理',
                    'buyer' => '仓库员',
                    'purchase_time' => '2026-07-02 10:00:00',
                    'amount' => 777,
                    'tabaono' => '',
                ],
            ],
        ],
        [
            'id' => 11,
            'platform' => 'w',
            'platform_order_id' => 'W-CAIGOU-002',
            'store' => 'Wowma一店',
            'order_date' => '2026-07-03 10:00',
            'items' => [[
                'id' => 1101,
                'item_code' => 'WM-1',
                'title' => '商品C',
                'quantity' => 1,
                'source_type' => 'cn_purchase',
                'purchase_status' => '国内采购-已采购',
                'buyer' => '李四',
                'purchase_time' => '2026-07-03 11:00:00',
                'amount' => 50,
                'tabaono' => '',
            ]],
        ],
    ]],
    'users' => ['erp' => []],
    'stores' => ['erp' => []],
];
file_put_contents($jsonPath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

try {
    $stats = (new PurchaseStatsService(new JsonStore($jsonPath)))->purchaseStats('erp', null, [
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-03',
    ]);

    $assertSame('只统计有采购人且有采购时间的记录', 3, $stats['totals']['item_count'] ?? null);
    $assertSame('统计采购件数', 4, $stats['totals']['quantity'] ?? null);
    $assertFloat('金额只汇总有 1688 单号的有效金额', 150.5, $stats['totals']['amount'] ?? null);
    $assertSame('1688 单号去重', 1, $stats['totals']['unique_orders'] ?? null);
    $assertSame('采购员数量', 2, $stats['totals']['buyer_count'] ?? null);

    $buyers = [];
    foreach ((array) ($stats['buyers'] ?? []) as $row) {
        $buyers[(string) ($row['buyer'] ?? '')] = $row;
    }
    $assertSame('张三完成采购数', 2, $buyers['张三']['item_count'] ?? null);
    $assertSame('张三 1688 去重数', 1, $buyers['张三']['unique_orders'] ?? null);
    $assertFloat('张三采购金额', 150.5, $buyers['张三']['amount'] ?? null);
    $assertSame('李四完成采购数', 1, $buyers['李四']['item_count'] ?? null);
    $assertSame('李四无 1688 单号不计去重', 0, $buyers['李四']['unique_orders'] ?? null);

    $traceOrderNos = array_column((array) ($stats['trace_rows'] ?? []), 'item_code');
    $assertSame('未完成记录不进追溯', false, in_array('RA-3', $traceOrderNos, true));
    $assertSame('未分配记录不进追溯', false, in_array('RA-4', $traceOrderNos, true));
    $assertSame('日本仓记录不进采购业绩追溯', false, in_array('RA-JP', $traceOrderNos, true));
} finally {
    @unlink($jsonPath);
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "purchase_performance_stats_test passed\n";
