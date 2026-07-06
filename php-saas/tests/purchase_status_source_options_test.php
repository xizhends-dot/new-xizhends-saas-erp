<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $basePath);
}
require $basePath . '/vendor/autoload.php';
require $basePath . '/app/Core/helpers.php';

use Xizhen\Services\PurchaseStatusService;
use Xizhen\Services\OrderFilterService;
use Xizhen\Services\OrderPageConfigRegistry;
use Xizhen\Core\JsonStore;

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

$customStatuses = ['未处理的订单', '国内采购-准备', 'AAA'];
$jpStatuses = [
    '日本仓待处理',
    '日本仓补货中',
    '日本仓已完成',
];

$assertSame('日本仓默认四项', 4, count(PurchaseStatusService::defaultJpStockStatuses()));
$assertSame('日本仓按配置状态返回', $jpStatuses, PurchaseStatusService::statusOptionsForSource('jp_stock', $customStatuses, $jpStatuses));
$assertSame('国内采购返回租户自定义清单', $customStatuses, PurchaseStatusService::statusOptionsForSource('cn_purchase', $customStatuses, $jpStatuses));
$assertSame('待定返回租户自定义清单', $customStatuses, PurchaseStatusService::statusOptionsForSource('pending', $customStatuses, $jpStatuses));

$_SESSION = [];
$_SERVER['REQUEST_URI'] = '/orders?tenant=erp&view=jp';

$tenantKey = 'erp';
$tenantNotices = [];
$orderView = 'jp';
$orders = [];
$platform = null;
$platformNames = [];
$source = 'all';
$keyword = '';
$filters = [];
$stores = [
    ['id' => 1, 'platform' => 'y', 'name' => 'Yahoo一店', 'short' => 'Y-01'],
    ['id' => 2, 'platform' => 'r', 'name' => '乐天一店', 'short' => 'R-01'],
];
$statusOptions = $customStatuses;
$jpStockStatusOptions = $jpStatuses;
$filterFields = [
    ['key' => 'status', 'label' => '采购状态', 'type' => 'select', 'optionsKey' => 'statusOptions', 'section' => 'basic', 'views' => ['jp']],
];
$exportTools = [];
$canEditOrders = false;
$canEditPurchase = false;
$canEditJp = false;
$canChangeSource = false;
$canBatchOperate = false;
$canBatchPurchase = false;
$canBatchJp = true;
$canImportExport = false;
$canPlatformImportExport = false;
$canPurchaseImportExport = false;
$canFinanceExport = false;
$canFullImportExport = false;
$can1688Logistics = false;
$canExpressLogistics = false;
$canJpLogistics = false;
$platformSyncServices = [];

ob_start();
require $basePath . '/app/Views/tenant/orders.php';
$html = (string) ob_get_clean();

$assert('JP 批量采购状态下拉存在', preg_match('/<select class="assign-sel" name="purchase_status"[^>]*>(.*?)<\/select>/s', $html, $matches) === 1);
$batchSelect = $matches[1] ?? '';
foreach ($jpStatuses as $status) {
    $assert("JP 批量下拉包含 {$status}", str_contains($batchSelect, '>' . e($status) . '<'));
}
$assert('JP 批量下拉不包含自定义状态 AAA', !str_contains($batchSelect, '>AAA<'));
$assert('JP 批量下拉不包含默认固定状态', !str_contains($batchSelect, '日本库存订单'));
$assertSame('JP 批量下拉使用配置状态数量', 3, preg_match_all('/<option>/', $batchSelect));
$assert('JP 搜索区采购状态下拉存在', preg_match('/<select name="status"[^>]*>(.*?)<\/select>/s', $html, $statusMatches) === 1);
$statusSelect = $statusMatches[1] ?? '';
foreach ($jpStatuses as $status) {
    $assert("JP 搜索状态包含 {$status}", str_contains($statusSelect, 'value="' . e($status) . '"'));
}
$assert('JP 搜索状态不包含出库待分配', !str_contains($statusSelect, 'value="待分配"'));
$assert('JP 搜索状态标题不是出库状态', !str_contains($html, '>出库状态<'));

