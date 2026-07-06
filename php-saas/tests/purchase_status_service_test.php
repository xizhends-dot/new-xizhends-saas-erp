<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
require $basePath . '/app/Core/StoreInterface.php';
require $basePath . '/app/Core/Permission.php';
require $basePath . '/app/Core/TenantFeature.php';
require $basePath . '/app/Services/AuthService.php';
require $basePath . '/app/Services/TenantProvisioningService.php';
require $basePath . '/app/Core/JsonStore.php';
require $basePath . '/app/Services/AppService.php';
require $basePath . '/app/Services/PurchaseStatusService.php';

use Xizhen\Core\JsonStore;
use Xizhen\Services\PurchaseStatusService;

$failures = [];
$assert = static function (string $label, bool $ok) use (&$failures): void {
    if (!$ok) {
        $failures[] = $label;
    }
};
$assertSame = static function (string $label, mixed $expected, mixed $actual) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
};

$defaults = PurchaseStatusService::defaultStatuses();
$assertSame('默认状态 17 项', 17, count($defaults));
$assertSame('默认首项', '未处理的订单', $defaults[0] ?? '');

$missing = PurchaseStatusService::validateStatuses(array_values(array_filter(
    $defaults,
    static fn (string $status): bool => $status !== '已发日本'
)));
$assert('缺失系统状态拒绝', $missing['ok'] === false);

$renamed = $defaults;
$renamed[array_search('已发日本', $renamed, true)] = '已发日本2';
$assert('系统状态改名拒绝', PurchaseStatusService::validateStatuses($renamed)['ok'] === false);

$duplicate = $defaults;
$duplicate[] = '已发日本';
$assert('重名拒绝', PurchaseStatusService::validateStatuses($duplicate)['ok'] === false);

$empty = $defaults;
$empty[] = '  ';
$assert('空名拒绝', PurchaseStatusService::validateStatuses($empty)['ok'] === false);

$newline = $defaults;
$newline[] = "新\n状态";
$assert('换行拒绝', PurchaseStatusService::validateStatuses($newline)['ok'] === false);

$long = $defaults;
$long[] = str_repeat('长', 33);
$assert('超长拒绝', PurchaseStatusService::validateStatuses($long)['ok'] === false);

$tooMany = $defaults;
for ($i = 1; $i <= 34; $i++) {
    $tooMany[] = '自定义' . $i;
}
$assertSame('超量 fixture 数量', 51, count($tooMany));
$assert('超量拒绝', PurchaseStatusService::validateStatuses($tooMany)['ok'] === false);

$reordered = array_reverse($defaults);
$assert('系统状态换序合法', PurchaseStatusService::validateStatuses($reordered)['ok'] === true);

$withAaa = $defaults;
$insertBefore = array_search('发货中', $withAaa, true);
array_splice($withAaa, (int) $insertBefore, 0, ['AAA']);
$validation = PurchaseStatusService::validateStatuses($withAaa);
$assert('AAA 插入合法', $validation['ok'] === true);
$aaaIndex = array_search('AAA', $validation['statuses'], true);
$purchasedIndex = array_search('国内采购-已采购', $validation['statuses'], true);
$shippingIndex = array_search('发货中', $validation['statuses'], true);
$assert('AAA 位于已采购与发货中之间', $purchasedIndex !== false && $aaaIndex !== false && $shippingIndex !== false && $purchasedIndex < $aaaIndex && $aaaIndex < $shippingIndex);

$withoutNonSystem = array_values(array_filter(
    $withAaa,
    static fn (string $status): bool => $status !== '刷单订单'
));
$assert('删除非系统状态合法', PurchaseStatusService::validateStatuses($withoutNonSystem)['ok'] === true);

