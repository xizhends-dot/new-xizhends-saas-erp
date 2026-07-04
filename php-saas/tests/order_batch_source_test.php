<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $basePath);
}
require $basePath . '/vendor/autoload.php';
require $basePath . '/app/Core/helpers.php';

$childScript = sys_get_temp_dir() . '/xizhen-order-batch-source-' . bin2hex(random_bytes(6)) . '.php';
$resultFile = sys_get_temp_dir() . '/xizhen-order-batch-source-' . bin2hex(random_bytes(6)) . '.json';

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

use Xizhen\Core\StoreInterface;
use Xizhen\Core\View;
use Xizhen\Http\Controllers\Tenant\OrderController;
use Xizhen\Services\AuthService;

final class BatchSourceTestStore implements StoreInterface
{
    /** @var array<int, array{tenant: string, item_id: int, source: string}> */
    public array $sourceChanges = [];
    /** @var array<int, array<string, mixed>> */
    private array $orders = [[
        'id' => 10,
        'platform' => 'r',
        'platform_order_id' => 'R-BATCH-1',
        'store' => '乐天一店',
        'items' => [
            ['id' => 1001, 'source_type' => 'pending', 'purchase_status' => '未处理的订单'],
            ['id' => 1002, 'source_type' => 'pending', 'purchase_status' => '未处理的订单'],
        ],
    ]];

