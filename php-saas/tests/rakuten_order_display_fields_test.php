<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $basePath);
}
require $basePath . '/vendor/autoload.php';
require $basePath . '/app/Core/helpers.php';

$tenantKey = 'erp';
$orderView = 'platform';
$returnUrl = '/orders?tenant=erp&platform=r';
$batchFormId = 'batch-platform';
$seq = 1;
$stores = [['id' => 1, 'platform' => 'r', 'name' => 'Rakuten Store', 'short' => 'R店', 'legacy_dpid' => 'r-shop']];
$statusOptions = [];
$jpStockStatusOptions = [];
$currentUser = ['permissions' => ['订单查看', '订单编辑']];
$canEditOrders = true;
$canEditPurchase = true;
$canEditJp = false;
$canChangeSource = true;
$canBatchOperate = false;
$canBatchPurchase = false;
$canBatchJp = false;
$canUploadImage = false;
$canDeleteImage = false;
$can1688Logistics = false;
$receiptCityOptions = [];
$order = [
    'id' => 1,
    'store_id' => 1,
    'platform' => 'r',
    'platform_order_id' => 'R-1',
    'order_date' => '2026-07-09 10:00:00',
    'imported_at' => '2026-07-09 10:01:00',
    'store' => 'Rakuten Store',
    'customer' => ['name' => '测试客户'],
    'items' => [[
        'id' => 10,
        'source_type' => 'pending',
        'purchase_status' => '未处理的订单',
        'item_code' => 'SKU-1',
        'lot_number' => '',
        'jp_warehouse_id' => '',
        'item_management_id' => 'MGR-1',
        'image' => '',
        'main_image' => '',
        'sku_image' => '',
        'title' => '不应展示的商品标题',
        'option' => '颜色:黑色 サイズ:M',
        'quantity' => 1,
        'unit_price' => 1000,
        'postage_price' => 0,
        'pay_charge' => 0,
        'line_total' => 1000,
        'platform_extra' => [
            'SubCodeOption' => '颜色:黑色 サイズ:M',
            'selectedChoice' => '包装:不要',
        ],
        'buyer' => '',
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
        'receipt_city' => '',
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

assert_true(str_contains($html, '<th class="c8" colspan="2">項目・選択肢</th>'), 'Rakuten header uses selected choice label');
assert_true(str_contains($html, '<td>颜色:黑色 サイズ:M</td>'), 'Rakuten product attributes show SubCodeOption');
assert_true(str_contains($html, '<span class="stack-main">包装:不要</span>'), 'Rakuten selected choice is shown in item choice column');
assert_true(!str_contains($html, '<span class="stack-main">不应展示的商品标题</span>'), 'Rakuten item choice column does not show product title');

echo "Rakuten order display fields test passed.\n";

function assert_true(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "{$label}: assertion failed" . PHP_EOL);
        exit(1);
    }
}