$jsonPath = sys_get_temp_dir() . '/xizhen-purchase-status-' . bin2hex(random_bytes(6)) . '.json';
file_put_contents($jsonPath, json_encode([
    'admins' => [],
    'platforms' => [],
    'tenants' => [[
        'id' => 1,
        'key' => 'erp',
        'company_name' => '测试租户',
        'short_name' => '测试',
        'subdomain' => 'erp',
        'db_name' => 'xizhen_tenant_erp',
        'plan' => 'basic',
        'status' => 'active',
        'staff_count' => 0,
        'balance' => 0,
        'contact' => '',
        'phone' => '',
        'platforms' => [],
        'features' => [],
    ]],
    'announcements' => [],
    'orders' => ['erp' => []],
    'stores' => ['erp' => []],
    'users' => ['erp' => []],
    'assignments' => ['erp' => []],
    'attachments' => ['erp' => []],
    'settings' => ['global' => [], 'tenant' => []],
    'import_export_logs' => ['erp' => []],
    'purchase_status_events' => ['erp' => []],
    'billing' => ['ledger' => ['erp' => []], 'subscriptions' => ['erp' => []]],
    'mail' => ['accounts' => ['erp' => []], 'folders' => ['erp' => []], 'messages' => ['erp' => []], 'replies' => ['erp' => []], 'rules' => ['erp' => []], 'settings' => ['erp' => []]],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$store = new JsonStore($jsonPath);
$service = new PurchaseStatusService($store);
$assertSame('设置缺失回退默认', $defaults, $service->statusesFor('erp'));
$assertSame('日本仓设置缺失回退默认四项', PurchaseStatusService::defaultJpStockStatuses(), $service->jpStockStatusesFor('erp'));

$save = $service->saveStatuses('erp', $withAaa);
$assert('JsonStore 保存成功', ($save['ok'] ?? false) === true);
$readBack = $service->statusesFor('erp');
$assertSame('JsonStore 读回一致', $withAaa, $readBack);

$next = array_values(array_filter($withoutNonSystem, static fn (string $status): bool => $status !== 'AAA'));
$save2 = $service->saveStatuses('erp', $next);
$assert('JsonStore 二次保存成功', ($save2['ok'] ?? false) === true);
$fresh = new PurchaseStatusService(new JsonStore($jsonPath));
$freshStatuses = $fresh->statusesFor('erp');
$assertSame('JsonStore 整体替换无旧值', $next, $freshStatuses);
$assert('JsonStore 无 AAA 残留', !in_array('AAA', $freshStatuses, true));
$assert('JsonStore 无刷单订单残留', !in_array('刷单订单', $freshStatuses, true));

$jpCustom = ['JP-A', 'JP-B', 'JP-C'];
$jpSave = $fresh->saveJpStockStatuses('erp', $jpCustom);
$assert('日本仓状态保存成功', ($jpSave['ok'] ?? false) === true);
$jpReadBack = (new PurchaseStatusService(new JsonStore($jsonPath)))->jpStockStatusesFor('erp');
$assertSame('日本仓状态读回自定义清单', $jpCustom, $jpReadBack);
$assertSame('日本仓 source 选项使用自定义清单', $jpCustom, PurchaseStatusService::statusOptionsForSource('jp_stock', $next, $jpCustom));
$assertSame('国内 source 选项仍使用国内清单', $next, PurchaseStatusService::statusOptionsForSource('cn_purchase', $next, $jpCustom));
$assert('日本仓空清单拒绝保存', $fresh->saveJpStockStatuses('erp', [])['ok'] === false);
$jpNext = ['JP-B', 'JP-D'];
$jpSave2 = $fresh->saveJpStockStatuses('erp', $jpNext);
$assert('日本仓状态二次保存成功', ($jpSave2['ok'] ?? false) === true);
$jpFreshStatuses = (new PurchaseStatusService(new JsonStore($jsonPath)))->jpStockStatusesFor('erp');
$assertSame('日本仓状态整体替换无旧值', $jpNext, $jpFreshStatuses);
$assert('日本仓状态无 JP-A 残留', !in_array('JP-A', $jpFreshStatuses, true));

$fresh->resetStatuses('erp');
$resetStatuses = (new PurchaseStatusService(new JsonStore($jsonPath)))->statusesFor('erp');
$assertSame('JsonStore 恢复默认回退', $defaults, $resetStatuses);
$fresh->resetJpStockStatuses('erp');
$resetJpStatuses = (new PurchaseStatusService(new JsonStore($jsonPath)))->jpStockStatusesFor('erp');
$assertSame('JsonStore 日本仓恢复默认回退', PurchaseStatusService::defaultJpStockStatuses(), $resetJpStatuses);

@unlink($jsonPath);

if ($failures !== []) {
    fwrite(STDERR, "PurchaseStatusService test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "PurchaseStatusService test OK\n";
