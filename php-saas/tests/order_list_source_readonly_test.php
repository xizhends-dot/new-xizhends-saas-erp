<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $basePath);
}
require $basePath . '/vendor/autoload.php';
require $basePath . '/app/Core/helpers.php';

$failures = [];
$assert = static function (string $label, bool $ok) use (&$failures): void {
    if (!$ok) {
        $failures[] = $label;
    }
};

$tenantKey = 'erp';
$orderView = 'platform';
$returnUrl = '/orders?tenant=erp&view=platform';
$batchFormId = 'batch-platform';
$seq = 1;
$stores = [];
$statusOptions = ['未处理的订单', '国内采购-准备'];
$jpStockStatusOptions = ['日本仓待处理'];
$currentUser = ['permissions' => ['订单查看', '订单编辑', '货源改判']];
$canEditOrders = true;
$canEditPurchase = true;
$canEditJp = false;
$canChangeSource = true;
$canBatchOperate = true;
$canBatchPurchase = true;
$canBatchJp = false;
$order = [
    'id' => 100,
    'platform' => 'y',
    'platform_order_id' => 'Y-SOURCE-100',
    'order_date' => '2026-07-06 10:00:00',
    'imported_at' => '2026-07-06 10:05:00',
    'store' => '测试店铺',
    'customer' => ['name' => '测试客户'],
    'items' => [[
        'id' => 200,
        'source_type' => 'cn_purchase',
        'purchase_status' => '国内采购-准备',
        'item_code' => 'SKU-200',
        'lot_number' => '',
        'jp_warehouse_id' => '',
        'item_management_id' => '',
        'image' => '/assets/img/placeholder.png',
        'title' => '测试商品',
        'option' => '黑色',
        'quantity' => 1,
        'unit_price' => 1000,
        'postage_price' => 0,
        'pay_charge' => 0,
        'line_total' => 1000,
        'buyer' => '王五',
        'purchase_time' => '',
        'purchase_link' => '',
        'buhuo_link' => '',
        'comment' => '',
        'tranship_comment' => '',
        'chinese_option' => '',
        'purchase_amount' => '',
        'amount' => '',
        'cn_amount' => '',
        'tabaono' => '',
        'caigou_ordernums' => '',
        'ship_company' => '',
        'ship_number' => '',
        'logistics' => '',
        'logistic_trace' => '',
        'intl_number' => '',
        'intl_status' => '',
        'intl_fee' => '',
        'intl_qty' => '',
        'intl_weight' => '',
        'intl_comment' => '',
        'logs' => [],
    ]],
];

ob_start();
require $basePath . '/app/Views/tenant/partials/order_block.php';
$html = (string) ob_get_clean();

$assert('列表展示国内采购货源地标签', str_contains($html, '<span class="src-tag cn">国内采购</span>'));
$assert('列表不出现单项货源地修改表单', !str_contains($html, 'action="/orders/source"'));
$assert('列表不出现单项 source 下拉', !str_contains($html, 'name="source" aria-label="货源地"'));
$assert('列表编辑抽屉不提交 source_type', !str_contains($html, 'name="source_type"'));
$assert('平台订单采购信息默认收起', str_contains($html, 'purchase-info-table table-hidden'));
$assert('平台订单国际物流默认收起', str_contains($html, 'otable sec-c table-hidden'));
$assert('订单栏合并客人姓名和片假名表头', str_contains($html, '<th class="c3" colspan="2">客人姓名/片假名</th>'));
$assert('订单栏不再单独显示收件人表头', !str_contains($html, '<th class="c3">收件人</th>'));
$assert('订单栏不再单独显示假名表头', !str_contains($html, '<th class="c4">假名</th>'));

if ($failures !== []) {
    fwrite(STDERR, "Order list source readonly test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Order list source readonly test OK\n";
