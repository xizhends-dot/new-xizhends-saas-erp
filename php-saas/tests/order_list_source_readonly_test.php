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
$stores = [['id' => 10, 'platform' => 'y', 'name' => '测试店铺', 'short' => '测店']];
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
$canUploadImage = true;
$canDeleteImage = true;
$can1688Logistics = true;
$receiptCityOptions = ['义乌', '深圳威通'];
$order = [
    'id' => 100,
    'store_id' => 10,
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
        'main_image' => 'storage/tenants/erp/images/orders/100/200/main.jpg',
        'sku_image' => '/storage/tenants/erp/images/orders/100/200/sku.png',
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

$skuOnlyOrder = $order;
$skuOnlyOrder['items'][0]['image'] = '/assets/no-image.svg';
$skuOnlyOrder['items'][0]['main_image'] = '';
$skuOnlyOrder['items'][0]['sku_image'] = 'storage/tenants/erp/images/orders/100/200/sku-only.png';
$order = $skuOnlyOrder;
ob_start();
require $basePath . '/app/Views/tenant/partials/order_block.php';
$skuOnlyHtml = (string) ob_get_clean();

$assert('列表展示国内采购货源地标签', str_contains($html, '<span class="src-tag cn">国内采购</span>'));
$assert('订单图片通过系统入口展示', str_contains($html, 'class="order-img" src="/orders/item-image?tenant=erp&amp;order_id=100&amp;item_id=200"'));
$assert('订单图片链接使用系统入口', str_contains($html, 'class="order-image-link" href="/orders/item-image?tenant=erp&amp;order_id=100&amp;item_id=200"'));
$assert('缺图时不使用商品标题作为图片替代文本', str_contains($html, '<img class="order-img" src="/orders/item-image?tenant=erp&amp;order_id=100&amp;item_id=200" alt=""'));
$assert('订单列表没有主图时仍走系统图片入口兜底', str_contains($skuOnlyHtml, 'class="order-img" src="/orders/item-image?tenant=erp&amp;order_id=100&amp;item_id=200"'));
$assert('列表不出现单项货源地修改表单', !str_contains($html, 'action="/orders/source"'));
$assert('列表不出现单项 source 下拉', !str_contains($html, 'name="source" aria-label="货源地"'));
$assert('列表编辑抽屉不提交 source_type', !str_contains($html, 'name="source_type"'));
$assert('平台订单采购信息默认收起', str_contains($html, 'purchase-info-table table-hidden'));
$assert('平台订单国际物流默认收起', str_contains($html, 'otable sec-c table-hidden'));
$assert('订单栏合并客人姓名和片假名表头', str_contains($html, '<th class="c3">客人姓名/片假名</th>'));
$assert('订单栏不再单独显示收件人表头', !str_contains($html, '<th class="c3">收件人</th>'));
$assert('订单栏不再单独显示假名表头', !str_contains($html, '<th class="c4">假名</th>'));
$assert('订单栏地址列缩小并让出宽度给邮箱', str_contains($html, '<th class="c4" colspan="2">地址</th>') && str_contains($html, '<th class="c8" colspan="3">邮箱</th>'));
$assert('订单栏不再显示付款状态表头', !str_contains($html, '<th class="c12">付款状态</th>'));
$assert('订单栏不再显示付款日期表头', !str_contains($html, '<th class="c13">付款日期</th>'));
$assert('商品表头按审查顺序显示订单明细和店铺', str_contains($html, '订单ID / 明细ID') && str_contains($html, '订单时间 / 店铺') && !str_contains($html, '订单ID / 店铺') && !str_contains($html, '订单时间 / 明细ID'));
$assert('店铺展示为缩写和全称', str_contains($html, '测店 / 测试店铺') && !str_contains($html, '店铺番号'));

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
$assert('编辑抽屉保持右侧编辑器结构', str_contains($html, 'class="editor-drawer"') && !str_contains($html, 'legacy-sidebar-editor') && str_contains($html, '<strong>编辑订单</strong>'));
$assert('编辑抽屉没有货源地选项', !str_contains($html, 'name="source_type"') && !str_contains($html, '货源地</span>'));
$assert('编辑抽屉内容对齐老系统侧栏字段', str_contains($html, '<summary class="drawer-section-title">编辑订单</summary>') && str_contains($html, '<div class="drawer-section-title">运单信息</div>') && str_contains($html, 'OrderId：') && str_contains($html, 'SKU产品图') && str_contains($html, '物流签收地：') && str_contains($html, '中文属性备注：'));
$assert('编辑抽屉支持上传订单产品图和SKU产品图', str_contains($html, 'action="/orders/images/upload"') && str_contains($html, 'name="kind" value="main"') && str_contains($html, 'name="kind" value="sku"') && str_contains($html, 'type="file" name="image"') && str_contains($html, 'name="base64_image"') && str_contains($html, 'form="drawer-image-main-200"') && str_contains($html, 'form="drawer-image-sku-200"'));
$assert('编辑抽屉采购人只读展示不再手动输入', str_contains($html, 'data-field-display="buyer"') && !str_contains($html, 'name="buyer" value='));
$assert('编辑抽屉图片粘贴框对齐老系统', str_contains($html, 'data-image-paste-input') && str_contains($html, 'data-image-paste-area') && str_contains($html, 'data-image-base64') && str_contains($html, 'placeholder="可将图片粘贴到此"') && !str_contains($html, '粘贴 base64 图片数据'));
$assert('编辑抽屉图片操作对齐老系统按钮', str_contains($html, '【选择图片】') && str_contains($html, '>提交保存</button>') && str_contains($html, '>削除</button>') && str_contains($html, 'action="/orders/images/delete"') && str_contains($html, 'form="drawer-image-delete-sku-200"'));
$assert('编辑抽屉图片削除不使用内联跳转确认', str_contains($html, 'data-image-delete-button') && !str_contains($html, 'onclick="return confirm'));
$assert('编辑订单基础信息默认收起', str_contains($html, '<details class="drawer-section drawer-collapsible">') && str_contains($html, '<summary class="drawer-section-title">编辑订单</summary>'));
$assert('编辑抽屉不使用最初简化商品卡片', !str_contains($html, 'drawer-product'));
$assert('编辑抽屉签收地可选择并保留当前值', str_contains($html, '<select name="receipt_city"') && preg_match('/<option value="义乌"[^>]*selected[^>]*>义乌<\/option>/', $html) === 1);
$assert('编辑抽屉1688订单号支持单条刷新', str_contains($html, 'data-1688-refresh-input') && str_contains($html, 'data-1688-refresh-button') && str_contains($html, 'data-refresh-url="/orders/1688/refresh"') && str_contains($html, 'data-1688-refresh-status'));
preg_match('/data-status-options="([^"]+)"/', $html, $statusJsonMatch);
$statusOptionsJson = html_entity_decode($statusJsonMatch[1] ?? '', ENT_QUOTES, 'UTF-8');
$drawerStatusOptions = json_decode($statusOptionsJson, true) ?: [];
$assert('待定货源地采购状态不硬塞国内采购状态', in_array('日本仓待处理', $drawerStatusOptions['pending'] ?? [], true));
$assert('平台订单右侧栏采购状态实际包含日本仓状态', str_contains($html, '<option >日本仓待处理</option>'));

$css = (string) file_get_contents($basePath . '/public/assets/app.css');
$assert('编辑抽屉仍从右侧滑入', str_contains($css, 'right: 0;') && str_contains($css, 'translateX(104%)') && str_contains($css, 'width: min(520px, 92vw)'));
$js = (string) file_get_contents($basePath . '/public/assets/app.js');
$assert('编辑抽屉支持直接粘贴图片预览并写入隐藏字段', str_contains($js, "document.addEventListener('paste'") && str_contains($js, 'imageFileFromClipboard') && str_contains($js, 'readAsDataURL') && str_contains($js, 'data-image-base64') && str_contains($js, '已粘贴图片，点击提交保存'));
$assert('编辑抽屉图片保存削除使用异步提交并保持抽屉', str_contains($js, 'submitDrawerImageForm') && str_contains($js, "fetch(action") && str_contains($js, 'clearDrawerImagePreview') && str_contains($js, 'ensureDrawerImageDeleteButton') && str_contains($js, 'X-Requested-With'));
$assert('编辑抽屉1688刷新异步回填字段并保持抽屉', str_contains($js, 'refreshDrawer1688') && str_contains($js, 'apply1688RefreshFields') && str_contains($js, "fetch(url") && str_contains($js, 'data-field-display'));
$assert('Rakuten项目选择支持显示更多展开', str_contains($js, 'data-choice-toggle') && str_contains($css, '.rakuten-choice-cell .choice-toggle'));

$routes = (string) file_get_contents($basePath . '/app/Http/routes.php');
$assert('1688单条刷新路由已注册', str_contains($routes, "post('/orders/1688/refresh'") && str_contains($routes, 'refresh1688Order'));
$assert('订单图片查询入口已注册以避开远程图片直连', str_contains($routes, "get('/orders/item-image'") && str_contains($routes, 'serveItemImage'));
$assert('订单图片下载路由已注册', str_contains($routes, "post('/orders/images/download'") && str_contains($routes, 'downloadProductImages'));
$assert('缺图占位资源存在', is_file($basePath . '/public/assets/no-image.svg'));

if ($failures !== []) {
    fwrite(STDERR, "Order list source readonly test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Order list source readonly test OK\n";