    public function all(): array { return []; }
    public function tenants(): array { return [['key' => 'erp', 'status' => 'active']]; }
    public function createTenant(array $data): array { return ['ok' => false, 'message' => 'not implemented']; }
    public function adminByUsername(string $username): ?array { return null; }
    public function touchAdminLogin(int $adminId): void {}
    public function tenant(string $key): array { return ['key' => $key, 'status' => 'active', 'platforms' => [['code' => 'r', 'enabled' => true, 'locked' => false]]]; }
    public function platforms(): array { return [['code' => 'r', 'name' => '乐天 Rakuten', 'short' => 'Rakuten']]; }
    public function orders(string $tenantKey): array { return $this->orders; }
    public function ordersByYear(string $tenantKey, int $year): array { return $this->orders; }
    public function ordersForStores(string $tenantKey, array $stores): array { return $this->orders; }
    public function order(string $tenantKey, int $orderId): ?array { return $this->orders[0]; }
    public function announcements(): array { return []; }
    public function tenantPlatforms(string $tenantKey): array { return [['code' => 'r', 'enabled' => true, 'locked' => false]]; }
    public function tenantFeatures(string $tenantKey): array { return [['key' => 'orders.edit', 'enabled' => true], ['key' => 'orders.platform', 'enabled' => true]]; }
    public function tenantBillingAccount(string $tenantKey): array { return []; }
    public function tenantBillingLedger(string $tenantKey, int $limit = 50): array { return []; }
    public function tenantBillingSubscriptions(string $tenantKey): array { return []; }
    public function adjustTenantPoints(string $tenantKey, int $amount, string $type, string $note, string $operator): void {}
    public function chargeTenantPoints(string $tenantKey, int $amount, string $note, string $operator): bool { return true; }
    public function processDueTenantBilling(string $tenantKey, string $operator = 'system'): array { return []; }
    public function stores(string $tenantKey): array { return [['id' => 1, 'platform' => 'r', 'name' => '乐天一店', 'short' => 'R-01', 'status' => 'visible']]; }
    public function store(string $tenantKey, int $storeId): ?array { return $this->stores($tenantKey)[0]; }
    public function addStore(string $tenantKey, array $data): bool { return true; }
    public function updateStore(string $tenantKey, int $storeId, array $data): void {}
    public function mergeStoreApiConfig(string $tenantKey, int $storeId, array $patch, string $apiStatus = '已配置'): void {}
    public function users(string $tenantKey): array { return [$this->user($tenantKey, 1)]; }
    public function user(string $tenantKey, int $userId): ?array { return ['id' => 1, 'username' => 'admin', 'name' => '管理员', 'role' => '客服', 'status' => 'active', 'permissions' => ['货源改判'], 'stores' => ['全部店铺'], 'is_company_admin' => false]; }
    public function tenantUserByUsername(string $tenantKey, string $username): ?array { return $this->user($tenantKey, 1); }
    public function updateTenantUserPassword(string $tenantKey, int $userId, string $passwordHash): void {}
    public function touchTenantUserLogin(string $tenantKey, int $userId): void {}
    public function addUser(string $tenantKey, array $data): void {}
    public function updateUser(string $tenantKey, int $userId, array $data): void {}
    public function updateUserPermissionOverrides(string $tenantKey, int $userId, array $overrides, string $operator): void {}
    public function assignments(string $tenantKey): array { return []; }
    public function saveAssignmentByBuyer(string $tenantKey, int $buyerUserId, array $supportUserIds): void {}
    public function saveAssignmentBySupport(string $tenantKey, int $supportUserId, array $buyerUserIds): void {}
    public function togglePlatform(string $tenantKey, string $platformCode, string $field): void {}
    public function toggleTenantFeature(string $tenantKey, string $featureKey): void {}
    public function changeItemSource(string $tenantKey, int $itemId, string $source): void { $this->sourceChanges[] = ['tenant' => $tenantKey, 'item_id' => $itemId, 'source' => $source]; }
    public function batchUpdateItems(string $tenantKey, array $itemIds, array $orderIds, array $changes, string $operator = '系统管理员', string $action = '批量更新'): void { throw new RuntimeException('set_source must not call batchUpdateItems'); }
    public function transitionItemPurchaseStatus(string $tenantKey, array $itemIds, string $fromStatus, string $toStatus, string $operator = '系统管理员', string $action = '状态流转'): int { return 0; }
    public function updateItemsLogistics(string $tenantKey, array $itemIds, string $status, string $action, string $operator): int { return 0; }
    public function deleteOrders(string $tenantKey, array $orderIds): void {}
    public function updateOrderFlags(string $tenantKey, int $orderId, array $flags, string $operator): void {}
    public function insertExternalOrder(string $tenantKey, array $data, string $operator): int { return 0; }
    public function upsertPlatformOrders(string $tenantKey, array $orders, string $operator): array { return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'items_inserted' => 0, 'items_updated' => 0]; }
    public function markStoreSync(string $tenantKey, int $storeId, string $status, string $message): void {}
    public function updateOrderItem(string $tenantKey, int $itemId, array $data, string $operator = '系统管理员', string $action = '保存明细'): void {}
    public function purchaseStatusEvents(string $tenantKey, string $date, ?array $user = null, string $platform = ''): array { return []; }
    public function importPlatformOrders(string $tenantKey, array $records, string $operator): array { return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'messages' => []]; }
    public function importPurchaseRows(string $tenantKey, array $records, string $operator): array { return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'messages' => []]; }
    public function importShippingRows(string $tenantKey, array $records, string $operator): array { return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'messages' => []]; }
    public function updateOrderItemImage(string $tenantKey, int $itemId, string $kind, string $path): void {}
    public function orderAttachments(string $tenantKey, int $orderId): array { return []; }
    public function addOrderAttachment(string $tenantKey, int $orderId, array $data): void {}
    public function deleteOrderAttachment(string $tenantKey, int $orderId, int $attachmentId): void {}
    public function globalSettings(): array { return []; }
    public function saveGlobalSettings(array $data): void {}
    public function tenantSettings(string $tenantKey): array { return []; }
    public function saveTenantSettings(string $tenantKey, array $data): void {}
    public function tenantNotices(string $tenantKey): array { return []; }
    public function tenantNotice(string $tenantKey, int $noticeId): ?array { return null; }
    public function saveTenantNotice(string $tenantKey, array $data): int { return 0; }
    public function deleteTenantNotice(string $tenantKey, int $noticeId): void {}
    public function toggleTenantNoticePinned(string $tenantKey, int $noticeId, bool $pinned): void {}
    public function importExportLogs(string $tenantKey): array { return []; }
    public function addImportExportLog(string $tenantKey, array $data): void {}
    public function mailAccounts(string $tenantKey): array { return []; }
    public function mailAccount(string $tenantKey, int $accountId): ?array { return null; }
    public function saveMailAccount(string $tenantKey, array $data): int { return 0; }
    public function deleteMailAccount(string $tenantKey, int $accountId): void {}
    public function mailFolders(string $tenantKey, ?int $accountId = null, bool $onlySynced = false): array { return []; }
    public function mailFolder(string $tenantKey, int $folderId): ?array { return null; }
    public function upsertMailFolders(string $tenantKey, int $accountId, array $folders): void {}
    public function updateMailFolder(string $tenantKey, int $folderId, array $data): void {}
    public function mailFolderCounts(string $tenantKey): array { return []; }
    public function mailMessages(string $tenantKey, array $filters, int $page, int $pageSize): array { return ['rows' => [], 'total' => 0, 'page' => $page, 'page_size' => $pageSize, 'total_pages' => 0]; }
    public function mailMessage(string $tenantKey, int $messageId): ?array { return null; }
    public function insertMailMessages(string $tenantKey, int $accountId, int $folderId, array $messages): array { return ['inserted' => 0, 'inserted_ids' => [], 'max_uid' => 0]; }
    public function updateMailFolderAfterSync(string $tenantKey, int $folderId, int $lastUid, int $messageCount, array $status = []): void {}
    public function updateMailAccountLastSync(string $tenantKey, int $accountId): void {}
    public function saveMailMessageBody(string $tenantKey, int $messageId, array $body): void {}
    public function updateMailMessages(string $tenantKey, array $messageIds, array $changes): int { return 0; }
    public function mailRules(string $tenantKey): array { return []; }
    public function saveMailRule(string $tenantKey, array $data): int { return 0; }
    public function deleteMailRule(string $tenantKey, int $ruleId): void {}
    public function addMailReply(string $tenantKey, array $data): void {}
}

