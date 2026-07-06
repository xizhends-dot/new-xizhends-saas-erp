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
$_SERVER['REQUEST_URI'] = '/orders?tenant=erp&view=platform';

$tenantKey = 'erp';
$tenantNotices = [];
$orderView = 'platform';
$orders = [];
$platform = 'y';
$platformNames = ['y' => 'Yahoo'];
$source = 'all';
$keyword = '';
$filters = [];
$stores = [];
$statusOptions = ['未处理的订单', '国内采购-准备'];
$jpStockStatusOptions = ['日本仓待处理', '日本仓已完成'];
$filterFields = [];
$exportTools = [];
$canEditOrders = false;
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
$platformSyncServices = [];

ob_start();
require $basePath . '/app/Views/tenant/orders.php';
$html = (string) ob_get_clean();

$assert('订单批量工具栏存在', preg_match('/<div class="tbar order-toolbar">.*?<\/div>\s*<\/div>/s', $html, $matches) === 1);
$toolbar = $matches[0] ?? '';

$expectedOrder = [
    '已选择',
    '全选',
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

$assert('已选择计数紧凑显示', str_contains($toolbar, '已选择 <strong>0</strong>/<strong>0</strong>单'));
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

$css = file_get_contents(__DIR__ . '/../public/assets/app.css') ?: '';
$assert('批量删除按钮使用红底白字', str_contains($css, '.order-page .order-toolbar .danger-text') && str_contains($css, 'background: #991b1b;') && str_contains($css, 'color: #fff;'));

if ($failures !== []) {
    fwrite(STDERR, "Order toolbar actions test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Order toolbar actions test OK\n";
