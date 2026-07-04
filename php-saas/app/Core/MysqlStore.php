<?php

declare(strict_types=1);

namespace Xizhen\Core;

use Xizhen\Repositories\AdminRepository;
use Xizhen\Repositories\BillingRepository;
use Xizhen\Repositories\MailRepository;
use Xizhen\Repositories\MediaRepository;
use Xizhen\Repositories\OrderImportRepository;
use Xizhen\Repositories\OrderMutationRepository;
use Xizhen\Repositories\OrderQueryRepository;
use Xizhen\Repositories\OrderRepository;
use Xizhen\Repositories\SettingsRepository;
use Xizhen\Repositories\StoreRepository;
use Xizhen\Repositories\TenantRepository;
use Xizhen\Repositories\UserRepository;

final class MysqlStore implements StoreInterface
{
    private readonly Db $db;
    private readonly TenantRepository $tenantRepository;
    private readonly BillingRepository $billingRepository;
    private readonly AdminRepository $adminRepository;
    private readonly OrderRepository $orderRepository;
    private readonly StoreRepository $storeRepository;
    private readonly UserRepository $userRepository;
    private readonly MailRepository $mailRepository;
    private readonly MediaRepository $mediaRepository;
    private readonly SettingsRepository $settingsRepository;

    public function __construct(private readonly Config $config)
    {
        $this->db = new Db($config);
        $this->billingRepository = new BillingRepository($this->db);
        $this->adminRepository = new AdminRepository($this->db);
        $this->storeRepository = new StoreRepository($this->db, $this->billingRepository);
        $this->userRepository = new UserRepository($this->db);
        $this->mailRepository = new MailRepository($this->db);
        $orderQueryRepository = new OrderQueryRepository($this->db);
        $orderMutationRepository = new OrderMutationRepository($this->db);
        $orderImportRepository = new OrderImportRepository($this->db, $orderMutationRepository, $this->storeRepository);
        $this->orderRepository = new OrderRepository($orderQueryRepository, $orderMutationRepository, $orderImportRepository);
        $this->mediaRepository = new MediaRepository($this->db);
        $this->tenantRepository = new TenantRepository($this->db, $config, $this->billingRepository, $this->orderRepository, $this->adminRepository);
        $this->settingsRepository = new SettingsRepository($this->db, $this->tenantRepository);
    }



    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->tenantRepository->all();
    }



    /** @return array<string, mixed>|null */
    public function adminByUsername(string $username): ?array
    {
        return $this->adminRepository->adminByUsername($username);
    }



    public function touchAdminLogin(int $adminId): void
    {
        $this->adminRepository->touchAdminLogin($adminId);
    }



    /** @return array<int, array<string, mixed>> */
    public function tenants(): array
    {
        return $this->tenantRepository->tenants();
    }



    /** @param array<string, mixed> $data @return array{ok: bool, message: string} */
    public function createTenant(array $data): array
    {
        return $this->tenantRepository->createTenant($data);
    }



    /** @return array<string, mixed> */
    public function tenant(string $key): array
    {
        return $this->tenantRepository->tenant($key);
    }



    /** @return array<int, array<string, mixed>> */
    public function platforms(): array
    {
        return $this->tenantRepository->platforms();
    }



    /** @return array<int, array<string, mixed>> */
    public function orders(string $tenantKey): array
    {
        return $this->orderRepository->orders($tenantKey);
    }



    /** @return array<int, array<string, mixed>> */
    public function ordersByYear(string $tenantKey, int $year): array
    {
        return $this->orderRepository->ordersByYear($tenantKey, $year);
    }



    /**
     * @param array<int, string> $stores
     * @return array<int, array<string, mixed>>
     */
    public function ordersForStores(string $tenantKey, array $stores): array
    {
        return $this->orderRepository->ordersForStores($tenantKey, $stores);
    }



    /** @return array<string, mixed>|null */
    public function order(string $tenantKey, int $orderId): ?array
    {
        return $this->orderRepository->order($tenantKey, $orderId);
    }



    /** @return array<int, array<string, mixed>> */
    public function announcements(): array
    {
        return $this->adminRepository->announcements();
    }



    /** @return array<int, array<string, mixed>> */
    public function tenantPlatforms(string $tenantKey): array
    {
        return $this->tenantRepository->tenantPlatforms($tenantKey);
    }



    /** @return array<int, array<string, mixed>> */
    public function tenantFeatures(string $tenantKey): array
    {
        return $this->tenantRepository->tenantFeatures($tenantKey);
    }



    /** @return array<string, mixed> */
    public function tenantBillingAccount(string $tenantKey): array
    {
        return $this->billingRepository->tenantBillingAccount($tenantKey);
    }



    /** @return array<int, array<string, mixed>> */
    public function tenantBillingLedger(string $tenantKey, int $limit = 50): array
    {
        return $this->billingRepository->tenantBillingLedger($tenantKey, $limit);
    }



    /** @return array<int, array<string, mixed>> */
    public function tenantBillingSubscriptions(string $tenantKey): array
    {
        return $this->billingRepository->tenantBillingSubscriptions($tenantKey);
    }



    public function adjustTenantPoints(string $tenantKey, int $amount, string $type, string $note, string $operator): void
    {
        $this->billingRepository->adjustTenantPoints($tenantKey, $amount, $type, $note, $operator);
    }



    public function chargeTenantPoints(string $tenantKey, int $amount, string $note, string $operator): bool
    {
        return $this->billingRepository->chargeTenantPoints($tenantKey, $amount, $note, $operator);
    }



    /** @return array<string, mixed> */
    public function processDueTenantBilling(string $tenantKey, string $operator = 'system'): array
    {
        return $this->billingRepository->processDueTenantBilling($tenantKey, $operator);
    }



    /** @return array<int, array<string, mixed>> */
    public function stores(string $tenantKey): array
    {
        return $this->storeRepository->stores($tenantKey);
    }



    /** @return array<string, mixed>|null */
    public function store(string $tenantKey, int $storeId): ?array
    {
        return $this->storeRepository->store($tenantKey, $storeId);
    }



    /** @param array<string, mixed> $data */
    public function addStore(string $tenantKey, array $data): bool
    {
        return $this->storeRepository->addStore($tenantKey, $data);
    }



    /** @param array<string, mixed> $data */
    public function updateStore(string $tenantKey, int $storeId, array $data): void
    {
        $this->storeRepository->updateStore($tenantKey, $storeId, $data);
    }



    /** @param array<string, mixed> $patch */
    public function mergeStoreApiConfig(string $tenantKey, int $storeId, array $patch, string $apiStatus = '已配置'): void
    {
        $this->storeRepository->mergeStoreApiConfig($tenantKey, $storeId, $patch, $apiStatus);
    }



    /** @return array<int, array<string, mixed>> */
    public function users(string $tenantKey): array
    {
        return $this->userRepository->users($tenantKey);
    }



    /** @return array<string, mixed>|null */
    public function user(string $tenantKey, int $userId): ?array
    {
        return $this->userRepository->user($tenantKey, $userId);
    }



    /** @return array<string, mixed>|null */
    public function tenantUserByUsername(string $tenantKey, string $username): ?array
    {
        return $this->userRepository->tenantUserByUsername($tenantKey, $username);
    }



    public function updateTenantUserPassword(string $tenantKey, int $userId, string $passwordHash): void
    {
        $this->userRepository->updateTenantUserPassword($tenantKey, $userId, $passwordHash);
    }



    /** @param array{allow?: array<int, string>, deny?: array<int, string>} $overrides */
    public function updateUserPermissionOverrides(string $tenantKey, int $userId, array $overrides, string $operator): void
    {
        $this->userRepository->updateUserPermissionOverrides($tenantKey, $userId, $overrides, $operator);
    }



    public function touchTenantUserLogin(string $tenantKey, int $userId): void
    {
        $this->userRepository->touchTenantUserLogin($tenantKey, $userId);
    }



    /** @param array<string, mixed> $data */
    public function addUser(string $tenantKey, array $data): void
    {
        $this->userRepository->addUser($tenantKey, $data);
    }



    /** @param array<string, mixed> $data */
    public function updateUser(string $tenantKey, int $userId, array $data): void
    {
        $this->userRepository->updateUser($tenantKey, $userId, $data);
    }



    /** @return array<int, array<string, mixed>> */
    public function assignments(string $tenantKey): array
    {
        return $this->userRepository->assignments($tenantKey);
    }



    /** @param array<int, int> $supportUserIds */
    public function saveAssignmentByBuyer(string $tenantKey, int $buyerUserId, array $supportUserIds): void
    {
        $this->userRepository->saveAssignmentByBuyer($tenantKey, $buyerUserId, $supportUserIds);
    }



    /** @param array<int, int> $buyerUserIds */
    public function saveAssignmentBySupport(string $tenantKey, int $supportUserId, array $buyerUserIds): void
    {
        $this->userRepository->saveAssignmentBySupport($tenantKey, $supportUserId, $buyerUserIds);
    }



    public function togglePlatform(string $tenantKey, string $platformCode, string $field): void
    {
        $this->tenantRepository->togglePlatform($tenantKey, $platformCode, $field);
    }



    public function toggleTenantFeature(string $tenantKey, string $featureKey): void
    {
        $this->tenantRepository->toggleTenantFeature($tenantKey, $featureKey);
    }



    public function changeItemSource(string $tenantKey, int $itemId, string $source): void
    {
        $this->orderRepository->changeItemSource($tenantKey, $itemId, $source);
    }



    /**
     * @param array<int, int> $itemIds
     * @param array<int, int> $orderIds
     * @param array<string, mixed> $changes
     */
    public function batchUpdateItems(
        string $tenantKey,
        array $itemIds,
        array $orderIds,
        array $changes,
        string $operator = '系统管理员',
        string $action = '批量更新'
    ): void
    {
        $this->orderRepository->batchUpdateItems($tenantKey, $itemIds, $orderIds, $changes, $operator, $action);
    }



    /**
     * @param array<int, int> $itemIds
     */
    public function transitionItemPurchaseStatus(
        string $tenantKey,
        array $itemIds,
        string $fromStatus,
        string $toStatus,
        string $operator = '系统管理员',
        string $action = '状态流转'
    ): int
    {
        return $this->orderRepository->transitionItemPurchaseStatus($tenantKey, $itemIds, $fromStatus, $toStatus, $operator, $action);
    }



    /**
     * @param array<int, int> $itemIds
     */
    public function updateItemsLogistics(string $tenantKey, array $itemIds, string $status, string $action, string $operator): int
    {
        return $this->orderRepository->updateItemsLogistics($tenantKey, $itemIds, $status, $action, $operator);
    }



    /** @param array<int, int> $orderIds */
    public function deleteOrders(string $tenantKey, array $orderIds): void
    {
        $this->orderRepository->deleteOrders($tenantKey, $orderIds);
    }



    /** @param array<string, bool> $flags */
    public function updateOrderFlags(string $tenantKey, int $orderId, array $flags, string $operator): void
    {
        $this->orderRepository->updateOrderFlags($tenantKey, $orderId, $flags, $operator);
    }



    /** @param array<string, mixed> $data */
    public function insertExternalOrder(string $tenantKey, array $data, string $operator): int
    {
        return $this->orderRepository->insertExternalOrder($tenantKey, $data, $operator);
    }



    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array{inserted: int, updated: int, skipped: int, items_inserted: int, items_updated: int}
     */
    public function upsertPlatformOrders(string $tenantKey, array $orders, string $operator): array
    {
        return $this->orderRepository->upsertPlatformOrders($tenantKey, $orders, $operator);
    }



    public function markStoreSync(string $tenantKey, int $storeId, string $status, string $message): void
    {
        $this->storeRepository->markStoreSync($tenantKey, $storeId, $status, $message);
    }



    /** @param array<string, mixed> $data */
    public function updateOrderItem(
        string $tenantKey,
        int $itemId,
        array $data,
        string $operator = '系统管理员',
        string $action = '保存明细'
    ): void
    {
        $this->orderRepository->updateOrderItem($tenantKey, $itemId, $data, $operator, $action);
    }



    /**
     * @return array<int, array<string, mixed>>
     */
    public function purchaseStatusEvents(string $tenantKey, string $date, ?array $user = null, string $platform = ''): array
    {
        return $this->orderRepository->purchaseStatusEvents($tenantKey, $date, $user, $platform);
    }



    /**
     * @param array<int, array<string, mixed>> $records
     * @return array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>}
     */
    public function importPlatformOrders(string $tenantKey, array $records, string $operator): array
    {
        return $this->orderRepository->importPlatformOrders($tenantKey, $records, $operator);
    }



    /**
     * @param array<int, array<string, mixed>> $records
     * @return array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>}
     */
    public function importPurchaseRows(string $tenantKey, array $records, string $operator): array
    {
        return $this->orderRepository->importPurchaseRows($tenantKey, $records, $operator);
    }



    /**
     * @param array<int, array<string, mixed>> $records
     * @return array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>}
     */
    public function importShippingRows(string $tenantKey, array $records, string $operator): array
    {
        return $this->orderRepository->importShippingRows($tenantKey, $records, $operator);
    }



    public function updateOrderItemImage(string $tenantKey, int $itemId, string $kind, string $path): void
    {
        $this->mediaRepository->updateOrderItemImage($tenantKey, $itemId, $kind, $path);
    }



    /** @return array<int, array<string, mixed>> */
    public function orderAttachments(string $tenantKey, int $orderId): array
    {
        return $this->mediaRepository->orderAttachments($tenantKey, $orderId);
    }



    /** @param array<string, mixed> $data */
    public function addOrderAttachment(string $tenantKey, int $orderId, array $data): void
    {
        $this->mediaRepository->addOrderAttachment($tenantKey, $orderId, $data);
    }



    public function deleteOrderAttachment(string $tenantKey, int $orderId, int $attachmentId): void
    {
        $this->mediaRepository->deleteOrderAttachment($tenantKey, $orderId, $attachmentId);
    }



    /** @return array<string, mixed> */
    public function globalSettings(): array
    {
        return $this->adminRepository->globalSettings();
    }



    /** @param array<string, mixed> $data */
    public function saveGlobalSettings(array $data): void
    {
        $this->adminRepository->saveGlobalSettings($data);
    }



    /** @return array<string, mixed> */
    public function tenantSettings(string $tenantKey): array
    {
        return $this->settingsRepository->tenantSettings($tenantKey);
    }



    /** @param array<string, mixed> $data */
    public function saveTenantSettings(string $tenantKey, array $data): void
    {
        $this->settingsRepository->saveTenantSettings($tenantKey, $data);
    }



    /** @return array<int, array<string, mixed>> */
    public function tenantNotices(string $tenantKey): array
    {
        return $this->settingsRepository->tenantNotices($tenantKey);
    }



    /** @return array<string, mixed>|null */
    public function tenantNotice(string $tenantKey, int $noticeId): ?array
    {
        return $this->settingsRepository->tenantNotice($tenantKey, $noticeId);
    }



    /** @param array<string, mixed> $data */
    public function saveTenantNotice(string $tenantKey, array $data): int
    {
        return $this->settingsRepository->saveTenantNotice($tenantKey, $data);
    }



    public function deleteTenantNotice(string $tenantKey, int $noticeId): void
    {
        $this->settingsRepository->deleteTenantNotice($tenantKey, $noticeId);
    }



    public function toggleTenantNoticePinned(string $tenantKey, int $noticeId, bool $pinned): void
    {
        $this->settingsRepository->toggleTenantNoticePinned($tenantKey, $noticeId, $pinned);
    }



    /** @return array<int, array<string, mixed>> */
    public function importExportLogs(string $tenantKey): array
    {
        return $this->settingsRepository->importExportLogs($tenantKey);
    }



    /** @param array<string, mixed> $data */
    public function addImportExportLog(string $tenantKey, array $data): void
    {
        $this->settingsRepository->addImportExportLog($tenantKey, $data);
    }



    /** @return array<int, array<string, mixed>> */
    public function mailAccounts(string $tenantKey): array
    {
        return $this->mailRepository->mailAccounts($tenantKey);
    }



    /** @return array<string, mixed>|null */
    public function mailAccount(string $tenantKey, int $accountId): ?array
    {
        return $this->mailRepository->mailAccount($tenantKey, $accountId);
    }



    /** @param array<string, mixed> $data */
    public function saveMailAccount(string $tenantKey, array $data): int
    {
        return $this->mailRepository->saveMailAccount($tenantKey, $data);
    }



    public function deleteMailAccount(string $tenantKey, int $accountId): void
    {
        $this->mailRepository->deleteMailAccount($tenantKey, $accountId);
    }



    /** @return array<int, array<string, mixed>> */
    public function mailFolders(string $tenantKey, ?int $accountId = null, bool $onlySynced = false): array
    {
        return $this->mailRepository->mailFolders($tenantKey, $accountId, $onlySynced);
    }



    /** @return array<string, mixed>|null */
    public function mailFolder(string $tenantKey, int $folderId): ?array
    {
        return $this->mailRepository->mailFolder($tenantKey, $folderId);
    }



    /** @param array<int, string> $folders */
    public function upsertMailFolders(string $tenantKey, int $accountId, array $folders): void
    {
        $this->mailRepository->upsertMailFolders($tenantKey, $accountId, $folders);
    }



    /** @param array<string, mixed> $data */
    public function updateMailFolder(string $tenantKey, int $folderId, array $data): void
    {
        $this->mailRepository->updateMailFolder($tenantKey, $folderId, $data);
    }



    /** @return array<string, mixed> */
    public function mailFolderCounts(string $tenantKey): array
    {
        return $this->mailRepository->mailFolderCounts($tenantKey);
    }



    /**
     * @param array<string, mixed> $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, page_size: int, total_pages: int}
     */
    public function mailMessages(string $tenantKey, array $filters, int $page, int $pageSize): array
    {
        return $this->mailRepository->mailMessages($tenantKey, $filters, $page, $pageSize);
    }



    /** @return array<string, mixed>|null */
    public function mailMessage(string $tenantKey, int $messageId): ?array
    {
        return $this->mailRepository->mailMessage($tenantKey, $messageId);
    }



    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array{inserted: int, inserted_ids: array<int, int>, max_uid: int}
     */
    public function insertMailMessages(string $tenantKey, int $accountId, int $folderId, array $messages): array
    {
        return $this->mailRepository->insertMailMessages($tenantKey, $accountId, $folderId, $messages);
    }



    /** @param array<string, int> $status */
    public function updateMailFolderAfterSync(string $tenantKey, int $folderId, int $lastUid, int $messageCount, array $status = []): void
    {
        $this->mailRepository->updateMailFolderAfterSync($tenantKey, $folderId, $lastUid, $messageCount, $status);
    }



    public function updateMailAccountLastSync(string $tenantKey, int $accountId): void
    {
        $this->mailRepository->updateMailAccountLastSync($tenantKey, $accountId);
    }



    /** @param array<string, mixed> $body */
    public function saveMailMessageBody(string $tenantKey, int $messageId, array $body): void
    {
        $this->mailRepository->saveMailMessageBody($tenantKey, $messageId, $body);
    }



    /**
     * @param array<int, int> $messageIds
     * @param array<string, mixed> $changes
     */
    public function updateMailMessages(string $tenantKey, array $messageIds, array $changes): int
    {
        return $this->mailRepository->updateMailMessages($tenantKey, $messageIds, $changes);
    }



    /** @return array<int, array<string, mixed>> */
    public function mailRules(string $tenantKey): array
    {
        return $this->mailRepository->mailRules($tenantKey);
    }



    /** @param array<string, mixed> $data */
    public function saveMailRule(string $tenantKey, array $data): int
    {
        return $this->mailRepository->saveMailRule($tenantKey, $data);
    }



    public function deleteMailRule(string $tenantKey, int $ruleId): void
    {
        $this->mailRepository->deleteMailRule($tenantKey, $ruleId);
    }



    /** @param array<string, mixed> $data */
    public function addMailReply(string $tenantKey, array $data): void
    {
        $this->mailRepository->addMailReply($tenantKey, $data);
    }

}