session_id('batch-source-test-' . bin2hex(random_bytes(3)));
session_start();
$_SESSION['xizhen_auth']['tenants']['erp'] = ['id' => 1, 'username' => 'admin'];
$_GET = ['tenant' => 'erp'];
$_POST = [
    'batch_action' => 'set_source',
    'item_ids' => ['1001', '1002'],
    'order_ids' => [],
    'source' => 'cn_purchase',
    'return' => '/orders?tenant=erp&view=platform',
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/orders/batch?tenant=erp';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$store = new BatchSourceTestStore();
register_shutdown_function(static function () use ($store, $resultFile): void {
    file_put_contents($resultFile, json_encode([
        'changes' => $store->sourceChanges,
        'status' => http_response_code(),
        'headers' => headers_list(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
$changes = is_array($result) ? ($result['changes'] ?? []) : [];
$assertSame('批量改货源地调用次数', 2, count($changes));
$assertSame('第一条 item 走 changeItemSource', ['tenant' => 'erp', 'item_id' => 1001, 'source' => 'cn_purchase'], $changes[0] ?? null);
$assertSame('第二条 item 走 changeItemSource', ['tenant' => 'erp', 'item_id' => 1002, 'source' => 'cn_purchase'], $changes[1] ?? null);
$assertSame('批量改货源地 303 跳转状态码', 303, (int) ($result['status'] ?? 0));

if ($failures !== []) {
    fwrite(STDERR, "Order batch source test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    if ($output !== []) {
        fwrite(STDERR, "\nChild output:\n" . implode("\n", $output) . "\n");
    }
    exit(1);
}

echo "Order batch source test passed.\n";
