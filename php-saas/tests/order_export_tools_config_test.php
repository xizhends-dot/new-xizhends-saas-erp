<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $basePath);
}
require $basePath . '/vendor/autoload.php';
require $basePath . '/app/Core/helpers.php';

use Xizhen\Core\JsonStore;
use Xizhen\Services\AuthService;
use Xizhen\Services\ExportTemplateService;
use Xizhen\Services\OrderPageConfigRegistry;

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

$jsonPath = sys_get_temp_dir() . '/xizhen-order-export-tools-' . bin2hex(random_bytes(6)) . '.json';
file_put_contents($jsonPath, json_encode(order_export_tools_fixture(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$store = new JsonStore($jsonPath);

$store->saveTenantSettings('erp', ['order_export_tools' => [
    'shipment_export' => 'hidden',
    'finance_export' => 'primary',
    'legacy_leftover' => 'more',
]]);
$store->saveTenantSettings('erp', ['order_export_tools' => [
    'platform_export' => 'more',
]]);
$settings = (new JsonStore($jsonPath))->tenantSettings('erp');
$assertSame('JsonStore order_export_tools 整体替换', ['platform_export' => 'more'], $settings['order_export_tools'] ?? null);

$templateService = new ExportTemplateService($store);
$saved = $templateService->save('erp', [
    'name' => '订单页自定义模板',
    'format' => 'csv',
    'columns' => [
        ['type' => 'field', 'key' => 'order.platform_order_id', 'label' => '订单号'],
    ],
]);
$templateId = (string) ($saved['template']['id'] ?? '');
$templateKey = OrderPageConfigRegistry::templateToolKey($templateId);
$yahooOnly = $templateService->save('erp', [
    'name' => 'Yahoo专用模板',
    'format' => 'csv',
    'platforms' => ['y'],
    'columns' => [
        ['type' => 'field', 'key' => 'order.platform_order_id', 'label' => '订单号'],
    ],
]);
$yahooTemplateId = (string) ($yahooOnly['template']['id'] ?? '');
$yahooTemplateKey = OrderPageConfigRegistry::templateToolKey($yahooTemplateId);
$legacyTemplateId = 'tpl_legacy_no_platforms';
$settingsForLegacy = $store->tenantSettings('erp');
$legacyTemplates = is_array($settingsForLegacy['export_templates'] ?? null) ? $settingsForLegacy['export_templates'] : [];
$legacyTemplates[] = [
    'id' => $legacyTemplateId,
    'name' => '存量无平台模板',
    'format' => 'csv',
    'columns' => [
        ['type' => 'field', 'key' => 'order.platform_order_id', 'label' => '订单号'],
    ],
];
$store->saveTenantSettings('erp', ['export_templates' => $legacyTemplates]);
$legacyTemplateKey = OrderPageConfigRegistry::templateToolKey($legacyTemplateId);
$store->saveTenantSettings('erp', ['order_export_tools' => [
    'shipment_export' => 'hidden',
    'finance_export' => 'primary',
    $templateKey => 'primary',
    $yahooTemplateKey => 'primary',
    $legacyTemplateKey => 'primary',
]]);

$registry = new OrderPageConfigRegistry($store, 'erp');
$tools = $registry->exportToolsFor('r', [
    'role' => '公司管理员',
    'is_company_admin' => true,
    'permissions' => ['导入导出', '财务导出', '公司设置'],
]);
$toolsByKey = [];
foreach ($tools as $tool) {
    $toolsByKey[(string) ($tool['key'] ?? '')] = $tool;
}
$assert('配置 hidden 后不输出发货表导出', !isset($toolsByKey['shipment_export']));
$assertSame('财务表导出配置为常驻', 'primary', $toolsByKey['finance_export']['group'] ?? null);
$assert('模板配置后渲染为工具', isset($toolsByKey[$templateKey]));
$assertSame('模板工具动作', '/import-export/platform-special/export', $toolsByKey[$templateKey]['action'] ?? null);
$assertSame('模板工具参数', $templateId, $toolsByKey[$templateKey]['params']['template_id'] ?? null);
$assert('r页不显示Yahoo专用模板', !isset($toolsByKey[$yahooTemplateKey]));
$assert('存量无platforms模板仍全平台显示', isset($toolsByKey[$legacyTemplateKey]));

$yahooToolsByKey = [];
foreach ($registry->exportToolsFor('y', [
    'role' => '公司管理员',
    'is_company_admin' => true,
    'permissions' => ['导入导出', '财务导出', '公司设置'],
]) as $tool) {
    $yahooToolsByKey[(string) ($tool['key'] ?? '')] = $tool;
}
$assert('y页显示Yahoo专用模板', isset($yahooToolsByKey[$yahooTemplateKey]));

$wowmaToolsByKey = [];
foreach ($registry->exportToolsFor('w', [
    'role' => '公司管理员',
    'is_company_admin' => true,
    'permissions' => ['导入导出', '财务导出', '公司设置'],
]) as $tool) {
    $wowmaToolsByKey[(string) ($tool['key'] ?? '')] = $tool;
}
$assert('w页不显示Yahoo专用模板', !isset($wowmaToolsByKey[$yahooTemplateKey]));

$plainTools = $registry->exportToolsFor('r', [
    'role' => '客服',
    'permissions' => ['订单编辑', '公司设置'],
]);
$plainKeys = array_map(static fn (array $tool): string => (string) ($tool['key'] ?? ''), $plainTools);
$plainByKey = [];
foreach ($plainTools as $tool) {
    $plainByKey[(string) ($tool['key'] ?? '')] = $tool;
}
$assert('无导入导出权限不显示配置出的模板', !in_array($templateKey, $plainKeys, true));
$assert('无导入导出权限财务导出 visibleWhen=false', empty($plainByKey['finance_export']['visibleWhen']));
$assert('无导入导出权限仍可见同步订单', !empty($plainByKey['sync_orders']['visibleWhen']));

$normalized = OrderPageConfigRegistry::normalizeDisplayConfig([
    'shipment_export' => 'more',
    'finance_export' => 'invalid',
    'unknown' => 'primary',
    $templateKey => 'hidden',
], ['shipment_export', 'finance_export', $templateKey]);
$assertSame('配置规范化只保留合法键值', ['shipment_export' => 'more', $templateKey => 'hidden'], $normalized);

$denied = order_export_tools_forbidden_status($basePath, $jsonPath);
$assertSame('无公司设置权限访问配置页被拒', 403, $denied);

$orderToolsHtml = order_export_tools_controller_html($basePath, $jsonPath, 'orderTools', [
    'tenant' => 'erp',
]);
$assert('配置页显示新建导出模板入口', str_contains($orderToolsHtml, '新建导出模板') && str_contains($orderToolsHtml, '/import-export/export-templates/edit?tenant=erp'));
$assert('配置页新建入口带回配置页 return', str_contains($orderToolsHtml, 'return=%2Fimport-export%2Forder-tools%3Ftenant%3Derp'));
$assert('配置页自定义模板显示编辑入口', str_contains($orderToolsHtml, '编辑') && str_contains($orderToolsHtml, 'id=' . $templateId));
$assert('配置页自定义模板显示删除表单', str_contains($orderToolsHtml, '/import-export/export-templates/delete') && str_contains($orderToolsHtml, 'name="id" value="' . $templateId . '"'));
$assert('配置页预置模板显示复制入口与说明', str_contains($orderToolsHtml, '复制为自定义') && str_contains($orderToolsHtml, '预置模板不可直接修改,复制后可自由编辑字段') && str_contains($orderToolsHtml, 'builtin_riya'));
$assert('配置页显示模板适用平台标签', str_contains($orderToolsHtml, 'Yahoo购物') && str_contains($orderToolsHtml, '全平台'));

$builtinEditHtml = order_export_tools_controller_html($basePath, $jsonPath, 'exportTemplateEdit', [
    'tenant' => 'erp',
    'id' => 'builtin_riya',
    'return' => '/import-export/order-tools?tenant=erp',
]);
$assert('编辑 builtin 时模板 id 置空', str_contains($builtinEditHtml, 'name="id" value=""'));
$assert('编辑 builtin 时名称带副本', str_contains($builtinEditHtml, '副本'));
$assert('编辑页保留配置页 return', str_contains($builtinEditHtml, 'name="return" value="/import-export/order-tools?tenant=erp"'));

@unlink($jsonPath);

if ($failures !== []) {
    fwrite(STDERR, "Order export tools config test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Order export tools config test passed.\n";

function order_export_tools_fixture(): array
{
    $hash = static fn (string $password): string => AuthService::makePasswordHash($password);

    return [
        'admins' => [],
        'platforms' => [['code' => 'r', 'name' => '乐天 Rakuten', 'short' => 'Rakuten', 'default_enabled' => true]],
        'tenants' => [[
            'id' => 1,
            'key' => 'erp',
            'company_name' => '测试租户',
            'short_name' => '测试',
            'subdomain' => 'erp',
            'db_name' => 'xizhen_tenant_erp',
            'plan' => 'basic',
            'status' => 'active',
            'staff_count' => 2,
            'balance' => 100,
            'contact' => '',
            'phone' => '',
            'platforms' => [['code' => 'r', 'enabled' => true, 'locked' => false]],
            'features' => [
                ['key' => 'import_export.center', 'enabled' => true],
                ['key' => 'import_export.platform_special', 'enabled' => true],
            ],
        ]],
        'announcements' => [],
        'orders' => ['erp' => []],
        'stores' => ['erp' => []],
        'users' => [
            'erp' => [
                [
                    'id' => 1,
                    'name' => '公司管理员',
                    'username' => 'admin',
                    'role' => '公司管理员',
                    'password_hash' => $hash('Admin@123'),
                    'is_company_admin' => true,
                    'permissions' => [],
                    'stores' => ['全部店铺'],
                    'status' => 'active',
                ],
                [
                    'id' => 2,
                    'name' => '普通客服',
                    'username' => 'support',
                    'role' => '客服',
                    'password_hash' => $hash('Support@123'),
                    'is_company_admin' => false,
                    'permissions' => ['订单查看'],
                    'stores' => ['全部店铺'],
                    'status' => 'active',
                ],
            ],
        ],
        'assignments' => ['erp' => []],
        'attachments' => ['erp' => []],
        'settings' => ['global' => [], 'tenant' => []],
        'import_export_logs' => ['erp' => []],
        'purchase_status_events' => ['erp' => []],
        'billing' => ['ledger' => ['erp' => []], 'subscriptions' => ['erp' => []]],
        'mail' => ['accounts' => ['erp' => []], 'folders' => ['erp' => []], 'messages' => ['erp' => []], 'replies' => ['erp' => []], 'rules' => ['erp' => []], 'settings' => ['erp' => []]],
    ];
}

function order_export_tools_forbidden_status(string $basePath, string $jsonPath): int
{
    $childScript = sys_get_temp_dir() . '/xizhen-order-export-tools-deny-' . bin2hex(random_bytes(6)) . '.php';
    $resultFile = sys_get_temp_dir() . '/xizhen-order-export-tools-deny-' . bin2hex(random_bytes(6)) . '.json';
    $code = <<<'PHP'
<?php

declare(strict_types=1);

$basePath = %BASE_PATH%;
$jsonPath = %JSON_PATH%;
$resultFile = %RESULT_FILE%;
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $basePath);
}
require $basePath . '/vendor/autoload.php';
require $basePath . '/app/Core/helpers.php';

use Xizhen\Core\JsonStore;
use Xizhen\Core\View;
use Xizhen\Http\Controllers\Tenant\ImportExportController;
use Xizhen\Services\AuthService;

session_id('order-export-tools-deny-' . bin2hex(random_bytes(3)));
session_start();
$_GET = ['tenant' => 'erp'];
$_SERVER['REQUEST_URI'] = '/import-export/order-tools?tenant=erp';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$store = new JsonStore($jsonPath);
$auth = new AuthService($store);
$auth->loginTenant('erp', 'support', 'Support@123');

register_shutdown_function(static function () use ($resultFile): void {
    file_put_contents($resultFile, json_encode(['status' => http_response_code()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
});

(new ImportExportController($store, new View($basePath . '/app/Views'), $auth))->orderTools();
PHP;
    $code = str_replace(
        ['%BASE_PATH%', '%JSON_PATH%', '%RESULT_FILE%'],
        [var_export($basePath, true), var_export($jsonPath, true), var_export($resultFile, true)],
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

    return (int) ($result['status'] ?? 0);
}

/** @param array<string, string> $get */
function order_export_tools_controller_html(string $basePath, string $jsonPath, string $method, array $get): string
{
    $childScript = sys_get_temp_dir() . '/xizhen-order-export-tools-html-' . bin2hex(random_bytes(6)) . '.php';
    $resultFile = sys_get_temp_dir() . '/xizhen-order-export-tools-html-' . bin2hex(random_bytes(6)) . '.html';
    $code = <<<'PHP'
<?php

declare(strict_types=1);

$basePath = %BASE_PATH%;
$jsonPath = %JSON_PATH%;
$resultFile = %RESULT_FILE%;
$method = %METHOD%;
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $basePath);
}
require $basePath . '/vendor/autoload.php';
require $basePath . '/app/Core/helpers.php';

use Xizhen\Core\JsonStore;
use Xizhen\Core\View;
use Xizhen\Http\Controllers\Tenant\ImportExportController;
use Xizhen\Services\AuthService;

session_id('order-export-tools-html-' . bin2hex(random_bytes(3)));
session_start();
$_GET = %GET%;
$_SERVER['REQUEST_URI'] = '/import-export/order-tools?tenant=erp';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$store = new JsonStore($jsonPath);
$auth = new AuthService($store);
$auth->loginTenant('erp', 'admin', 'Admin@123');

$controller = new ImportExportController($store, new View($basePath . '/app/Views'), $auth);
ob_start();
$controller->{$method}();
$html = (string) ob_get_clean();
file_put_contents($resultFile, $html);
PHP;
    $code = str_replace(
        ['%BASE_PATH%', '%JSON_PATH%', '%RESULT_FILE%', '%METHOD%', '%GET%'],
        [var_export($basePath, true), var_export($jsonPath, true), var_export($resultFile, true), var_export($method, true), var_export($get, true)],
        $code
    );
    file_put_contents($childScript, $code);

    $php = PHP_BINARY ?: 'php';
    $output = [];
    $exitCode = 0;
    exec('"' . $php . '" ' . escapeshellarg($childScript) . ' 2>&1', $output, $exitCode);
    $html = is_file($resultFile) ? (string) file_get_contents($resultFile) : implode("\n", $output);

    @unlink($childScript);
    @unlink($resultFile);

    return $html;
}
