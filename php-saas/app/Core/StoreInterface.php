<?php

declare(strict_types=1);

namespace Xizhen\Core;

interface StoreInterface
{
    /** @return array<string, mixed> */
    public function all(): array;

    /** @return array<int, array<string, mixed>> */
    public function tenants(): array;

    /** @return array<string, mixed>|null */
    public function adminByUsername(string $username): ?array;

    public function touchAdminLogin(int $adminId): void;

    /** @return array<string, mixed> */
    public function tenant(string $key): array;

    /** @return array<int, array<string, mixed>> */
    public function platforms(): array;

    /** @return array<int, array<string, mixed>> */
    public function orders(string $tenantKey): array;

    /**
     * @param array<int, string> $stores
     * @return array<int, array<string, mixed>>
     */
    public function ordersForStores(string $tenantKey, array $stores): array;

    /** @return array<string, mixed>|null */
    public function order(string $tenantKey, int $orderId): ?array;

    /** @return array<int, array<string, mixed>> */
    public function announcements(): array;

    /** @return array<int, array<string, mixed>> */
    public function tenantPlatforms(string $tenantKey): array;

    /** @return array<int, array<string, mixed>> */
    public function tenantFeatures(string $tenantKey): array;

    /** @return array<string, mixed> */
    public function tenantBillingAccount(string $tenantKey): array;

    /** @return array<int, array<string, mixed>> */
    public function tenantBillingLedger(string $tenantKey, int $limit = 50): array;

    /** @return array<int, array<string, mixed>> */
    public function tenantBillingSubscriptions(string $tenantKey): array;

    public function adjustTenantPoints(string $tenantKey, int $amount, string $type, string $note, string $operator): void;

    public function chargeTenantPoints(string $tenantKey, int $amount, string $note, string $operator): bool;

    /** @return array<string, mixed> */
    public function processDueTenantBilling(string $tenantKey, string $operator = 'system'): array;

    /** @return array<int, array<string, mixed>> */
    public function stores(string $tenantKey): array;

    /** @return array<string, mixed>|null */
    public function store(string $tenantKey, int $storeId): ?array;

    /** @param array<string, mixed> $data */
    public function addStore(string $tenantKey, array $data): bool;

    /** @param array<string, mixed> $data */
    public function updateStore(string $tenantKey, int $storeId, array $data): void;

    /** @return array<int, array<string, mixed>> */
    public function users(string $tenantKey): array;

    /** @return array<string, mixed>|null */
    public function user(string $tenantKey, int $userId): ?array;

    /** @return array<string, mixed>|null */
    public function tenantUserByUsername(string $tenantKey, string $username): ?array;

    public function updateTenantUserPassword(string $tenantKey, int $userId, string $passwordHash): void;

    public function touchTenantUserLogin(string $tenantKey, int $userId): void;

    /** @param array<string, mixed> $data */
    public function addUser(string $tenantKey, array $data): void;

    /** @param array<string, mixed> $data */
    public function updateUser(string $tenantKey, int $userId, array $data): void;

    /** @return array<int, array<string, mixed>> */
    public function assignments(string $tenantKey): array;

    /**
     * @param array<int, int> $supportUserIds
     */
    public function saveAssignmentByBuyer(string $tenantKey, int $buyerUserId, array $supportUserIds): void;

    /**
     * @param array<int, int> $buyerUserIds
     */
    public function saveAssignmentBySupport(string $tenantKey, int $supportUserId, array $buyerUserIds): void;

    public function togglePlatform(string $tenantKey, string $platformCode, string $field): void;

    public function toggleTenantFeature(string $tenantKey, string $featureKey): void;

