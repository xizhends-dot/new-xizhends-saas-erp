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
$wowmaSyncFolders = ['XIZHENDS', 'Ready_buy', 'Custom_folder'];
$settings = [
    'company' => [],
    'orders' => [
        'default_page_size' => 200,
        'default_query_days' => 30,
        'platform_sync_default_days' => 7,
        'archive_days' => 180,
        'price_warning_index' => 0,
        'wowma_sync_folders' => $wowmaSyncFolders,
    ],
    'profit' => [],
    'logistics' => [],
    'api_1688' => [],
];
$platformNames = ['w' => 'Wowma'];
$purchaseStatuses = ['未处理的订单'];
$jpStockPurchaseStatuses = ['日本仓待处理'];
$systemPurchaseStatuses = [];
$saved = '';
$error = '';

ob_start();
require $basePath . '/app/Views/tenant/settings.php';
$settingsHtml = (string) ob_get_clean();

$assert('系统设置显示 Wowma 文件夹配置项', str_contains($settingsHtml, 'Wowma 文件夹名称') && str_contains($settingsHtml, 'name="wowma_sync_folders"'));
$assert('系统设置保留 Wowma 文件夹名单', str_contains($settingsHtml, 'XIZHENDS') && str_contains($settingsHtml, 'Ready_buy') && str_contains($settingsHtml, 'Custom_folder'));
$assert('系统设置保存会提交当前页签', str_contains($settingsHtml, 'name="active_tab"') && str_contains($settingsHtml, 'data-settings-active-tab'));

$appJs = file_get_contents($basePath . '/public/assets/app.js') ?: '';
$assert('系统设置保存前同步当前页签', str_contains($appJs, 'syncSettingsActiveTabFields') && str_contains($appJs, 'currentSettingsTab'));
$assert('租户保存后由全局弹窗提示并清理参数', str_contains($appJs, 'initTenantFlashFromUrl') && str_contains($appJs, "url.searchParams.delete('message')") && str_contains($appJs, "url.searchParams.delete('error')") && str_contains($appJs, "url.searchParams.delete('saved')"));
$assert('租户保存失败弹窗带失败提示', str_contains($appJs, "alert(error !== '' ? '保存失败：' + text : text)"));
$assert('同步订单弹窗不被普通保存提示重复处理', str_contains($appJs, "url.searchParams.has('sync_message')"));
$assert('租户侧边栏滚动位置会按租户恢复', str_contains($appJs, 'initTenantSidebarScroll') && str_contains($appJs, "tenant_sidebar_scroll:' + (tenant || 'default')"));

$_SESSION = [];
$_GET = [];
$_SERVER['REQUEST_URI'] = '/orders?tenant=erp&view=platform&platform=w';

$tenantNotices = [];
$orderView = 'platform';
$orders = [];
$platform = 'w';
$source = 'all';
$keyword = '';
$filters = [];
$stores = [
    ['id' => 11, 'platform' => 'w', 'name' => 'Wowma精品店', 'short' => 'W-01'],
];
$statusOptions = ['未处理的订单'];
$jpStockStatusOptions = ['日本仓待处理'];
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
$can1688Logistics = false;
$canExpressLogistics = false;
$canJpLogistics = false;
$platformSyncServices = ['w' => 'Wowma'];
$deleteChallenge = ['question' => '1 + 1', 'answer' => 2];

ob_start();
require $basePath . '/app/Views/tenant/orders.php';
$ordersHtml = (string) ob_get_clean();

$assert('Wowma 同步表单包含店铺选择', str_contains($ordersHtml, 'name="store_id"') && str_contains($ordersHtml, 'Wowma精品店'));
$assert('Wowma 同步表单包含文件夹下拉', str_contains($ordersHtml, 'name="order_status"') && str_contains($ordersHtml, 'aria-label="Wowma 文件夹名称"'));
$assert('Wowma 同步表单列出配置文件夹', str_contains($ordersHtml, 'value="XIZHENDS"') && str_contains($ordersHtml, 'value="Ready_buy"') && str_contains($ordersHtml, 'value="Custom_folder"'));

if ($failures !== []) {
    fwrite(STDERR, "Wowma sync folder UI test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Wowma sync folder UI test OK\n";
