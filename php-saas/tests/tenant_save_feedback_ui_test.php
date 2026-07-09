<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $basePath);
}
require_once $basePath . '/app/Core/helpers.php';

$failures = [];
$assert = static function (string $label, bool $ok) use (&$failures): void {
    if (!$ok) {
        $failures[] = $label;
    }
};

$tenantKey = 'erp';
$tenant = ['company_name' => '测试公司'];
$platformNames = ['r' => 'Rakuten'];
$storeApiFields = [];
$storeApiValues = [];
$store = [
    'id' => 12,
    'platform' => 'r',
    'legacy_dpid' => 'dp01',
    'short' => 'R-01',
    'name' => 'Rakuten旗舰店',
    'profit_deduction' => 70,
    'status' => 'visible',
    'api_status' => '未配置',
    'api_config' => '',
    'hidden_reason' => '',
];
$returnUrl = '/stores?tenant=erp';

ob_start();
require $basePath . '/app/Views/tenant/store_edit.php';
$storeEditHtml = (string) ob_get_clean();

$assert('店铺编辑保存后回到当前编辑页', str_contains($storeEditHtml, 'name="return"') && str_contains($storeEditHtml, '/stores/edit?tenant=erp&amp;id=12'));

$rolePermissions = [
    '客服' => ['订单查看'],
    '采购' => ['采购状态'],
];
$user = [
    'id' => 7,
    'name' => '张三',
    'username' => 'zhangsan',
    'role' => '客服',
    'permissions' => ['订单查看'],
    'stores' => ['全部店铺'],
    'status' => 'active',
    'preference_module' => '',
];
$stores = [['name' => 'Rakuten旗舰店']];
$returnUrl = '/users?tenant=erp';

ob_start();
require $basePath . '/app/Views/tenant/user_edit.php';
$userEditHtml = (string) ob_get_clean();

$assert('员工编辑保存后回到当前编辑页', str_contains($userEditHtml, 'name="return"') && str_contains($userEditHtml, '/users/edit?tenant=erp&amp;id=7'));

$appJs = file_get_contents($basePath . '/public/assets/app.js') ?: '';
$assert('租户后台有全局保存成功失败弹窗', str_contains($appJs, 'initTenantFlashFromUrl') && str_contains($appJs, "'保存失败：'"));
$assert('租户后台弹窗后清理 URL 参数', str_contains($appJs, "url.searchParams.delete('message')") && str_contains($appJs, "url.searchParams.delete('error')") && str_contains($appJs, "url.searchParams.delete('saved')"));

if ($failures !== []) {
    fwrite(STDERR, "Tenant save feedback UI test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Tenant save feedback UI test OK\n";
