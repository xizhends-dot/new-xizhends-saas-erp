<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $basePath);
}
require $basePath . '/vendor/autoload.php';
require $basePath . '/app/Core/helpers.php';

use Xizhen\Services\PurchaseStatusService;

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
    '日本库存订单',
    '库存缺货订单',
    '日本仓库已发出荷通知',
    '日本仓库已处理',
];

$assertSame('日本仓固定四状态常量', $jpStatuses, PurchaseStatusService::JP_STOCK_STATUSES);
$assertSame('日本仓按固定状态返回', $jpStatuses, PurchaseStatusService::statusOptionsForSource('jp_stock', $customStatuses));
$assertSame('国内采购返回租户自定义清单', $customStatuses, PurchaseStatusService::statusOptionsForSource('cn_purchase', $customStatuses));
$assertSame('待定返回租户自定义清单', $customStatuses, PurchaseStatusService::statusOptionsForSource('pending', $customStatuses));
$assertSame('日本仓固定四状态不受自定义清单影响', $jpStatuses, PurchaseStatusService::statusOptionsForSource('jp_stock', ['AAA', 'BBB']));

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
$stores = [];
$statusOptions = $customStatuses;
$jpStockStatusOptions = PurchaseStatusService::JP_STOCK_STATUSES;
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
$assertSame('JP 批量下拉固定四项数量', 4, preg_match_all('/<option>/', $batchSelect));

if ($failures !== []) {
    fwrite(STDERR, "Purchase status source options test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Purchase status source options test OK\n";
