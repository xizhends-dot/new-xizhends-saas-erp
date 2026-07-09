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

$_SESSION = [];
$_GET = ['sync_message' => '1', 'message' => '同步完成：新增 2 单，更新 1 单。'];
$_SERVER['REQUEST_URI'] = '/orders?tenant=erp&view=platform&sync_message=1&message=' . rawurlencode('同步完成：新增 2 单，更新 1 单。');

$tenantKey = 'erp';
$tenantNotices = [];
$orderView = 'platform';
$orders = [];
$platform = 'y';
$platformNames = ['y' => 'Yahoo'];
$source = 'all';
$keyword = '';
$filters = [];
$stores = [['id' => 1, 'platform' => 'y', 'name' => 'Yahoo一店', 'short' => 'Y-01']];
$statusOptions = ['未处理的订单', '国内采购-准备'];
$jpStockStatusOptions = ['日本仓待处理', '日本仓已完成'];
$filterFields = [];
$exportTools = [[
    'key' => 'sync_orders',
    'label' => '同步订单',
    'action' => '/orders/platform/sync',
    'method' => 'post',
    'group' => 'sync',
    'visibleWhen' => true,
]];
$canEditOrders = true;
$canEditPurchase = false;
$canEditJp = false;
$canChangeSource = true;
$canBatchOperate = true;
$canBatchPurchase = true;
$canBatchJp = false;
$canImportExport = false;
$canPlatformImportExport = false;
$canPurchaseImportExport = false;
$canFinanceExport = false;
$canFullImportExport = false;
$can1688Logistics = true;
$canExpressLogistics = true;
$canJpLogistics = true;
$platformSyncServices = ['y' => 'Yahoo'];
$deleteChallenge = ['question' => '8 + 5', 'answer' => 13];

ob_start();
require $basePath . '/app/Views/tenant/orders.php';
$html = (string) ob_get_clean();

$assert('订单批量工具栏存在', preg_match('/<div class="tbar order-toolbar">.*?<\/div>\s*<\/div>/s', $html, $matches) === 1);
$toolbar = $matches[0] ?? '';
$assert('订单页不再显示无效手动录入按钮', !str_contains($html, '手动录入'));
$assert('订单页不再显示无效手动出库按钮', !str_contains($html, '手动出库'));
$assert('Yahoo 同步表单提示指定 IP', str_contains($html, 'data-confirm="Yahoo 平台订单同步需要在指定 IP 环境下执行。确认当前网络符合要求后再继续同步？"'));
$assert('Yahoo 同步按钮可见', str_contains($html, '同步订单') && str_contains($html, 'name="platform" value="y"'));
$assert('同步结果写入页面弹窗数据', str_contains($html, 'data-sync-message="同步完成：新增 2 单，更新 1 单。"'));
$assert('订单图片下载表单已挂载', str_contains($html, 'id="product-image-download-form"') && str_contains($html, 'action="/orders/images/download"'));

$js = file_get_contents(__DIR__ . '/../public/assets/app.js') ?: '';
$assert('同步结果页面加载后使用老系统风格弹窗提示', str_contains($js, '.order-page[data-sync-message]') && str_contains($js, 'showSyncModal') && str_contains($js, 'sync-modal-log'));
$assert('同步弹窗后清理 URL 参数避免重复弹窗', str_contains($js, "url.searchParams.delete('sync_message')") && str_contains($js, "url.searchParams.delete('message')") && str_contains($js, 'window.history.replaceState'));
$css = file_get_contents(__DIR__ . '/../public/assets/app.css') ?: '';
$assert('同步弹窗改为当前绿色小尺寸样式', str_contains($css, '.sync-modal-title') && str_contains($css, '--brand-main: #006400;') && str_contains($css, '--brand-hover: #368C36;') && str_contains($css, 'background: var(--brand-main);') && str_contains($css, 'width: min(520px, 94vw);') && str_contains($css, 'min-height: 126px;'));
$assert('批量表单返回地址移除同步提示参数', str_contains($html, 'name="return" value="/orders?tenant=erp&amp;view=platform"') && !str_contains($html, 'name="return" value="/orders?tenant=erp&amp;view=platform&amp;sync_message=1'));

