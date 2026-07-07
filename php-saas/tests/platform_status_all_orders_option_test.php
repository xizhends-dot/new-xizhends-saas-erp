<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $basePath);
}
require $basePath . '/vendor/autoload.php';
require $basePath . '/app/Core/helpers.php';

use Xizhen\Services\OrderPageConfigRegistry;

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
$keyword = '';
$filters = [];
$stores = [['id' => 1, 'platform' => 'y', 'name' => 'Yahoo一店', 'short' => 'Y-01']];
$statusOptions = ['未处理的订单', '国内采购-准备'];
$jpStockStatusOptions = ['日本仓待处理'];
$filterFields = (new OrderPageConfigRegistry())->filterFieldsFor($platform);
$exportTools = [];
$canEditOrders = false;
$canEditPurchase = false;
$canEditJp = false;
$canChangeSource = false;
$canBatchOperate = false;
$canBatchPurchase = false;
$canBatchJp = false;
$canImportExport = false;
$canPlatformImportExport = false;
$canPurchaseImportExport = false;
$canFinanceExport = false;
$canFullImportExport = false;
$can1688Logistics = false;
$canExpressLogistics = false;
$canJpLogistics = false;
$platformSyncServices = [];
$receiptCityOptions = [];

/**
 * @param array<string, mixed> $vars
 */
function renderPlatformOrdersForStatusOptionTest(string $basePath, array $vars, string $sourceValue): string
{
    extract($vars, EXTR_SKIP);
    $source = $sourceValue;
    ob_start();
    require $basePath . '/app/Views/tenant/orders.php';
    return (string) ob_get_clean();
}

$viewVars = compact(
    'tenantKey',
    'tenantNotices',
    'orderView',
    'orders',
    'platform',
    'platformNames',
    'keyword',
    'filters',
    'stores',
    'statusOptions',
    'jpStockStatusOptions',
    'filterFields',
    'exportTools',
    'canEditOrders',
    'canEditPurchase',
    'canEditJp',
    'canChangeSource',
    'canBatchOperate',
    'canBatchPurchase',
    'canBatchJp',
    'canImportExport',
    'canPlatformImportExport',
    'canPurchaseImportExport',
    'canFinanceExport',
    'canFullImportExport',
    'can1688Logistics',
    'canExpressLogistics',
    'canJpLogistics',
    'platformSyncServices',
    'receiptCityOptions'
);

foreach (['all', 'cn_purchase'] as $sourceValue) {
    $html = renderPlatformOrdersForStatusOptionTest($basePath, $viewVars, $sourceValue);
    $assert("平台页 {$sourceValue} 默认状态是待处理订单", str_contains($html, '>— 待处理订单 —<'));
    $assert("平台页 {$sourceValue} 包含全部订单选项", (bool) preg_match('/<option value="__ALL__"[^>]*>全部订单<\/option>/', $html));
}

if ($failures !== []) {
    fwrite(STDERR, "Platform status all orders option test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Platform status all orders option test OK\n";
