<?php

declare(strict_types=1);

require __DIR__ . '/../app/Core/helpers.php';
require __DIR__ . '/../app/Core/StoreInterface.php';
require __DIR__ . '/../app/Core/TenantFeature.php';
require __DIR__ . '/../app/Core/Permission.php';
require __DIR__ . '/../app/Services/AuthService.php';
require __DIR__ . '/../app/Services/TenantProvisioningService.php';
require __DIR__ . '/../app/Core/JsonStore.php';

use Xizhen\Core\JsonStore;
use Xizhen\Services\AuthService;
use Xizhen\Services\TenantProvisioningService;

assert_same(true, TenantProvisioningService::isValidSubdomain('abc-shop'), 'subdomain accepts lowercase hyphen');
assert_same(true, TenantProvisioningService::isValidSubdomain('a1'), 'subdomain accepts letters and digits');
assert_same(false, TenantProvisioningService::isValidSubdomain('-abc'), 'subdomain rejects leading hyphen');
assert_same(false, TenantProvisioningService::isValidSubdomain('abc_shop'), 'subdomain rejects underscore');
assert_same(false, TenantProvisioningService::isValidSubdomain('admin'), 'subdomain rejects reserved word');
$normalizedInvalid = TenantProvisioningService::normalizeInput(['subdomain' => 'abc_shop']);
assert_same('abc_shop', $normalizedInvalid['subdomain'], 'normalizeInput keeps invalid subdomain for validation');

assert_same('xizhen_tenant_abc_shop', TenantProvisioningService::defaultDbName('abc-shop'), 'default db name replaces hyphen');
assert_same(true, TenantProvisioningService::isValidDbName('xizhen_tenant_abc_shop'), 'db name accepts lowercase underscore');
assert_same(false, TenantProvisioningService::isValidDbName('xizhen-tenant-abc'), 'db name rejects hyphen');
assert_same(false, TenantProvisioningService::isValidDbName(str_repeat('a', 65)), 'db name rejects too long value');

$sql = <<<SQL
-- full line comment
CREATE TABLE demo (id INT); -- inline comment

INSERT INTO demo VALUES ('a; still string'); -- another comment
;
UPDATE demo SET id = 2;
SQL;
$statements = TenantProvisioningService::splitSqlStatements($sql);
assert_same([
    'CREATE TABLE demo (id INT)',
    "INSERT INTO demo VALUES ('a; still string')",
    'UPDATE demo SET id = 2',
], $statements, 'sql splitter strips comments and skips empty statements');

$jsonPath = sys_get_temp_dir() . '/xizhen-tenant-provisioning-' . bin2hex(random_bytes(6)) . '.json';
$store = new JsonStore($jsonPath);
$result = $store->createTenant([
    'company_name' => 'ABC 商贸',
    'company_short_name' => 'ABC',
    'subdomain' => 'abc-shop',
    'db_name' => '',
    'db_host' => '127.0.0.1',
    'plan' => 'pro',
    'contact_name' => '张三',
    'contact_phone' => '13800000000',
    'admin_username' => 'admin',
    'admin_password' => 'Password@123',
    'initial_points' => 120,
    'operator' => 'tester',
]);
assert_same(true, $result['ok'], 'JsonStore createTenant succeeds');
$tenant = $store->tenant('abc-shop');
assert_same('ABC 商贸', $tenant['company_name'] ?? '', 'JsonStore creates tenant profile');
assert_same('xizhen_tenant_abc_shop', $tenant['db_name'] ?? '', 'JsonStore fills default db name');
assert_same(120, (int) ($tenant['balance'] ?? 0), 'JsonStore writes initial points');
assert_same(true, count($store->tenantPlatforms('abc-shop')) > 0, 'JsonStore initializes default platforms');
assert_same(true, count($store->tenantBillingLedger('abc-shop')) === 1, 'JsonStore writes initial point ledger');

$duplicate = $store->createTenant([
    'company_name' => '重复租户',
    'subdomain' => 'abc-shop',
    'admin_username' => 'admin2',
    'admin_password' => 'Password@123',
]);
assert_same(false, $duplicate['ok'], 'JsonStore rejects duplicate subdomain');

$auth = new AuthService($store);
$login = $auth->loginTenant('abc-shop', 'admin', 'Password@123');
assert_same(true, $login['ok'], 'initial tenant admin can login');
assert_same(true, (bool) (($login['user']['is_company_admin'] ?? false)), 'initial admin is company admin');

@unlink($jsonPath);
echo "Tenant provisioning test passed.\n";

function assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}
