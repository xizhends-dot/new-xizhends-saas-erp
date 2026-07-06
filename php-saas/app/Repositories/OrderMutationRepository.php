<?php

declare(strict_types=1);

namespace Xizhen\Repositories;

final class OrderMutationRepository extends BaseRepository
{


    public function changeItemSource(string $tenantKey, int $itemId, string $source): void
    {
        if (!in_array($source, ['cn_purchase', 'jp_stock', 'pending'], true)) {
            return;
        }

        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo) {
            return;
        }

        $item = $this->itemSnapshot($tenantPdo, $itemId);
        if (!$item || (string) $item['source_type'] === $source) {
            return;
        }

        $status = match ($source) {
            'cn_purchase' => '国内采购-准备',
            'jp_stock' => '日本库存订单',
            default => '待处理',
        };

        $tenantPdo->beginTransaction();
        try {
            $update = $tenantPdo->prepare('UPDATE order_items SET source_type = ?, purchase_status = ? WHERE id = ?');
            $update->execute([$source, $status, $itemId]);

            if ($source === 'cn_purchase') {
                $this->ensureChildRow($tenantPdo, 'purchases', $itemId);
            }
            if ($source === 'jp_stock') {
                $this->ensureChildRow($tenantPdo, 'jp_shipments', $itemId);
            }

            $log = $tenantPdo->prepare(
                'INSERT INTO order_logs (order_id, order_item_id, operator, action_type, field_name, old_value, new_value, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $log->execute([
                (int) $item['order_id'],
                $itemId,
                '系统管理员',
                '货源改判',
                'source_type',
                (string) $item['source_type'],
                $source,
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
            if ((string) ($item['purchase_status'] ?? '') !== $status) {
                $oldStatus = (string) ($item['purchase_status'] ?? '');
                $this->insertItemLog($tenantPdo, (int) $item['order_id'], $itemId, '货源改判', 'purchase_status', $oldStatus, $status);
                $item['purchase_status'] = $status;
                $this->recordPurchaseStatusEvent($tenantPdo, $item, $oldStatus, $status, '货源改判', '系统管理员');
            }

            $tenantPdo->commit();
        } catch (\Throwable $error) {
            $tenantPdo->rollBack();
            throw $error;
        }
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
    ): void {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo) {
            return;
        }

