<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $basePath);
}
require $basePath . '/vendor/autoload.php';
require $basePath . '/app/Core/helpers.php';

$childScript = sys_get_temp_dir() . '/xizhen-order-batch-status-source-' . bin2hex(random_bytes(6)) . '.php';
$resultFile = sys_get_temp_dir() . '/xizhen-order-batch-status-source-' . bin2hex(random_bytes(6)) . '.json';

$code = <<<'PHP'
<?php

declare(strict_types=1);

$basePath = %BASE_PATH%;
$resultFile = %RESULT_FILE%;
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $basePath);
}
require $basePath . '/vendor/autoload.php';
require $basePath . '/app/Core/helpers.php';

use Xizhen\Core\JsonStore;
use Xizhen\Core\View;
use Xizhen\Http\Controllers\Tenant\OrderController;
use Xizhen\Services\AuthService;

$jsonPath = sys_get_temp_dir() . '/xizhen-order-batch-status-source-store-' . bin2hex(random_bytes(6)) . '.json';
$data = [
    'admins' => [],
    'platforms' => [['code' => 'r', 'name' => '乐天 Rakuten', 'short' => 'Rakuten']],
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
        'platforms' => [['code' => 'r', 'enabled' => true, 'locked' => false]],
        'features' => [
            ['key' => 'orders.edit', 'enabled' => true],
            ['key' => 'orders.platform', 'enabled' => true],
            ['key' => 'orders.purchase', 'enabled' => true],
        ],
    ]],
    'announcements' => [],
    'orders' => ['erp' => [[
        'id' => 10,
        'platform' => 'r',
        'platform_order_id' => 'R-BATCH-STATUS-1',
        'store' => '乐天一店',
        'items' => [
            ['id' => 1001, 'source_type' => 'cn_purchase', 'purchase_status' => '未处理的订单'],
            ['id' => 1002, 'source_type' => 'jp_stock', 'purchase_status' => '日本仓待处理'],
        ],
    ]]],
    'stores' => ['erp' => [['id' => 1, 'platform' => 'r', 'name' => '乐天一店', 'short' => 'R-01', 'status' => 'visible']]],
    'users' => ['erp' => [[
        'id' => 1,
        'username' => 'admin',
        'password' => password_hash('pass', PASSWORD_DEFAULT),
        'name' => '管理员',
        'role' => '公司管理员',
        'status' => 'active',
        'permissions' => ['批量操作', '订单编辑', '采购状态', '日本仓发货'],
        'stores' => ['全部店铺'],
        'is_company_admin' => true,
    ]],
    ],
    'assignments' => ['erp' => []],
    'attachments' => ['erp' => []],
    'settings' => ['global' => [], 'tenant' => []],
    'import_export_logs' => ['erp' => []],
    'purchase_status_events' => ['erp' => []],
    'billing' => ['ledger' => ['erp' => []], 'subscriptions' => ['erp' => []]],
    'mail' => ['accounts' => ['erp' => []], 'folders' => ['erp' => []], 'messages' => ['erp' => []], 'replies' => ['erp' => []], 'rules' => ['erp' => []], 'settings' => ['erp' => []]],
];
file_put_contents($jsonPath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

session_id('batch-status-source-test-' . bin2hex(random_bytes(3)));
session_start();
$_SESSION['xizhen_auth']['tenants']['erp'] = ['id' => 1, 'username' => 'admin'];
$_GET = ['tenant' => 'erp'];
$_POST = [
    'batch_action' => 'set_purchase_status',
    'view' => 'platform',
    'order_ids' => [],
    'item_ids' => ['1002'],
    'purchase_status' => '日本仓已完成',
    'return' => '/orders?tenant=erp&view=platform',
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/orders/batch?tenant=erp';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$store = new JsonStore($jsonPath);
register_shutdown_function(static function () use ($jsonPath, $resultFile): void {
    $saved = is_file($jsonPath) ? (json_decode((string) file_get_contents($jsonPath), true) ?: []) : [];
    file_put_contents($resultFile, json_encode([
        'orders' => $saved['orders']['erp'] ?? [],
        'status' => http_response_code(),
        'headers' => headers_list(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    @unlink($jsonPath);
});

$controller = new OrderController($store, new View($basePath . '/app/Views'), new AuthService($store));
$controller->batchOrders();
PHP;

$code = str_replace(
    ['%BASE_PATH%', '%RESULT_FILE%'],
    [var_export($basePath, true), var_export($resultFile, true)],
    $code
);
file_put_contents($childScript, $code);

$php = PHP_BINARY ?: 'php';
$output = [];
$exitCode = 0;
exec('"' . $php . '" ' . escapeshellarg($childScript) . ' 2>&1', $output, $exitCode);
$result = is_file($resultFile) ? json_decode((string) file_get_contents($resultFile), true) : null;

@unlink($childScript);
@unlink($resultFile);

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

$assertSame('子进程退出码', 0, $exitCode);
$assert('子进程写出结果', is_array($result));
$items = is_array($result) ? ($result['orders'][0]['items'] ?? []) : [];
$assertSame('未勾选的国内采购子项不会被平台页批量状态误改', '未处理的订单', $items[0]['purchase_status'] ?? null);
$assertSame('已勾选的日本仓子项被平台页批量状态更新', '日本仓已完成', $items[1]['purchase_status'] ?? null);
$assertSame('批量改状态 303 跳转状态码', 303, (int) ($result['status'] ?? 0));

if ($failures !== []) {
    fwrite(STDERR, "Order batch status source test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    if ($output !== []) {
        fwrite(STDERR, "\nChild output:\n" . implode("\n", $output) . "\n");
    }
    exit(1);
}

echo "Order batch status source test passed.\n";
