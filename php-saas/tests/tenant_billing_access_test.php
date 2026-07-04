<?php

declare(strict_types=1);

require __DIR__ . '/../app/Core/helpers.php';
require __DIR__ . '/../app/Core/StoreInterface.php';
require __DIR__ . '/../app/Core/TenantFeature.php';
require __DIR__ . '/../app/Core/Permission.php';
require __DIR__ . '/../app/Core/JsonStore.php';
require __DIR__ . '/../app/Services/AuthService.php';

use Xizhen\Core\JsonStore;
use Xizhen\Services\AuthService;

$jsonPath = temp_json_path();
$store = new JsonStore($jsonPath);
$auth = new AuthService($store);

assert_login_admin($auth, 'flag-admin', 'FlagAdmin@123', 'is_company_admin user login');
assert_same(true, $auth->isTenantCompanyAdmin('erp'), 'is_company_admin=1 is company admin');
$auth->logout('tenant', 'erp');

assert_login_admin($auth, 'role-admin', 'RoleAdmin@123', 'role admin user login');
assert_same(true, $auth->isTenantCompanyAdmin('erp'), 'role=公司管理员 is company admin');
$auth->logout('tenant', 'erp');

assert_login_admin($auth, 'buyer', 'Buyer@123', 'buyer user login');
assert_same(false, $auth->isTenantCompanyAdmin('erp'), 'buyer is not company admin');
$auth->logout('tenant', 'erp');

assert_login_admin($auth, 'support-rich', 'Support@123', 'support user login');
assert_same(false, $auth->isTenantCompanyAdmin('erp'), 'ordinary user with permissions is not company admin');
$auth->logout('tenant', 'erp');

@unlink($jsonPath);
echo "Tenant billing access test passed.\n";

function temp_json_path(): string
{
    $path = sys_get_temp_dir() . '/xizhen-tenant-billing-access-' . bin2hex(random_bytes(6)) . '.json';
    $hash = static fn (string $password): string => AuthService::makePasswordHash($password);
    $data = [
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
            'staff_count' => 4,
            'balance' => 100,
            'contact' => '',
            'phone' => '',
            'platforms' => [],
            'features' => [],
        ]],
        'announcements' => [],
        'orders' => ['erp' => []],
        'stores' => ['erp' => []],
        'users' => [
            'erp' => [
                [
                    'id' => 1,
                    'name' => '标记管理员',
                    'username' => 'flag-admin',
                    'role' => '客服',
                    'password_hash' => $hash('FlagAdmin@123'),
                    'is_company_admin' => true,
                    'permissions' => [],
                    'stores' => ['全部店铺'],
                    'status' => 'active',
                ],
                [
                    'id' => 2,
                    'name' => '角色管理员',
                    'username' => 'role-admin',
                    'role' => '公司管理员',
                    'password_hash' => $hash('RoleAdmin@123'),
                    'is_company_admin' => false,
                    'permissions' => [],
                    'stores' => ['全部店铺'],
                    'status' => 'active',
                ],
                [
                    'id' => 3,
                    'name' => '采购',
                    'username' => 'buyer',
                    'role' => '采购',
                    'password_hash' => $hash('Buyer@123'),
                    'is_company_admin' => false,
                    'permissions' => [],
                    'stores' => ['全部店铺'],
                    'status' => 'active',
                ],
                [
                    'id' => 4,
                    'name' => '授权客服',
                    'username' => 'support-rich',
                    'role' => '客服',
                    'password_hash' => $hash('Support@123'),
                    'is_company_admin' => false,
                    'permissions' => ['店铺新增', '员工管理', '系统设置', '公司设置', '权限覆盖'],
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
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return $path;
}

function assert_login_admin(AuthService $auth, string $username, string $password, string $label): void
{
    $result = $auth->loginTenant('erp', $username, $password);
    assert_same(true, $result['ok'] ?? false, $label);
}

function assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}
