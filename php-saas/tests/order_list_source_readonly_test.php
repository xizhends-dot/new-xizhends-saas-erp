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
$receiptCityOptions = ['义乌', '深圳威通'];
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
        'unit_price' => 900,
        'postage_price' => 0,
        'pay_charge' => 0,
        'line_total' => 1000,
        'platform_extra' => ['requestPrice' => '888'],
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
        'ship_company' => '中通',
        'ship_number' => 'CN123456',
        'receipt_city' => '义乌',
        'logistics' => '运输中',
        'logistic_trace' => '轨迹内容',
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
$purchaseInfoHtml = '';
if (preg_match('/<table class="otable sec-b purchase-info-table.*?<\/table>/s', $html, $purchaseMatches) === 1) {
    $purchaseInfoHtml = $purchaseMatches[0];
}

$requestOnlyOrder = $order;
$requestOnlyOrder['items'][0]['line_total'] = '';
$order = $requestOnlyOrder;
ob_start();
require $basePath . '/app/Views/tenant/partials/order_block.php';
$requestOnlyHtml = (string) ob_get_clean();

$assert('列表展示国内采购货源地标签', str_contains($html, '<span class="src-tag cn">国内采购</span>'));
$assert('列表不出现单项货源地修改表单', !str_contains($html, 'action="/orders/source"'));
$assert('列表不出现单项 source 下拉', !str_contains($html, 'name="source" aria-label="货源地"'));
$assert('列表编辑抽屉不提交 source_type', !str_contains($html, 'name="source_type"'));
$assert('平台订单采购信息默认收起', str_contains($html, 'purchase-info-table table-hidden'));
$assert('平台订单国际物流默认收起', str_contains($html, 'otable sec-c table-hidden'));
$assert('订单栏合并客人姓名和片假名表头', str_contains($html, '<th class="c3">客人姓名/片假名</th>'));
$assert('订单栏不再单独显示收件人表头', !str_contains($html, '<th class="c3">收件人</th>'));
$assert('订单栏不再单独显示假名表头', !str_contains($html, '<th class="c4">假名</th>'));
$assert('订单栏地址列加宽', str_contains($html, '<th class="c4" colspan="4">地址</th>'));
$assert('订单栏不再显示付款状态表头', !str_contains($html, '<th class="c12">付款状态</th>'));
$assert('订单栏不再显示付款日期表头', !str_contains($html, '<th class="c13">付款日期</th>'));

$assert('商品价格列改为单价表头', str_contains($html, '<th class="c11">单价</th>'));
$assert('商品金额列显示总价请求金额表头', str_contains($html, '<th class="c13">总价/请求金额</th>'));
$assert('商品金额区域优先显示总价说明', str_contains($html, '<span class="oid-sub">总价</span>'));
$assert('商品金额区域优先使用总价金额', str_contains($html, '<span class="price-val">￥1,000</span>'));
$assert('没有总价时显示请求金额说明', str_contains($requestOnlyHtml, '<span class="oid-sub">请求金额</span>'));
$assert('没有总价时使用请求金额数值', str_contains($requestOnlyHtml, '<span class="price-val">￥888</span>'));
$assert('采购信息表首列只显示采购人', str_contains($purchaseInfoHtml, '<th class="c0" colspan="2">采购人</th>'));
$assert('采购信息表不再显示采购状态采购人合并列', !str_contains($purchaseInfoHtml, '采购状态 / 采购人'));
$assert('采购信息表不再显示采购状态值', !str_contains($purchaseInfoHtml, '国内采购-准备'));
$assert('采购信息表不再显示补货链接', !str_contains($purchaseInfoHtml, '补货链接'));
$assert('采购信息表不再显示国内运费', !str_contains($purchaseInfoHtml, '国内运费'));
$assert('采购信息表采购时间放大一格', str_contains($purchaseInfoHtml, '<th class="c2" colspan="2">采购时间</th>'));
$assert('采购信息表1688订单号放大一格', str_contains($purchaseInfoHtml, '<th class="c10" colspan="2">1688订单号</th>'));
$assert('采购信息表物流公司不再显示状态表头', !str_contains($purchaseInfoHtml, '物流公司 / 状态') && str_contains($purchaseInfoHtml, '<th class="c12">物流公司</th>'));
$assert('采购信息表国内运单号改为签收地并继续收窄一格', !str_contains($purchaseInfoHtml, '国内运单号 / 物流轨迹') && str_contains($purchaseInfoHtml, '<th class="c13" colspan="2">国内运单号 / 签收地</th>'));
$assert('采购信息表显示签收地', str_contains($purchaseInfoHtml, 'CN123456 / 义乌'));
$assert('采购信息表不再显示物流状态和轨迹内容', !str_contains($purchaseInfoHtml, '运输中') && !str_contains($purchaseInfoHtml, '轨迹内容') && !str_contains($purchaseInfoHtml, '物流轨迹'));
$assert('编辑抽屉保持右侧编辑器结构', str_contains($html, 'class="editor-drawer"') && !str_contains($html, 'legacy-sidebar-editor') && str_contains($html, '<strong>编辑子商品</strong>') && str_contains($html, 'drawer-product'));
$assert('编辑抽屉没有货源地选项', !str_contains($html, 'name="source_type"') && !str_contains($html, '货源地</span>'));
$assert('编辑抽屉没有老系统基础订单块和SKU上传', !str_contains($html, 'OrderId：') && !str_contains($html, 'SKU产品图') && !str_contains($html, 'drawer-section-title'));
$assert('编辑抽屉签收地可选择并保留当前值', str_contains($html, '<select name="receipt_city"') && preg_match('/<option value="义乌"[^>]*selected[^>]*>义乌<\/option>/', $html) === 1);
preg_match('/data-status-options="([^"]+)"/', $html, $statusJsonMatch);
$statusOptionsJson = html_entity_decode($statusJsonMatch[1] ?? '', ENT_QUOTES, 'UTF-8');
$drawerStatusOptions = json_decode($statusOptionsJson, true) ?: [];
$assert('待定货源地采购状态不硬塞国内采购状态', in_array('日本仓待处理', $drawerStatusOptions['pending'] ?? [], true));
$assert('平台订单右侧栏采购状态实际包含日本仓状态', str_contains($html, '<option >日本仓待处理</option>'));

$css = (string) file_get_contents($basePath . '/public/assets/app.css');
$assert('编辑抽屉仍从右侧滑入', str_contains($css, 'right: 0;') && str_contains($css, 'translateX(104%)') && str_contains($css, 'width: min(520px, 92vw)'));

if ($failures !== []) {
    fwrite(STDERR, "Order list source readonly test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Order list source readonly test OK\n";