$expectedOrder = [
    '|',
    '展开详情',
    '更新1688物流',
    '更新TB/PDD物流',
    '更新国际物流',
    '货源地设置',
    '采购状态设置',
    '批量删除',
];
$lastPosition = -1;
foreach ($expectedOrder as $text) {
    $position = strpos($toolbar, $text);
    $assert("工具栏包含 {$text}", $position !== false);
    if ($position !== false) {
        $assert("工具栏顺序正确 {$text}", $position >= $lastPosition);
        $lastPosition = $position;
    }
}

$selectAllPosition = strpos($toolbar, 'class="select-all-check"');
$countPosition = strpos($toolbar, '<span class="tbar-count-num"><strong>0</strong>/<strong>0</strong>单</span>');
$separatorPosition = strpos($toolbar, '<span class="sep">|</span>');
$detailPosition = strpos($toolbar, '>展开详情</button>');
$assert('批量全选改为勾选框', $selectAllPosition !== false && str_contains($toolbar, 'data-toggle-selection="all"') && str_contains($toolbar, 'aria-label="全选订单"'));
$assert('选择计数不再显示已选择文字', !str_contains($toolbar, '已选择 <strong>') && $countPosition !== false);
$assert('选择栏顺序为勾选框 计数 分隔 展开详情', $selectAllPosition !== false && $countPosition !== false && $separatorPosition !== false && $detailPosition !== false && $selectAllPosition < $countPosition && $countPosition < $separatorPosition && $separatorPosition < $detailPosition);
$assert('批量栏不再显示状态适用货源地', !str_contains($toolbar, 'name="status_source"') && !str_contains($toolbar, '状态适用货源地'));
$assert('批量采购状态下拉不再依赖状态适用货源地', !str_contains($toolbar, 'data-batch-status-target') && !str_contains($toolbar, '请先选择状态适用货源地'));
$assert('批量栏不再显示采购人分配', !str_contains($toolbar, 'name="buyer"') && !str_contains($toolbar, 'assign_buyer') && !str_contains($toolbar, '>采购人<'));
$assert('批量货源地不再显示待定', !str_contains($toolbar, '<option value="pending">待定</option>'));
$assert('批量采购状态显示国内采购分组', str_contains($toolbar, '<option value="" disabled>---国内采购---</option>'));
$assert('批量采购状态显示日本仓分组', str_contains($toolbar, '<option value="" disabled>---日本仓---</option>'));
$assert('批量采购状态包含国内采购状态', str_contains($toolbar, '>国内采购-准备</option>'));
$assert('批量采购状态包含日本仓状态', str_contains($toolbar, '>日本仓已完成</option>'));
$assert('货源地设置使用设置按钮色调类', str_contains($toolbar, 'class="btn-xs batch-setting-btn"') && str_contains($toolbar, '>货源地设置</button>'));
$assert('采购状态设置使用设置按钮色调类', substr_count($toolbar, 'class="btn-xs batch-setting-btn"') >= 2 && str_contains($toolbar, '>采购状态设置</button>'));

$assert('批量删除按钮使用红底白字', str_contains($css, '.order-page .order-toolbar .danger-text') && str_contains($css, 'background: #991b1b;') && str_contains($css, 'color: #fff;'));
$assert('批量删除按钮带强验证题', str_contains($toolbar, 'data-batch-delete-button') && str_contains($toolbar, 'data-delete-challenge="8 + 5"') && str_contains($html, 'name="delete_challenge_answer"'));
$assert('批量删除前端会校验验证题', str_contains($js, 'confirmBatchDelete') && str_contains($js, 'delete_challenge_answer') && str_contains($js, '验证答案错误'));

if ($failures !== []) {
    fwrite(STDERR, "Order toolbar actions test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Order toolbar actions test OK\n";
