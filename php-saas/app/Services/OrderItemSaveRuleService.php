<?php

declare(strict_types=1);

namespace Xizhen\Services;

final class OrderItemSaveRuleService
{
    private const SYNC_FIELDS = ['material', 'tranship_comment'];
    private const ITEM_ID_PLATFORMS = ['r' => true, 'y' => true];

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $currentItem
     * @param array<string, mixed>|null $currentUser
     * @return array<string, mixed>
     */
    public function withAutoBuyer(array $changes, array $currentItem, ?array $currentUser): array
    {
        $buyer = $this->autoBuyer($changes, $currentItem, $currentUser);
        if ($buyer !== null) {
            $changes['buyer'] = $buyer;
        }

        return $changes;
    }

    /**
     * Matches the old save handlers: after a save by a purchase user, record the
     * current username when tabaono is non-empty and caigou_user/buyer is empty.
     *
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $currentItem
     * @param array<string, mixed>|null $currentUser
     */
    public function autoBuyer(array $changes, array $currentItem, ?array $currentUser): ?string
    {
        if (!$this->isPurchaseUser($currentUser)) {
            return null;
        }

        if ($this->stringValue($changes['buyer'] ?? '') !== '') {
            return null;
        }

        if ($this->stringValue($currentItem['buyer'] ?? $currentItem['caigou_user'] ?? '') !== '') {
            return null;
        }

        $tabaonoAfterSave = array_key_exists('tabaono', $changes)
            ? $this->stringValue($changes['tabaono'])
            : $this->stringValue($currentItem['tabaono'] ?? '');
        if ($tabaonoAfterSave === '') {
            return null;
        }

        $username = $this->stringValue($currentUser['username'] ?? '');
        if ($username !== '') {
            return $username;
        }

        $name = $this->stringValue($currentUser['name'] ?? $currentUser['display_name'] ?? '');
        return $name !== '' ? $name : null;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $changes
     * @return array{item_ids: array<int, int>, changes: array<string, string>, item_key: string}
     */
    public function sameItemSyncPlan(array $order, int $sourceItemId, array $changes): array
    {
        $syncChanges = $this->sameItemSyncChanges($changes);
        if ($sourceItemId <= 0 || !$syncChanges) {
            return ['item_ids' => [], 'changes' => [], 'item_key' => ''];
        }

        $sourceItem = $this->findItem($order, $sourceItemId);
        if ($sourceItem === null) {
            return ['item_ids' => [], 'changes' => [], 'item_key' => ''];
        }

        $sourceKey = $this->sameItemKey($order, $sourceItem);
        if ($sourceKey === '') {
            return ['item_ids' => [], 'changes' => [], 'item_key' => ''];
        }

        $targetIds = [];
        foreach ($order['items'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemId = (int) ($item['id'] ?? 0);
            if ($itemId <= 0 || $itemId === $sourceItemId) {
                continue;
            }

            if ($this->sameItemKey($order, $item) === $sourceKey) {
                $targetIds[] = $itemId;
            }
        }

        return [
            'item_ids' => array_values(array_unique($targetIds)),
            'changes' => $syncChanges,
            'item_key' => $sourceKey,
        ];
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, string>
     */
    public function sameItemSyncChanges(array $changes): array
    {
        $syncChanges = [];
        foreach (self::SYNC_FIELDS as $field) {
            if (!array_key_exists($field, $changes)) {
                continue;
            }

            $value = $this->stringValue($changes[$field]);
            if ($value !== '') {
                $syncChanges[$field] = $value;
            }
        }

        return $syncChanges;
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>|null
     */
    public function findItem(array $order, int $itemId): ?array
    {
        foreach ($order['items'] ?? [] as $item) {
            if (is_array($item) && (int) ($item['id'] ?? 0) === $itemId) {
                return $item;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $currentUser */
    private function isPurchaseUser(?array $currentUser): bool
    {
        if ($currentUser === null) {
            return false;
        }

        return $this->stringValue($currentUser['role'] ?? $currentUser['usertype'] ?? '') === '采购';
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     */
    private function sameItemKey(array $order, array $item): string
    {
        $platform = strtolower($this->stringValue($order['platform'] ?? ''));

        if (isset(self::ITEM_ID_PLATFORMS[$platform])) {
            $itemId = $this->extraValue($item, ['ItemId', 'itemId']);
            return $itemId !== '' ? $itemId : $this->stringValue($item['item_code'] ?? '');
        }

        $itemCode = $this->stringValue($item['item_code'] ?? '');
        return $itemCode !== '' ? $itemCode : $this->extraValue($item, ['itemCode', 'ItemId', 'itemId']);
    }

    /**
     * @param array<string, mixed> $item
     * @param array<int, string> $keys
     */
    private function extraValue(array $item, array $keys): string
    {
        $extra = $item['platform_extra'] ?? [];
        if (!is_array($extra)) {
            return '';
        }

        foreach ($keys as $key) {
            $value = $this->stringValue($extra[$key] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function stringValue(mixed $value): string
    {
        return trim((string) $value);
    }
}