        $ids = $this->resolveItemIds($tenantPdo, $itemIds, $orderIds);
        foreach ($ids as $id) {
            $this->updateOrderItemData($tenantPdo, $id, $changes, $action, $operator);
        }
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
    ): int {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        $fromStatus = trim($fromStatus);
        $toStatus = trim($toStatus);
        if (!$tenantPdo || !$itemIds || $fromStatus === '' || $toStatus === '' || $fromStatus === $toStatus) {
            return 0;
        }

        $updated = 0;
        $tenantPdo->beginTransaction();
        try {
            $update = $tenantPdo->prepare('UPDATE order_items SET purchase_status = ? WHERE id = ? AND purchase_status = ?');
            foreach ($itemIds as $itemId) {
                $snapshot = $this->itemSnapshot($tenantPdo, $itemId);
                if (!$snapshot || (string) ($snapshot['purchase_status'] ?? '') !== $fromStatus) {
                    continue;
                }

                $update->execute([$toStatus, $itemId, $fromStatus]);
                if ($update->rowCount() <= 0) {
                    continue;
                }

                $this->insertItemLog($tenantPdo, (int) $snapshot['order_id'], $itemId, $action, 'purchase_status', $fromStatus, $toStatus, $operator);
                $snapshot['purchase_status'] = $toStatus;
                $this->recordPurchaseStatusEvent($tenantPdo, $snapshot, $fromStatus, $toStatus, $action, $operator);
                $updated++;
            }
            $tenantPdo->commit();
        } catch (\Throwable $error) {
            $tenantPdo->rollBack();
            throw $error;
        }

        return $updated;
    }



    /**
     * @param array<int, int> $itemIds
     */
    public function updateItemsLogistics(string $tenantKey, array $itemIds, string $status, string $action, string $operator): int
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if (!$tenantPdo || !$itemIds) {
            return 0;
        }

        $status = trim($status);
        $action = trim($action) ?: '物流更新';
        $operator = trim($operator) ?: '系统';
        $updated = 0;

        foreach ($itemIds as $itemId) {
            $snapshot = $this->itemSnapshot($tenantPdo, $itemId);
            if (!$snapshot) {
                continue;
            }

            $oldValue = (string) ($snapshot['logistics'] ?? ($snapshot['jpship_status'] ?? ''));
            if ($oldValue === $status) {
                continue;
            }

            $this->ensureChildRow($tenantPdo, 'domestic_shipments', $itemId);
            $stmt = $tenantPdo->prepare('UPDATE domestic_shipments SET jpship_status = ? WHERE order_item_id = ?');
            $stmt->execute([$status, $itemId]);
            $this->insertItemLog(
                $tenantPdo,
                (int) $snapshot['order_id'],
                $itemId,
                $action,
                'logistics',
                $oldValue,
                $status,
                $operator
            );
            $updated++;
        }

        return $updated;
    }



    /** @param array<int, int> $orderIds */
    public function deleteOrders(string $tenantKey, array $orderIds): void
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if (!$tenantPdo || !$orderIds) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $stmt = $tenantPdo->prepare("DELETE FROM orders WHERE id IN ({$placeholders})");
        $stmt->execute($orderIds);
    }



    /** @param array<string, bool> $flags */
    public function updateOrderFlags(string $tenantKey, int $orderId, array $flags, string $operator): void
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo || $orderId <= 0) {
            return;
        }

        $sets = [];
        $params = [];
        foreach (['review_invited', 'reviewed'] as $field) {
            if (!array_key_exists($field, $flags) || !$this->columnExists($tenantPdo, 'orders', $field)) {
                continue;
            }
            $sets[] = "{$field} = ?";
            $params[] = !empty($flags[$field]) ? 1 : 0;
        }
        if (!$sets) {
            return;
        }

        $params[] = $orderId;
        $stmt = $tenantPdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
        $this->insertItemLog($tenantPdo, $orderId, null, '评价状态切换', 'review', '', json_encode($flags, JSON_UNESCAPED_UNICODE), $operator);
    }



    /** @param array<string, mixed> $data */
    public function insertExternalOrder(string $tenantKey, array $data, string $operator): int
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo || !$this->tableExists($tenantPdo, 'orders') || !$this->tableExists($tenantPdo, 'order_items')) {
            return 0;
        }

        $platform = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($data['platform'] ?? 'external')) ?: 'external';
        $platformOrderId = trim((string) ($data['platform_order_id'] ?? ''));
        $tracking = trim((string) ($data['tracking'] ?? ''));
        $storeId = max(0, (int) ($data['store_id'] ?? 0));
        if ($platformOrderId === '' || $tracking === '' || $storeId <= 0) {
            return 0;
        }

        $stmt = $tenantPdo->prepare('SELECT id FROM orders WHERE platform = ? AND store_id = ? AND platform_order_id = ? LIMIT 1');
        $stmt->execute([$platform, $storeId, $platformOrderId]);
        $existing = (int) ($stmt->fetchColumn() ?: 0);
        if ($existing > 0) {
            return $existing;
        }

        $orderColumns = [];
        $orderValues = [];
        $orderParams = [];
        foreach ([
            'platform' => $platform,
            'store_id' => $storeId,
            'platform_order_id' => $platformOrderId,
            'order_date' => date('Y-m-d H:i:s'),
            'imported_at' => date('Y-m-d H:i:s'),
            'status' => '外部插入',
        ] as $column => $value) {
            if ($this->columnExists($tenantPdo, 'orders', $column)) {
                $orderColumns[] = $column;
                $orderValues[] = '?';
                $orderParams[] = $value;
            }
        }
        if (!$orderColumns) {
            return 0;
        }

        $tenantPdo->prepare('INSERT INTO orders (' . implode(', ', $orderColumns) . ') VALUES (' . implode(', ', $orderValues) . ')')->execute($orderParams);
        $orderId = (int) $tenantPdo->lastInsertId();

        $itemColumns = ['order_id'];
        $itemValues = ['?'];
        $itemParams = [$orderId];
        foreach ([
            'item_code' => trim((string) ($data['item_code'] ?? '')),
            'product_title' => '外部插入订单',
            'quantity' => max(1, (int) ($data['quantity'] ?? 1)),
            'source_type' => 'pending',
            'purchase_status' => '外部插入',
        ] as $column => $value) {
            if ($this->columnExists($tenantPdo, 'order_items', $column)) {
                $itemColumns[] = $column;
                $itemValues[] = '?';
                $itemParams[] = $value;
            }
        }
        $tenantPdo->prepare('INSERT INTO order_items (' . implode(', ', $itemColumns) . ') VALUES (' . implode(', ', $itemValues) . ')')->execute($itemParams);
        $itemId = (int) $tenantPdo->lastInsertId();
        $this->writeItemField($tenantPdo, $itemId, 'ship_number', $tracking);
        $this->writeItemField($tenantPdo, $itemId, 'intl_number', $tracking);
        $this->insertItemLog($tenantPdo, $orderId, $itemId, '外部插入订单', 'platform_order_id', '-', $platformOrderId, $operator);

        return $orderId;
    }



    /** @param array<string, mixed> $data */
    public function updateOrderItem(
        string $tenantKey,
        int $itemId,
        array $data,
        string $operator = '系统管理员',
        string $action = '保存明细'
    ): void {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo || $itemId <= 0) {
            return;
        }

        $this->updateOrderItemData($tenantPdo, $itemId, $data, $action, $operator);
    }



    /** @return array<string, mixed>|null */
    public function itemSnapshot(\PDO $pdo, int $itemId): ?array
    {
        $stmt = $pdo->prepare(<<<'SQL'
SELECT i.id, i.order_id, o.platform, o.platform_order_id, COALESCE(NULLIF(s.dpquancheng, ''), s.dpqz, '') AS store_name,
       i.source_type, i.purchase_status, i.jp_warehouse_id, i.amount, i.item_code,
       i.material, i.weight, i.chinese_option, i.item_comment AS comment,
       p.tabaono, p.caigou_link AS purchase_link, p.buhuo_link, p.caigou_user AS buyer,
       p.caigou_time AS purchase_time, p.caigou_ordernums, p.cn_amount, p.com_amount, p.cn_ship_number,
       j.out_status, j.assignee,
       d.ship_company, COALESCE(NULLIF(p.cn_ship_number, ''), d.ship_number) AS ship_number,
       d.ship_quantity, d.receipt_city, d.jpship_status AS logistics, d.logistic_trace,
       x.intl_number, x.intl_status, x.intl_fee, x.intl_qty, x.intl_weight, x.tranship_comment, x.comment AS intl_comment
FROM order_items i
JOIN orders o ON o.id = i.order_id
LEFT JOIN stores s ON s.id = o.store_id
LEFT JOIN purchases p ON p.order_item_id = i.id
LEFT JOIN jp_shipments j ON j.order_item_id = i.id
LEFT JOIN domestic_shipments d ON d.order_item_id = i.id
LEFT JOIN intl_shipments x ON x.order_item_id = i.id
WHERE i.id = ?
LIMIT 1
SQL);
        $stmt->execute([$itemId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }



    /** @param array<string, mixed> $changes */
    public function updateOrderItemData(\PDO $pdo, int $itemId, array $changes, string $action, string $operator = '系统管理员'): void
    {
        $snapshot = $this->itemSnapshot($pdo, $itemId);
        if (!$snapshot) {
            return;
        }

        $allowed = [
            'source_type',
            'purchase_status',
            'buyer',
            'purchase_time',
            'purchase_link',
            'buhuo_link',
            'amount',
            'cn_amount',
            'com_amount',
            'tabaono',
            'caigou_ordernums',
            'ship_company',
            'ship_number',
            'ship_quantity',
            'receipt_city',
            'logistics',
            'logistic_trace',
            'material',
            'weight',
            'chinese_option',
            'comment',
            'assignee',
            'out_status',
            'jp_warehouse_id',
            'intl_number',
            'intl_status',
            'intl_fee',
            'intl_qty',
            'intl_weight',
            'tranship_comment',
            'intl_comment',
        ];

        $pdo->beginTransaction();
        try {
            $pendingPurchaseEvents = [];
            foreach ($allowed as $field) {
                if (!array_key_exists($field, $changes)) {
                    continue;
                }

                $newValue = is_string($changes[$field]) ? trim($changes[$field]) : $changes[$field];
                if ($field === 'source_type' && !in_array($newValue, ['cn_purchase', 'jp_stock', 'pending'], true)) {
                    continue;
                }
                if ($field === 'out_status' && !in_array($newValue, ['待分配', '已分配', '已出库', '已发货'], true)) {
                    continue;
                }
                if (in_array($field, ['amount', 'cn_amount', 'com_amount', 'intl_fee', 'intl_weight', 'weight'], true)) {
                    $newValue = (float) $newValue;
                }
                if (in_array($field, ['ship_quantity', 'intl_qty'], true)) {
                    $newValue = (int) $newValue;
                }
                if ($field === 'purchase_status') {
                    $newValue = $this->normalizePurchaseStatus((string) $newValue);
                }

                $oldValue = $snapshot[$field] ?? ($field === 'ship_number' ? ($snapshot['cn_ship_number'] ?? '') : '');
                if ((string) $oldValue === (string) $newValue) {
                    continue;
                }

                $this->writeItemField($pdo, $itemId, $field, $newValue);
                $this->insertItemLog($pdo, (int) $snapshot['order_id'], $itemId, $action, $field, (string) $oldValue, (string) $newValue, $operator);
                $snapshot[$field] = $newValue;
                if ($field === 'purchase_status') {
                    $pendingPurchaseEvents[] = [(string) $oldValue, (string) $newValue, $action, $operator];
                }

                if ($field === 'source_type') {
                    $statusOldValue = (string) ($snapshot['purchase_status'] ?? '');
                    $nextStatus = match ((string) $newValue) {
                        'cn_purchase' => '国内采购-准备',
                        'jp_stock' => '日本库存订单',
                        default => '待处理',
                    };
                    if (array_key_exists('purchase_status', $changes)) {
                        $nextStatus = $this->normalizePurchaseStatus((string) $changes['purchase_status']);
                    }
                    if ($statusOldValue !== $nextStatus) {
                        $this->writeItemField($pdo, $itemId, 'purchase_status', $nextStatus);
                        $this->insertItemLog($pdo, (int) $snapshot['order_id'], $itemId, $action, 'purchase_status', $statusOldValue, $nextStatus, $operator);
                        $snapshot['purchase_status'] = $nextStatus;
                        $pendingPurchaseEvents[] = [$statusOldValue, $nextStatus, $action, $operator];
                    }
                }
            }

            foreach ($pendingPurchaseEvents as [$oldStatus, $newStatus, $eventSource, $eventOperator]) {
                $this->recordPurchaseStatusEvent($pdo, $snapshot, $oldStatus, $newStatus, $eventSource, $eventOperator);
            }

            $pdo->commit();
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }



    public function normalizePurchaseStatus(string $status): string
    {
        return match ($status) {
            '', '未采购' => '国内采购-准备',
            '已采购' => '国内采购-已采购',
            default => $status,
        };
    }



    /**
     * @param array<int, int> $itemIds
     * @param array<int, int> $orderIds
     * @return array<int, int>
     */
    private function resolveItemIds(\PDO $pdo, array $itemIds, array $orderIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if (!$itemIds && !$orderIds) {
            return [];
        }

        $clauses = [];
        $params = [];
        if ($itemIds) {
            $clauses[] = 'id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')';
            array_push($params, ...$itemIds);
        }
        if ($orderIds) {
            $clauses[] = 'order_id IN (' . implode(',', array_fill(0, count($orderIds), '?')) . ')';
            array_push($params, ...$orderIds);
        }

        $stmt = $pdo->prepare('SELECT id FROM order_items WHERE ' . implode(' OR ', $clauses));
        $stmt->execute($params);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }



    private function writeItemField(\PDO $pdo, int $itemId, string $field, mixed $value): void
    {
        match ($field) {
            'source_type', 'purchase_status', 'amount', 'jp_warehouse_id' => $this->updateOrderItemColumn($pdo, $itemId, $field, $value),
            'buyer' => $this->updatePurchaseColumn($pdo, $itemId, 'caigou_user', $value),
            'purchase_time' => $this->updatePurchaseColumn($pdo, $itemId, 'caigou_time', $value !== '' ? $value : null),
            'purchase_link' => $this->updatePurchaseColumn($pdo, $itemId, 'caigou_link', $value),
            'buhuo_link' => $this->updatePurchaseColumn($pdo, $itemId, 'buhuo_link', $value),
            'tabaono' => $this->updatePurchaseColumn($pdo, $itemId, 'tabaono', $value),
            'caigou_ordernums' => $this->updatePurchaseColumn($pdo, $itemId, 'caigou_ordernums', $value),
            'cn_amount' => $this->updatePurchaseColumn($pdo, $itemId, 'cn_amount', $value),
            'com_amount' => $this->updatePurchaseColumn($pdo, $itemId, 'com_amount', $value),
            'ship_number' => $this->updateShipNumber($pdo, $itemId, $value),
            'ship_company' => $this->updateDomesticColumn($pdo, $itemId, 'ship_company', $value),
            'ship_quantity' => $this->updateDomesticColumn($pdo, $itemId, 'ship_quantity', $value),
            'receipt_city' => $this->updateDomesticColumn($pdo, $itemId, 'receipt_city', $value),
            'logistics' => $this->updateDomesticColumn($pdo, $itemId, 'jpship_status', $value),
            'logistic_trace' => $this->updateDomesticColumn($pdo, $itemId, 'logistic_trace', $value),
            'material' => $this->updateOrderItemColumn($pdo, $itemId, 'material', $value),
            'weight' => $this->updateOrderItemColumn($pdo, $itemId, 'weight', $value),
            'chinese_option' => $this->updateOrderItemColumn($pdo, $itemId, 'chinese_option', $value),
            'comment' => $this->updateOrderItemColumn($pdo, $itemId, 'item_comment', $value),
            'assignee' => $this->updateJpColumn($pdo, $itemId, 'assignee', $value),
            'out_status' => $this->updateJpColumn($pdo, $itemId, 'out_status', $value),
            'intl_number' => $this->updateIntlColumn($pdo, $itemId, 'intl_number', $value),
            'intl_status' => $this->updateIntlColumn($pdo, $itemId, 'intl_status', $value),
            'intl_fee' => $this->updateIntlColumn($pdo, $itemId, 'intl_fee', $value),
            'intl_qty' => $this->updateIntlColumn($pdo, $itemId, 'intl_qty', $value),
            'intl_weight' => $this->updateIntlColumn($pdo, $itemId, 'intl_weight', $value),
            'tranship_comment' => $this->updateIntlColumn($pdo, $itemId, 'tranship_comment', $value),
            'intl_comment' => $this->updateIntlColumn($pdo, $itemId, 'comment', $value),
            default => null,
        };
    }



    private function updateOrderItemColumn(\PDO $pdo, int $itemId, string $column, mixed $value): void
    {
        $stmt = $pdo->prepare("UPDATE order_items SET {$column} = ? WHERE id = ?");
        $stmt->execute([$value, $itemId]);
    }



    private function updatePurchaseColumn(\PDO $pdo, int $itemId, string $column, mixed $value): void
    {
        $this->ensureChildRow($pdo, 'purchases', $itemId);
        $stmt = $pdo->prepare("UPDATE purchases SET {$column} = ? WHERE order_item_id = ?");
        $stmt->execute([$value, $itemId]);
    }



    private function updateDomesticColumn(\PDO $pdo, int $itemId, string $column, mixed $value): void
    {
        $this->ensureChildRow($pdo, 'domestic_shipments', $itemId);
        $stmt = $pdo->prepare("UPDATE domestic_shipments SET {$column} = ? WHERE order_item_id = ? LIMIT 1");
        $stmt->execute([$value, $itemId]);
    }



    private function updateShipNumber(\PDO $pdo, int $itemId, mixed $value): void
    {
        $this->updatePurchaseColumn($pdo, $itemId, 'cn_ship_number', $value);
        $this->updateDomesticColumn($pdo, $itemId, 'ship_number', $value);
    }



    private function updateJpColumn(\PDO $pdo, int $itemId, string $column, mixed $value): void
    {
        $this->ensureChildRow($pdo, 'jp_shipments', $itemId);
        $stmt = $pdo->prepare("UPDATE jp_shipments SET {$column} = ? WHERE order_item_id = ?");
        $stmt->execute([$value, $itemId]);
    }



    private function updateIntlColumn(\PDO $pdo, int $itemId, string $column, mixed $value): void
    {
        $this->ensureChildRow($pdo, 'intl_shipments', $itemId);
        $stmt = $pdo->prepare("UPDATE intl_shipments SET {$column} = ? WHERE order_item_id = ?");
        $stmt->execute([$value, $itemId]);
    }



    private function recordPurchaseStatusEvent(\PDO $pdo, array $snapshot, string $oldStatus, string $newStatus, string $source, string $operator): void
    {
        $actionType = self::PURCHASE_EVENT_STATUSES[$newStatus] ?? null;
        if ($actionType === null || $oldStatus === $newStatus) {
            return;
        }

        $this->ensurePurchaseStatusEventTable($pdo);
        $purchaseTime = trim((string) ($snapshot['purchase_time'] ?? ''));
        $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO purchase_status_events
(platform, order_id, order_item_id, platform_order_id, item_code, store_name, operator, user_type, buyer, action_type, old_status, new_status, source, tabaono, cn_amount, caigou_time, created_at, created_date)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), CURDATE())
SQL);
        $stmt->execute([
            (string) ($snapshot['platform'] ?? ''),
            (int) ($snapshot['order_id'] ?? 0),
            (int) ($snapshot['id'] ?? 0),
            (string) ($snapshot['platform_order_id'] ?? ''),
            (string) ($snapshot['item_code'] ?? ''),
            (string) ($snapshot['store_name'] ?? ''),
            $operator,
            '',
            (string) ($snapshot['buyer'] ?? ''),
            $actionType,
            $oldStatus,
            $newStatus,
            $source,
            (string) ($snapshot['tabaono'] ?? ''),
            (float) ($snapshot['cn_amount'] ?? $snapshot['amount'] ?? 0),
            $purchaseTime !== '' ? $purchaseTime : null,
        ]);
    }
}