$filterJsonPath = sys_get_temp_dir() . '/xizhen-purchase-status-filter-' . bin2hex(random_bytes(6)) . '.json';
file_put_contents($filterJsonPath, json_encode([
    'admins' => [],
    'platforms' => [],
    'tenants' => [],
    'announcements' => [],
    'orders' => [],
    'stores' => [],
    'users' => [],
    'assignments' => [],
    'attachments' => [],
    'settings' => ['global' => [], 'tenant' => []],
    'import_export_logs' => [],
    'purchase_status_events' => [],
    'billing' => ['ledger' => [], 'subscriptions' => []],
    'mail' => ['accounts' => [], 'folders' => [], 'messages' => [], 'replies' => [], 'rules' => [], 'settings' => []],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$filterService = new OrderFilterService(new JsonStore($filterJsonPath));
$ordersForFilter = [[
    'id' => 1,
    'platform_order_id' => 'P-1',
    'order_date' => '2026-07-05',
    'imported_at' => '2026-07-05',
    'items' => [[
        'id' => 11,
        'source_type' => 'jp_stock',
        'purchase_status' => '日本仓补货中',
        'out_status' => '待分配',
    ]],
]];
$matchedByPurchaseStatus = $filterService->filterOrdersForView($ordersForFilter, 'jp', null, 'all', null, ['status' => '日本仓补货中']);
$matchedByOutStatus = $filterService->filterOrdersForView($ordersForFilter, 'jp', null, 'all', null, ['status' => '待分配']);
$assertSame('JP status 筛选命中 purchase_status', 1, count($matchedByPurchaseStatus[0]['items'] ?? []));
$assertSame('JP status 筛选不再命中 out_status', 0, count($matchedByOutStatus));
@unlink($filterJsonPath);

$orderView = 'platform';
$platform = 'y';
$source = 'all';
$filters = [];
$receiptCityOptions = ['义乌', '广州新势力', '深圳威通'];
$canBatchOperate = true;
$canBatchPurchase = true;
$canChangeSource = true;
$filterFields = (new OrderPageConfigRegistry())->filterFieldsFor($platform);
$stores = array_values(array_filter($stores, static fn (array $store): bool => (string) ($store['platform'] ?? '') === 'y'));

ob_start();
require $basePath . '/app/Views/tenant/orders.php';
$platformHtml = (string) ob_get_clean();

$assert('平台页全部货源地时状态筛选可用', str_contains($platformHtml, 'data-order-status-filter') && !str_contains($platformHtml, 'disabled title="请先选择货源地"'));
$assert('平台页全部货源地时默认状态为待处理订单', str_contains($platformHtml, '>— 待处理订单 —<'));
$assert('平台页全部货源地时状态筛选包含全部订单', str_contains($platformHtml, '<option value="__ALL__" >全部订单</option>') || str_contains($platformHtml, '<option value="__ALL__">全部订单</option>'));
foreach (array_merge($customStatuses, $jpStatuses) as $status) {
    $assert("平台页全部货源地状态包含 {$status}", str_contains($platformHtml, 'value="' . e($status) . '"'));
}
$cnGroupPos = strpos($platformHtml, '---国内采购---');
$jpGroupPos = strpos($platformHtml, '---日本仓---');
$assert('平台页全部货源地状态包含国内采购分组', $cnGroupPos !== false);
$assert('平台页全部货源地状态包含日本仓分组', $jpGroupPos !== false);
$assert('平台页全部货源地状态分组顺序正确', $cnGroupPos !== false && $jpGroupPos !== false && $cnGroupPos < $jpGroupPos);
$assert('平台页状态分组标题不可选择', str_contains($platformHtml, '<option value="" disabled>---国内采购---</option>') && str_contains($platformHtml, '<option value="" disabled>---日本仓---</option>'));
$assert('平台页采购状态不再高亮为整行', !str_contains($platformHtml, 'order-status-filter-row'));
$assert('平台页筛选货源地不显示待定', !str_contains($platformHtml, '<option value="pending">待定</option>'));
$cnSourcePos = strpos($platformHtml, '<option value="cn_purchase">国内采购</option>');
$jpSourcePos = strpos($platformHtml, '<option value="jp_stock">日本仓</option>');
$assert('平台页货源地顺序为国内采购优先于日本仓', $cnSourcePos !== false && $jpSourcePos !== false && $cnSourcePos < $jpSourcePos);
$searchGridStart = strpos($platformHtml, '<div class="order-search-grid">');
$searchGridEnd = $searchGridStart === false ? false : strpos($platformHtml, '</div>', $searchGridStart);
$searchGridHtml = ($searchGridStart !== false && $searchGridEnd !== false) ? substr($platformHtml, $searchGridStart, $searchGridEnd - $searchGridStart) : '';
$firstRowKeys = [
    strpos($searchGridHtml, 'order-search-field-order_no'),
    strpos($searchGridHtml, 'order-search-field-tabaono'),
    strpos($searchGridHtml, 'order-search-field-item_id'),
];
$secondRowKeys = [
    strpos($searchGridHtml, 'order-search-field-cn_ship_no'),
    strpos($searchGridHtml, 'order-search-field-intl_ship_no'),
    strpos($searchGridHtml, 'order-search-field-receipt_city'),
];
$thirdRowKeys = [
    strpos($searchGridHtml, 'order-search-field-source'),
    strpos($searchGridHtml, 'order-search-field-status'),
    strpos($searchGridHtml, 'order-search-field-store'),
];
$assert('平台页搜索区第一行顺序为订单号/1688订单号/ItemId查询', !in_array(false, $firstRowKeys, true) && $firstRowKeys === array_values($firstRowKeys) && $firstRowKeys[0] < $firstRowKeys[1] && $firstRowKeys[1] < $firstRowKeys[2]);
$assert('平台页搜索区第二行顺序为国内发货/国际发货/国内签收地', !in_array(false, $secondRowKeys, true) && $secondRowKeys[0] < $secondRowKeys[1] && $secondRowKeys[1] < $secondRowKeys[2]);
$assert('平台页搜索区第三行顺序为货源地/采购状态/店铺', !in_array(false, $thirdRowKeys, true) && $thirdRowKeys[0] < $thirdRowKeys[1] && $thirdRowKeys[1] < $thirdRowKeys[2]);
$assert('当前平台店铺下拉只显示本平台店铺', preg_match('/<option value="Yahoo一店"[^>]*>Yahoo一店<\/option>/', $platformHtml) === 1 && preg_match('/<option value="乐天一店"[^>]*>乐天一店<\/option>/', $platformHtml) === 0);
$assert('国内签收地用设置下拉选项', str_contains($platformHtml, '<select name="receipt_city"') && preg_match('/<option value="义乌"[^>]*>义乌<\/option>/', $platformHtml) === 1 && preg_match('/<option value="深圳威通"[^>]*>深圳威通<\/option>/', $platformHtml) === 1);
$assert('每页显示位于结果数量旁边', str_contains($platformHtml, 'order-search-summary') && str_contains($platformHtml, 'order-page-size-field'));
$assert('底部分页不再显示静态每页下拉', !str_contains($platformHtml, '<span>每页</span>'));
$assert('日期范围用单行起止日期控件', preg_match('/order-date-range-field[^>]*>.*?<span class="lb">日期范围<\/span>.*?<span class="order-date-range-control">.*?<input class="order-date-input" type="date"[^>]+name="OrderTime"[^>]*>.*?<span class="order-date-range-sep">至<\/span>.*?<input class="order-date-input" type="date"[^>]+name="OrderTime2"[^>]*>/s', $platformHtml) === 1);
$assert('日期范围字段位于更多筛选同一网格第一列', !str_contains($platformHtml, 'order-search-grid order-date-range-row') && str_contains($platformHtml, 'order-search-field-date_range order-date-range-field') && !preg_match('/order-search-field-date_range[^"]*\\bcol[23]\\b/', $platformHtml));
$css = file_get_contents($basePath . '/public/assets/app.css') ?: '';
$assert('日期范围不单独覆盖标签列宽', !preg_match('/\\.order-filter-modern\\s+\\.order-date-range-field\\s*\\{[^}]*grid-template-columns/s', $css));
$assert('平台页批量栏不再显示状态适用货源地', !str_contains($platformHtml, 'name="status_source"') && !str_contains($platformHtml, '状态适用货源地'));
$assert('平台页批量状态下拉直接可用', !str_contains($platformHtml, 'data-batch-status-target') && !str_contains($platformHtml, '请先选择状态适用货源地'));
$assert('平台页批量栏不再显示采购人分配', !str_contains($platformHtml, 'name="buyer"') && !str_contains($platformHtml, 'assign_buyer') && !str_contains($platformHtml, '>采购人<'));
$assert('平台页批量栏显示新按钮文案', str_contains($platformHtml, '货源地设置') && str_contains($platformHtml, '采购状态设置') && str_contains($platformHtml, '批量删除'));
$assert('平台页批量货源地不显示待定', !str_contains($platformHtml, '<option value="pending">待定</option>'));

$source = 'cn_purchase';
ob_start();
require $basePath . '/app/Views/tenant/orders.php';
$platformSourceHtml = (string) ob_get_clean();
$assert('平台页选择货源地后默认状态仍为待处理订单', str_contains($platformSourceHtml, '>— 待处理订单 —<'));
$assert('平台页选择货源地后状态筛选仍包含全部订单', str_contains($platformSourceHtml, '<option value="__ALL__" >全部订单</option>') || str_contains($platformSourceHtml, '<option value="__ALL__">全部订单</option>'));

if ($failures !== []) {
    fwrite(STDERR, "Purchase status source options test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Purchase status source options test OK\n";
