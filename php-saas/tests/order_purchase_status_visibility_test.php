<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Xizhen\Core\JsonStore;
use Xizhen\Services\OrderFilterService;

$failures = [];
$assertSame = static function (string $label, mixed $expected, mixed $actual) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
};

$service = new OrderFilterService(new JsonStore(sys_get_temp_dir() . '/xizhen-order-filter-' . bin2hex(random_bytes(6)) . '.json'));
$orders = [[
    'id' => 1,
    'platform' => 'r',
    'platform_order_id' => 'R-VISIBILITY-1',
    'imported_at' => '2026-07-06 10:00:00',
    'order_date' => '2026-07-06 10:00:00',
    'store' => '测试店铺',
    'customer' => ['name' => '测试客户', 'phone' => ''],
    'items' => [
        [
            'id' => 11,
            'source_type' => 'cn_purchase',
            'purchase_status' => '国内采购-准备',
            'item_code' => 'SKU-1',
            'title' => '测试商品',
            'tabaono' => 'T-1',
        ],
        [
            'id' => 12,
            'source_type' => 'cn_purchase',
            'purchase_status' => '国内采购-已采购',
            'item_code' => 'SKU-2',
            'title' => '已采购商品',
            'tabaono' => 'T-2',
        ],
        [
            'id' => 13,
            'source_type' => 'jp_stock',
            'purchase_status' => '日本库存订单',
            'item_code' => 'SKU-3',
            'title' => '日本仓商品',
            'tabaono' => '',
        ],
        [
            'id' => 14,
            'source_type' => 'pending',
            'purchase_status' => '未处理的订单',
            'item_code' => 'SKU-4',
            'title' => '待判定商品',
            'tabaono' => '',
        ],
    ],
]];

$purchaseDefaultFilters = [
    'status' => '',
    'page_size' => '200',
    'date_scope' => 'purchase',
    'default_pending' => '1',
    'keyword' => '',
];

$purchaseDefault = $service->filterOrdersForView($orders, 'purchase', null, 'all', null, $purchaseDefaultFilters);
$assertSame('采购页默认只显示待处理国内采购订单项', [11], array_column($purchaseDefault[0]['items'] ?? [], 'id'));

$platformDefaultFilters = [
    'status' => '',
    'page_size' => '200',
    'date_scope' => 'imported',
    'default_pending' => '1',
    'keyword' => '',
];
$hiddenOnPlatform = $service->filterOrdersForView($orders, 'platform', null, 'all', null, $platformDefaultFilters);
$assertSame('平台页默认待处理筛选仍隐藏已完成状态订单项', [11, 14], array_column($hiddenOnPlatform[0]['items'] ?? [], 'id'));

$explicitAll = $service->filterOrdersForView($orders, 'purchase', null, 'all', null, array_merge($purchaseDefaultFilters, ['status' => '__ALL__', 'all_orders' => '1']));
$assertSame('采购页显式全部订单只显示国内采购订单项', [11, 12], array_column($explicitAll[0]['items'] ?? [], 'id'));

$legacyAll = $service->filterOrdersForView($orders, 'purchase', null, 'all', null, array_merge($purchaseDefaultFilters, ['status' => '__ALL__']));
$assertSame('采购页兼容老系统全部订单状态值但不跨货源地', [11, 12], array_column($legacyAll[0]['items'] ?? [], 'id'));

$jpExplicitAll = $service->filterOrdersForView($orders, 'jp', null, 'all', null, array_merge($purchaseDefaultFilters, ['status' => '__ALL__', 'all_orders' => '1']));
$assertSame('日本仓页显式全部订单只显示日本仓订单项', [13], array_column($jpExplicitAll[0]['items'] ?? [], 'id'));

$multiOrderSearch = $service->filterOrdersForView([
    [
        'id' => 2,
        'platform' => 'r',
        'platform_order_id' => 'R-SEARCH-1',
        'imported_at' => '2026-07-06 10:00:00',
        'order_date' => '2026-07-06 10:00:00',
        'store' => '测试店铺',
        'customer' => ['name' => '客户一', 'phone' => ''],
        'items' => [['id' => 21, 'source_type' => 'pending', 'purchase_status' => '未处理的订单', 'item_code' => 'SKU-21', 'title' => '商品一']],
    ],
    [
        'id' => 3,
        'platform' => 'r',
        'platform_order_id' => 'R-SEARCH-2',
        'imported_at' => '2026-07-06 10:00:00',
        'order_date' => '2026-07-06 10:00:00',
        'store' => '测试店铺',
        'customer' => ['name' => '客户二', 'phone' => ''],
        'items' => [['id' => 31, 'source_type' => 'pending', 'purchase_status' => '未处理的订单', 'item_code' => 'SKU-31', 'title' => '商品二']],
    ],
    [
        'id' => 4,
        'platform' => 'r',
        'platform_order_id' => 'R-SEARCH-3',
        'imported_at' => '2026-07-06 10:00:00',
        'order_date' => '2026-07-06 10:00:00',
        'store' => '测试店铺',
        'customer' => ['name' => '客户三', 'phone' => ''],
        'items' => [['id' => 41, 'source_type' => 'pending', 'purchase_status' => '未处理的订单', 'item_code' => 'SKU-41', 'title' => '商品三']],
    ],
], 'platform', 'r', 'all', 'R-SEARCH-1 R-SEARCH-3', array_merge($platformDefaultFilters, ['status' => '__ALL__', 'all_orders' => '1']));
$assertSame('订单号搜索支持空格分隔多个订单号', ['R-SEARCH-1', 'R-SEARCH-3'], array_column($multiOrderSearch, 'platform_order_id'));

if ($failures !== []) {
    fwrite(STDERR, "Order purchase status visibility test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Order purchase status visibility test OK\n";