    public function changeItemSource(string $tenantKey, int $itemId, string $source): void;

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
    ): void;

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
    ): int;

    /**
     * @param array<int, int> $itemIds
     */
    public function updateItemsLogistics(string $tenantKey, array $itemIds, string $status, string $action, string $operator): int;

    /** @param array<int, int> $orderIds */
    public function deleteOrders(string $tenantKey, array $orderIds): void;

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array{inserted: int, updated: int, skipped: int, items_inserted: int, items_updated: int}
     */
    public function upsertPlatformOrders(string $tenantKey, array $orders, string $operator): array;

    public function markStoreSync(string $tenantKey, int $storeId, string $status, string $message): void;

    /**
     * @param array<string, mixed> $data Supported old-detail compatible item fields include:
     * source_type, purchase_status, buyer, purchase_time, purchase_link, buhuo_link,
     * amount, cn_amount, com_amount, tabaono, caigou_ordernums, ship_company,
     * ship_number, logistics, logistic_trace, material, weight, chinese_option,
     * comment, tranship_comment, assignee, out_status, jp_warehouse_id,
     * intl_number, intl_fee, intl_qty, intl_weight, intl_comment.
     */
    public function updateOrderItem(
        string $tenantKey,
        int $itemId,
        array $data,
        string $operator = '系统管理员',
        string $action = '保存明细'
    ): void;

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>}
     */
    public function importPlatformOrders(string $tenantKey, array $records, string $operator): array;

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>}
     */
    public function importPurchaseRows(string $tenantKey, array $records, string $operator): array;

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>}
     */
    public function importShippingRows(string $tenantKey, array $records, string $operator): array;

    public function updateOrderItemImage(string $tenantKey, int $itemId, string $kind, string $path): void;

    /** @return array<int, array<string, mixed>> */
    public function orderAttachments(string $tenantKey, int $orderId): array;

    /** @param array<string, mixed> $data */
    public function addOrderAttachment(string $tenantKey, int $orderId, array $data): void;

    public function deleteOrderAttachment(string $tenantKey, int $attachmentId): void;

    /** @return array<string, mixed> */
    public function globalSettings(): array;

    /** @param array<string, mixed> $data */
    public function saveGlobalSettings(array $data): void;

    /** @return array<string, mixed> */
    public function tenantSettings(string $tenantKey): array;

    /** @param array<string, mixed> $data */
    public function saveTenantSettings(string $tenantKey, array $data): void;

    /** @return array<int, array<string, mixed>> */
    public function importExportLogs(string $tenantKey): array;

    /** @param array<string, mixed> $data */
    public function addImportExportLog(string $tenantKey, array $data): void;

    /** @return array<int, array<string, mixed>> */
    public function mailAccounts(string $tenantKey): array;

    /** @return array<string, mixed>|null */
    public function mailAccount(string $tenantKey, int $accountId): ?array;

    /** @param array<string, mixed> $data */
    public function saveMailAccount(string $tenantKey, array $data): int;

    public function deleteMailAccount(string $tenantKey, int $accountId): void;

    /** @return array<int, array<string, mixed>> */
    public function mailFolders(string $tenantKey, ?int $accountId = null, bool $onlySynced = false): array;

    /** @return array<string, mixed>|null */
    public function mailFolder(string $tenantKey, int $folderId): ?array;

    /** @param array<int, string> $folders */
    public function upsertMailFolders(string $tenantKey, int $accountId, array $folders): void;

    /** @param array<string, mixed> $data */
    public function updateMailFolder(string $tenantKey, int $folderId, array $data): void;

    /** @return array<string, mixed> */
    public function mailFolderCounts(string $tenantKey): array;

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, page_size: int, total_pages: int}
     */
    public function mailMessages(string $tenantKey, array $filters, int $page, int $pageSize): array;

    /** @return array<string, mixed>|null */
    public function mailMessage(string $tenantKey, int $messageId): ?array;

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array{inserted: int, inserted_ids: array<int, int>, max_uid: int}
     */
    public function insertMailMessages(string $tenantKey, int $accountId, int $folderId, array $messages): array;

    /** @param array<string, int> $status */
    public function updateMailFolderAfterSync(string $tenantKey, int $folderId, int $lastUid, int $messageCount, array $status = []): void;

    public function updateMailAccountLastSync(string $tenantKey, int $accountId): void;

    /** @param array<string, mixed> $body */
    public function saveMailMessageBody(string $tenantKey, int $messageId, array $body): void;

    /**
     * @param array<int, int> $messageIds
     * @param array<string, mixed> $changes
     */
    public function updateMailMessages(string $tenantKey, array $messageIds, array $changes): int;

    /** @return array<int, array<string, mixed>> */
    public function mailRules(string $tenantKey): array;

    /** @param array<string, mixed> $data */
    public function saveMailRule(string $tenantKey, array $data): int;

    public function deleteMailRule(string $tenantKey, int $ruleId): void;

    /** @param array<string, mixed> $data */
    public function addMailReply(string $tenantKey, array $data): void;
}
