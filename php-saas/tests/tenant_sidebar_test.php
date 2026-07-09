<?php
declare(strict_types=1);

$basePath = dirname(__DIR__);
define('BASE_PATH', $basePath);
require_once $basePath . '/app/Core/helpers.php';
require_once $basePath . '/app/Core/Permission.php';

$failures = [];
$assert = static function (string $message, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$tenant = ['key' => 'erp', 'short_name' => 'ERP', 'company_name' => '测试公司', 'plan' => 'Basic'];
$tenantKey = 'erp';
$tenantFeatures = [];
$currentUser = [
    'username' => 'admin-erp',
    'name' => '管理员',
    'role' => '公司管理员',
    'is_company_admin' => true,
    'permissions' => ['公司设置', '系统设置'],
];
$menu = [
    ['code' => 'r', 'name' => 'Rakuten', 'color' => '#bf0000', 'locked' => false],
    ['code' => 'y', 'name' => 'Yahoo', 'color' => '#ff0033', 'locked' => false],
    ['code' => 'w', 'name' => 'Wowma', 'color' => '#ff6a00', 'locked' => false],
    ['code' => 'm', 'name' => 'Mercari', 'color' => '#ff0211', 'locked' => false],
    ['code' => 'q', 'name' => 'Qoo10', 'color' => '#527fef', 'locked' => false],
];
$active = 'dashboard';
$title = '测试';
$content = '<div>content</div>';

ob_start();
require $basePath . '/app/Views/layouts/tenant.php';
$html = (string) ob_get_clean();

$assert('左侧栏展示签收地入口', str_contains($html, '>签收地</a>'));
$assert('签收地入口指向国内快递设置页签', str_contains($html, 'href="/settings?tenant=erp#logistics"'));

$assert('Rakuten platform tag uses brand color', str_contains($html, 'class="dot platform-nav-tag" style="background: #bf0000"'));
$assert('Yahoo platform tag uses brand color', str_contains($html, 'class="dot platform-nav-tag" style="background: #ff0033"'));
$assert('Wowma platform tag uses brand color', str_contains($html, 'class="dot platform-nav-tag" style="background: #ff6a00"'));
$assert('Mercari platform tag uses brand color', str_contains($html, 'class="dot platform-nav-tag" style="background: #ff0211"'));
$assert('Qoo10 platform tag uses brand color', str_contains($html, 'class="dot platform-nav-tag" style="background: #527fef"'));

if ($failures !== []) {
    fwrite(STDERR, "Tenant sidebar test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Tenant sidebar test passed.\n";
