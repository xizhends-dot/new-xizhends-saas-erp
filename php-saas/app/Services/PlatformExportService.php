<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

/**
 * 发货单导出统一渲染引擎:模板(预置或租户自定义) + 订单集 → headers/rows。
 * 字段取值逻辑全部在 ExportFieldRegistry;本类只做列遍历、raw 路径、CSV 防注入。
 */
final class PlatformExportService
{
    /**
     * @param array<string, mixed> $template
     * @param array<int, array<string, mixed>> $orders
     * @return array{name: string, filename: string, format: string, headers: array<int, string>, rows: array<int, array<int, mixed>>, imageColumns: array<int, int>}
     */
    public function render(array $template, array $orders): array
    {
        $columns = array_values(array_filter((array) ($template['columns'] ?? []), 'is_array'));
        $format = strtolower((string) ($template['format'] ?? 'csv')) === 'xlsx' ? 'xlsx' : 'csv';
        $fields = ExportFieldRegistry::fields();

        $headers = [];
        $imageColumns = [];
        foreach ($columns as $index => $column) {
            $headers[] = (string) ($column['label'] ?? '');
            if (($column['type'] ?? '') === 'field' && ($fields[(string) ($column['key'] ?? '')]['type'] ?? '') === 'image') {
                $imageColumns[] = $index;
            }
        }

        $rows = [];
        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }
            foreach (array_filter((array) ($order['items'] ?? []), 'is_array') as $item) {
                $row = [];
                foreach ($columns as $column) {
                    $row[] = $this->cellValue($column, $order, $item);
                }
                $rows[] = $row;
            }
        }

        return [
            'name' => (string) ($template['name'] ?? '发货单导出'),
            'filename' => 'shipping-' . date('Ymd-His') . '.' . $format,
            'format' => $format,
            'headers' => $headers,
            'rows' => $this->safeRows($rows),
            'imageColumns' => $imageColumns,
        ];
    }

    /**
     * @param array<string, mixed> $column
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     */
    private function cellValue(array $column, array $order, array $item): mixed
    {
        return match ((string) ($column['type'] ?? '')) {
            'field' => ExportFieldRegistry::resolve((string) ($column['key'] ?? ''), $order, $item),
            'const' => (string) ($column['value'] ?? ''),
            'raw' => $this->rawValue((string) ($column['path'] ?? ''), $order, $item),
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     */
    private function rawValue(string $path, array $order, array $item): string
    {
        $value = null;
        if (str_starts_with($path, 'order.')) {
            $value = $order[substr($path, 6)] ?? null;
        } elseif (str_starts_with($path, 'item.')) {
            $value = $item[substr($path, 5)] ?? null;
        } elseif (str_starts_with($path, 'customer.')) {
            $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
            $value = $customer[substr($path, 9)] ?? null;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /** @param array<int, array<int, mixed>> $rows @return array<int, array<int, mixed>> */
    private function safeRows(array $rows): array
    {
        return array_map(
            fn (array $row): array => array_map(fn (mixed $cell): mixed => $this->safeCell($cell), $row),
            $rows
        );
    }

    private function safeCell(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : $value;
    }

    /** @deprecated Task 5 切换到模板机制后删除。 @return array<string, array{name: string, source: string, platform: string, note: string}> */
    public function variants(): array
    {
        $meta = [];
        foreach ($this->templateService()->builtinTemplates() as $template) {
            $key = substr((string) $template['id'], strlen('builtin_'));
            $meta[$key] = ['name' => (string) $template['name'], 'source' => 'builtin', 'platform' => '', 'note' => '预置模板(过渡兼容)'];
        }

        return $meta;
    }

    /** @deprecated Task 5 切换到模板机制后删除。 */
    public function exportDataset(string $tenantKey, string $variant, array $orders, array $options = []): array
    {
        $service = $this->templateService();
        $template = $service->find($tenantKey, $service->fromLegacyVariant(strtolower(trim($variant))) ?? 'builtin_riya')
            ?? $service->builtinTemplates()[0];
        $template['format'] = 'csv';
        $dataset = $this->render($template, $orders);
        $dataset['source'] = 'builtin';
        $dataset['note'] = '预置模板(过渡兼容)';

        return $dataset;
    }

    private function templateService(): ExportTemplateService
    {
        return new ExportTemplateService(new class implements StoreInterface {
            /** @return array<string, mixed> */
            public function all(): array
            {
                return [];
            }

            /** @return array<int, array<string, mixed>> */
            public function tenants(): array
            {
                return [];
            }

            /** @return array<string, mixed>|null */
            public function adminByUsername(string $username): ?array
            {
                return null;
            }

            public function touchAdminLogin(int $adminId): void
            {
            }

            /** @return array<string, mixed> */
            public function tenant(string $key): array
            {
                return ['key' => $key];
            }

            /** @return array<int, array<string, mixed>> */
            public function platforms(): array
            {
                return [];
            }

            /** @return array<int, array<string, mixed>> */
            public function orders(string $tenantKey): array
            {
                return [];
            }

            /** @param array<int, string> $stores @return array<int, array<string, mixed>> */
            public function ordersForStores(string $tenantKey, array $stores): array
            {
                return [];
            }

            /** @return array<string, mixed>|null */
            public function order(string $tenantKey, int $orderId): ?array
            {
                return null;
            }

            /** @return array<int, array<string, mixed>> */
            public function announcements(): array
            {
                return [];
            }

            /** @return array<int, array<string, mixed>> */
            public function tenantPlatforms(string $tenantKey): array
            {
                return [];
            }

            /** @return array<int, array<string, mixed>> */
            public function tenantFeatures(string $tenantKey): array
            {
                return [];
            }

            /** @return array<string, mixed> */
            public function tenantBillingAccount(string $tenantKey): array
            {
                return [];
            }

            /** @return array<int, array<string, mixed>> */
            public function tenantBillingLedger(string $tenantKey, int $limit = 50): array
            {
                return [];
            }

            /** @return array<int, array<string, mixed>> */
            public function tenantBillingSubscriptions(string $tenantKey): array
            {
                return [];
            }

            public function adjustTenantPoints(string $tenantKey, int $amount, string $type, string $note, string $operator): void
            {
            }

            public function chargeTenantPoints(string $tenantKey, int $amount, string $note, string $operator): bool
            {
                return false;
            }

            /** @return array<string, mixed> */
            public function processDueTenantBilling(string $tenantKey, string $operator = 'system'): array
            {
                return [];
            }

            /** @return array<int, array<string, mixed>> */
            public function stores(string $tenantKey): array
            {
                return [];
            }

            /** @return array<string, mixed>|null */
            public function store(string $tenantKey, int $storeId): ?array
            {
                return null;
            }

            /** @param array<string, mixed> $data */
            public function addStore(string $tenantKey, array $data): bool
            {
                return false;
            }

            /** @param array<string, mixed> $data */
            public function updateStore(string $tenantKey, int $storeId, array $data): void
            {
            }

            /** @param array<string, mixed> $patch */
            public function mergeStoreApiConfig(string $tenantKey, int $storeId, array $patch, string $apiStatus = '已配置'): void
            {
            }

            /** @return array<int, array<string, mixed>> */
            public function users(string $tenantKey): array
            {
                return [];
            }

            /** @return array<string, mixed>|null */
            public function user(string $tenantKey, int $userId): ?array
            {
                return null;
            }

            /** @return array<string, mixed>|null */
            public function tenantUserByUsername(string $tenantKey, string $username): ?array
            {
                return null;
            }

            public function updateTenantUserPassword(string $tenantKey, int $userId, string $passwordHash): void
            {
            }

            public function touchTenantUserLogin(string $tenantKey, int $userId): void
            {
            }

            /** @param array<string, mixed> $data */
            public function addUser(string $tenantKey, array $data): void
            {
            }

            /** @param array<string, mixed> $data */
            public function updateUser(string $tenantKey, int $userId, array $data): void
            {
            }

            /** @param array{allow?: array<int, string>, deny?: array<int, string>} $overrides */
            public function updateUserPermissionOverrides(string $tenantKey, int $userId, array $overrides, string $operator): void
            {
            }

            /** @return array<int, array<string, mixed>> */
            public function assignments(string $tenantKey): array
            {
                return [];
            }

            /** @param array<int, int> $supportUserIds */
            public function saveAssignmentByBuyer(string $tenantKey, int $buyerUserId, array $supportUserIds): void
            {
            }

            /** @param array<int, int> $buyerUserIds */
            public function saveAssignmentBySupport(string $tenantKey, int $supportUserId, array $buyerUserIds): void
            {
            }

            public function togglePlatform(string $tenantKey, string $platformCode, string $field): void
            {
            }

            public function toggleTenantFeature(string $tenantKey, string $featureKey): void
            {
            }

            public function changeItemSource(string $tenantKey, int $itemId, string $source): void
            {
            }

            /** @param array<int, int> $itemIds @param array<int, int> $orderIds @param array<string, mixed> $changes */
            public function batchUpdateItems(string $tenantKey, array $itemIds, array $orderIds, array $changes, string $operator = '系统管理员', string $action = '批量更新'): void
            {
            }

            /** @param array<int, int> $itemIds */
            public function transitionItemPurchaseStatus(string $tenantKey, array $itemIds, string $fromStatus, string $toStatus, string $operator = '系统管理员', string $action = '状态流转'): int
            {
                return 0;
            }

            /** @param array<int, int> $itemIds */
            public function updateItemsLogistics(string $tenantKey, array $itemIds, string $status, string $action, string $operator): int
            {
                return 0;
            }

            /** @param array<int, int> $orderIds */
            public function deleteOrders(string $tenantKey, array $orderIds): void
            {
            }

            /** @param array<string, bool> $flags */
            public function updateOrderFlags(string $tenantKey, int $orderId, array $flags, string $operator): void
            {
            }

            /** @param array<string, mixed> $data */
            public function insertExternalOrder(string $tenantKey, array $data, string $operator): int
            {
                return 0;
            }

            /** @param array<int, array<string, mixed>> $orders @return array{inserted: int, updated: int, skipped: int, items_inserted: int, items_updated: int} */
            public function upsertPlatformOrders(string $tenantKey, array $orders, string $operator): array
            {
                return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'items_inserted' => 0, 'items_updated' => 0];
            }

            public function markStoreSync(string $tenantKey, int $storeId, string $status, string $message): void
            {
            }

            /** @param array<string, mixed> $data */
            public function updateOrderItem(string $tenantKey, int $itemId, array $data, string $operator = '系统管理员', string $action = '保存明细'): void
            {
            }

            /** @return array<int, array<string, mixed>> */
            public function purchaseStatusEvents(string $tenantKey, string $date, ?array $user = null, string $platform = ''): array
            {
                return [];
            }

            /** @param array<int, array<string, mixed>> $records @return array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>} */
            public function importPlatformOrders(string $tenantKey, array $records, string $operator): array
            {
                return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'messages' => []];
            }

            /** @param array<int, array<string, mixed>> $records @return array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>} */
            public function importPurchaseRows(string $tenantKey, array $records, string $operator): array
            {
                return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'messages' => []];
            }

            /** @param array<int, array<string, mixed>> $records @return array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>} */
            public function importShippingRows(string $tenantKey, array $records, string $operator): array
            {
                return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'messages' => []];
            }

            public function updateOrderItemImage(string $tenantKey, int $itemId, string $kind, string $path): void
            {
            }

            /** @return array<int, array<string, mixed>> */
            public function orderAttachments(string $tenantKey, int $orderId): array
            {
                return [];
            }

            /** @param array<string, mixed> $data */
            public function addOrderAttachment(string $tenantKey, int $orderId, array $data): void
            {
            }

            public function deleteOrderAttachment(string $tenantKey, int $orderId, int $attachmentId): void
            {
            }

            /** @return array<string, mixed> */
            public function globalSettings(): array
            {
                return [];
            }

            /** @param array<string, mixed> $data */
            public function saveGlobalSettings(array $data): void
            {
            }

            /** @return array<string, mixed> */
            public function tenantSettings(string $tenantKey): array
            {
                return ['export_templates' => []];
            }

            /** @param array<string, mixed> $data */
            public function saveTenantSettings(string $tenantKey, array $data): void
            {
            }

            /** @return array<int, array<string, mixed>> */
            public function tenantNotices(string $tenantKey): array
            {
                return [];
            }

            /** @return array<string, mixed>|null */
            public function tenantNotice(string $tenantKey, int $noticeId): ?array
            {
                return null;
            }

            /** @param array<string, mixed> $data */
            public function saveTenantNotice(string $tenantKey, array $data): int
            {
                return 0;
            }

            public function deleteTenantNotice(string $tenantKey, int $noticeId): void
            {
            }

            public function toggleTenantNoticePinned(string $tenantKey, int $noticeId, bool $pinned): void
            {
            }

            /** @return array<int, array<string, mixed>> */
            public function importExportLogs(string $tenantKey): array
            {
                return [];
            }

            /** @param array<string, mixed> $data */
            public function addImportExportLog(string $tenantKey, array $data): void
            {
            }

            /** @return array<int, array<string, mixed>> */
            public function mailAccounts(string $tenantKey): array
            {
                return [];
            }

            /** @return array<string, mixed>|null */
            public function mailAccount(string $tenantKey, int $accountId): ?array
            {
                return null;
            }

            /** @param array<string, mixed> $data */
            public function saveMailAccount(string $tenantKey, array $data): int
            {
                return 0;
            }

            public function deleteMailAccount(string $tenantKey, int $accountId): void
            {
            }

            /** @return array<int, array<string, mixed>> */
            public function mailFolders(string $tenantKey, ?int $accountId = null, bool $onlySynced = false): array
            {
                return [];
            }

            /** @return array<string, mixed>|null */
            public function mailFolder(string $tenantKey, int $folderId): ?array
            {
                return null;
            }

            /** @param array<int, string> $folders */
            public function upsertMailFolders(string $tenantKey, int $accountId, array $folders): void
            {
            }

            /** @param array<string, mixed> $data */
            public function updateMailFolder(string $tenantKey, int $folderId, array $data): void
            {
            }

            /** @return array<string, mixed> */
            public function mailFolderCounts(string $tenantKey): array
            {
                return [];
            }

            /** @param array<string, mixed> $filters @return array{rows: array<int, array<string, mixed>>, total: int, page: int, page_size: int, total_pages: int} */
            public function mailMessages(string $tenantKey, array $filters, int $page, int $pageSize): array
            {
                return ['rows' => [], 'total' => 0, 'page' => $page, 'page_size' => $pageSize, 'total_pages' => 0];
            }

            /** @return array<string, mixed>|null */
            public function mailMessage(string $tenantKey, int $messageId): ?array
            {
                return null;
            }

            /** @param array<int, array<string, mixed>> $messages @return array{inserted: int, inserted_ids: array<int, int>, max_uid: int} */
            public function insertMailMessages(string $tenantKey, int $accountId, int $folderId, array $messages): array
            {
                return ['inserted' => 0, 'inserted_ids' => [], 'max_uid' => 0];
            }

            /** @param array<string, int> $status */
            public function updateMailFolderAfterSync(string $tenantKey, int $folderId, int $lastUid, int $messageCount, array $status = []): void
            {
            }

            public function updateMailAccountLastSync(string $tenantKey, int $accountId): void
            {
            }

            /** @param array<string, mixed> $body */
            public function saveMailMessageBody(string $tenantKey, int $messageId, array $body): void
            {
            }

            /** @param array<int, int> $messageIds @param array<string, mixed> $changes */
            public function updateMailMessages(string $tenantKey, array $messageIds, array $changes): int
            {
                return 0;
            }

            /** @return array<int, array<string, mixed>> */
            public function mailRules(string $tenantKey): array
            {
                return [];
            }

            /** @param array<string, mixed> $data */
            public function saveMailRule(string $tenantKey, array $data): int
            {
                return 0;
            }

            public function deleteMailRule(string $tenantKey, int $ruleId): void
            {
            }

            /** @param array<string, mixed> $data */
            public function addMailReply(string $tenantKey, array $data): void
            {
            }
        });
    }
}
