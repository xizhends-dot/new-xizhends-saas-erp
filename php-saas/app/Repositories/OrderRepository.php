<?php

declare(strict_types=1);

namespace Xizhen\Repositories;

final class OrderRepository
{
    public function __construct(
        private readonly OrderQueryRepository $queryRepository,
        private readonly OrderMutationRepository $mutationRepository,
        private readonly OrderImportRepository $importRepository
    ) {
    }



    /** @return array<int, array<string, mixed>> */
    public function orders(string $tenantKey): array
    {
        return $this->queryRepository->orders($tenantKey);
    }



    /** @return array<int, array<string, mixed>> */
    public function ordersByYear(string $tenantKey, int $year): array
    {
        return $this->queryRepository->ordersByYear($tenantKey, $year);
    }



    /**
     * @param array<int, string> $stores
     * @return array<int, array<string, mixed>>
     */
    public function ordersForStores(string $tenantKey, array $stores): array
    {
        return $this->queryRepository->ordersForStores($tenantKey, $stores);
    }



    /** @return array<string, mixed>|null */
    public function order(string $tenantKey, int $orderId): ?array
    {
        return $this->queryRepository->order($tenantKey, $orderId);
    }



    /**
     * @return array<int, array<string, mixed>>
     */
    public function purchaseStatusEvents(string $tenantKey, string $date, ?array $user = null, string $platform = ''): array
    {
        return $this->queryRepository->purchaseStatusEvents($tenantKey, $date, $user, $platform);
    }



    public function changeItemSource(string $tenantKey, int $itemId, string $source): void
    {
        $this->mutationRepository->changeItemSource($tenantKey, $itemId, $source);
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
        $this->mutationRepository->batchUpdateItems($tenantKey, $itemIds, $orderIds, $changes, $operator, $action);
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
        return $this->mutationRepository->transitionItemPurchaseStatus($tenantKey, $itemIds, $fromStatus, $toStatus, $operator, $action);
    }



    /**
     * @param array<int, int> $itemIds
     */
    public function updateItemsLogistics(string $tenantKey, array $itemIds, string $status, string $action, string $operator): int
    {
        return $this->mutationRepository->updateItemsLogistics($tenantKey, $itemIds, $status, $action, $operator);
    }



    /** @param array<int, int> $orderIds */
    public function deleteOrders(string $tenantKey, array $orderIds): void
    {
        $this->mutationRepository->deleteOrders($tenantKey, $orderIds);
    }



    /** @param array<string, bool> $flags */
    public function updateOrderFlags(string $tenantKey, int $orderId, array $flags, string $operator): void
    {
        $this->mutationRepository->updateOrderFlags($tenantKey, $orderId, $flags, $operator);
    }



    /** @param array<string, mixed> $data */
    public function insertExternalOrder(string $tenantKey, array $data, string $operator): int
    {
        return $this->mutationRepository->insertExternalOrder($tenantKey, $data, $operator);
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
        $this->mutationRepository->updateOrderItem($tenantKey, $itemId, $data, $operator, $action);
    }



    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array{inserted: int, updated: int, skipped: int, items_inserted: int, items_updated: int}
     */
    public function upsertPlatformOrders(string $tenantKey, array $orders, string $operator): array
    {
        return $this->importRepository->upsertPlatformOrders($tenantKey, $orders, $operator);
    }



    /**
     * @param array<int, array<string, mixed>> $records
     * @return array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>}
     */
    public function importPlatformOrders(string $tenantKey, array $records, string $operator): array
    {
        return $this->importRepository->importPlatformOrders($tenantKey, $records, $operator);
    }



    /**
     * @param array<int, array<string, mixed>> $records
     * @return array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>}
     */
    public function importPurchaseRows(string $tenantKey, array $records, string $operator): array
    {
        return $this->importRepository->importPurchaseRows($tenantKey, $records, $operator);
    }



    /**
     * @param array<int, array<string, mixed>> $records
     * @return array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>}
     */
    public function importShippingRows(string $tenantKey, array $records, string $operator): array
    {
        return $this->importRepository->importShippingRows($tenantKey, $records, $operator);
    }
}
