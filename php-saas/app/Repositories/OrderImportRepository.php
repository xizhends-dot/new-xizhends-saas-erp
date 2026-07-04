<?php

declare(strict_types=1);

namespace Xizhen\Repositories;

use Xizhen\Core\Db;

final class OrderImportRepository extends BaseRepository
{
    public function __construct(
        Db $db,
        private readonly OrderMutationRepository $mutationRepository,
        private readonly StoreRepository $storeRepository
    ) {
        parent::__construct($db);
    }



    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array{inserted: int, updated: int, skipped: int, items_inserted: int, items_updated: int}
     */
    public function upsertPlatformOrders(string $tenantKey, array $orders, string $operator): array
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        $result = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'items_inserted' => 0, 'items_updated' => 0];
        if (!$tenantPdo || !$orders) {
            return $result;
        }

        $tenantPdo->beginTransaction();
        try {
            foreach ($orders as $order) {
                $platform = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($order['platform'] ?? '')) ?: '';
                $platformOrderId = trim((string) ($order['platform_order_id'] ?? ''));
                $storeId = max(0, (int) ($order['store_id'] ?? 0));
                if ($platform === '' || $platformOrderId === '') {
                    $result['skipped']++;
                    continue;
                }

                $orderId = $this->findPlatformOrderId($tenantPdo, $platform, $storeId, $platformOrderId);
                $orderPayload = $this->normalizePlatformOrderPayload($order);
                if ($orderId > 0) {
                    $this->updatePlatformOrder($tenantPdo, $orderId, $orderPayload);
                    $result['updated']++;
                } else {
                    $orderId = $this->insertPlatformOrder($tenantPdo, $orderPayload);
                    $result['inserted']++;
                }

                $quantityDetail = [];
                foreach (is_array($order['items'] ?? null) ? $order['items'] : [] as $index => $item) {
                    $itemPayload = $this->normalizePlatformItemPayload($orderId, $item, $index);
                    $lineKey = $itemPayload['line_id'] !== '' ? $itemPayload['line_id'] : (string) ($index + 1);
                    $quantityDetail[] = 'L' . $lineKey . '=' . (int) $itemPayload['quantity'];
                    $itemId = $this->findPlatformOrderItemId($tenantPdo, $orderId, $itemPayload);
                    if ($itemId > 0) {
                        $this->updatePlatformOrderItem($tenantPdo, $itemId, $itemPayload);
                        $result['items_updated']++;
                    } else {
                        $itemId = $this->insertPlatformOrderItem($tenantPdo, $itemPayload);
                        $result['items_inserted']++;
                        $this->insertItemLog($tenantPdo, $orderId, $itemId, '平台API导入', 'source', '-', 'Rakuten RMS', $operator);
                    }
                }

                if ($quantityDetail) {
                    $this->mergeOrderQuantityDetail($tenantPdo, $orderId, implode('&', $quantityDetail));
                }
            }

            $tenantPdo->commit();
        } catch (\Throwable $error) {
            $tenantPdo->rollBack();
            throw $error;
        }

        return $result;
    }



    /**
     * @param array<int, array<string, mixed>> $records
     * @return array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>}
     */
    public function importPlatformOrders(string $tenantKey, array $records, string $operator): array
    {
        $report = $this->emptyImportReport();
        $orders = [];
        foreach ($records as $record) {
            $order = is_array($record['order'] ?? null) ? $record['order'] : [];
            $item = is_array($record['item'] ?? null) ? $record['item'] : [];
            $platform = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($order['platform'] ?? '')) ?: '';
            $platformOrderId = trim((string) ($order['platform_order_id'] ?? ''));
            if ($platform === '' || $platformOrderId === '') {
                $this->importReportFail($report, (int) ($record['row'] ?? 0), '平台或订单号为空。');
                continue;
            }

            $order['store_id'] = $this->resolveImportStoreId($tenantKey, $platform, (int) ($order['store_id'] ?? 0));
            if (isset($order['extra']) && !isset($order['platform_extra'])) {
                $order['platform_extra'] = $order['extra'];
            }
            if (isset($item['extra']) && !isset($item['platform_extra'])) {
                $item['platform_extra'] = $item['extra'];
            }
            $key = $platform . "\n" . (int) $order['store_id'] . "\n" . $platformOrderId;
            if (!isset($orders[$key])) {
                $order['items'] = [];
                $orders[$key] = $order;
            } else {
                $orders[$key] = $this->mergeImportOrder($orders[$key], $order);
            }
            if ($item) {
                $orders[$key]['items'][] = $item;
            }
        }

        if (!$orders) {
            return $report;
        }

        $result = $this->upsertPlatformOrders($tenantKey, array_values($orders), $operator);
        $report['inserted'] = (int) ($result['inserted'] ?? 0);
        $report['updated'] = (int) ($result['updated'] ?? 0) + (int) ($result['items_inserted'] ?? 0) + (int) ($result['items_updated'] ?? 0);
        $report['skipped'] = (int) ($result['skipped'] ?? 0);

        return $report;
    }



    /**
     * @param array<int, array<string, mixed>> $records
     * @return array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>}
     */
    public function importPurchaseRows(string $tenantKey, array $records, string $operator): array
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        $report = $this->emptyImportReport();
        if (!$tenantPdo || !$records) {
            return $report;
        }

        foreach ($records as $record) {
            $identity = is_array($record['identity'] ?? null) ? $record['identity'] : [];
            $changes = is_array($record['changes'] ?? null) ? $record['changes'] : [];
            $itemId = $this->findImportItemId($tenantPdo, $identity);
            if ($itemId <= 0) {
                $report['failed']++;
                $this->importReportMessage($report, (int) ($record['row'] ?? 0), '未找到匹配的订单商品，采购导入未更新。');
                continue;
            }

            $snapshot = $this->mutationRepository->itemSnapshot($tenantPdo, $itemId);
            $oldStatus = (string) ($snapshot['purchase_status'] ?? '');
            if (!$this->canAdvancePurchaseStatus($oldStatus)) {
                $report['skipped']++;
                $this->importReportMessage($report, (int) ($record['row'] ?? 0), "当前采购状态为 {$oldStatus}，未覆盖。");
                continue;
            }

            if (isset($changes['purchase_status'])) {
                $changes['purchase_status'] = $this->mutationRepository->normalizePurchaseStatus((string) $changes['purchase_status']);
            }
            $changes['source_type'] = 'cn_purchase';
            $this->mutationRepository->updateOrderItemData($tenantPdo, $itemId, $changes, '采购导入', $operator);
            $report['updated']++;
        }

        return $report;
    }



    /**
     * @param array<int, array<string, mixed>> $records
     * @return array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>}
     */
    public function importShippingRows(string $tenantKey, array $records, string $operator): array
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        $report = $this->emptyImportReport();
        if (!$tenantPdo || !$records) {
            return $report;
        }

        foreach ($records as $record) {
            $identity = is_array($record['identity'] ?? null) ? $record['identity'] : [];
            $changes = is_array($record['changes'] ?? null) ? $record['changes'] : [];
            $itemIds = $this->findImportItemIds($tenantPdo, $identity);
            if (!$itemIds) {
                $report['failed']++;
                $this->importReportMessage($report, (int) ($record['row'] ?? 0), '未找到匹配的订单商品，国际运单导入未更新。');
                continue;
            }

            foreach ($itemIds as $itemId) {
                $payload = $changes;
                if (isset($payload['intl_number']) && empty($payload['reset_tracking'])) {
                    $payload['intl_number'] = $this->mergeMysqlTrackingNumbers($tenantPdo, $itemId, (string) $payload['intl_number']);
                }
                unset($payload['reset_tracking']);
                if (isset($payload['purchase_status'])) {
                    $payload['purchase_status'] = $this->mutationRepository->normalizePurchaseStatus((string) $payload['purchase_status']);
                }
                $this->mutationRepository->updateOrderItemData($tenantPdo, $itemId, $payload, '国际运单导入', $operator);
                $report['updated']++;
            }
        }

        return $report;
    }



    private function findPlatformOrderId(\PDO $pdo, string $platform, int $storeId, string $platformOrderId): int
    {
        if ($storeId > 0) {
            $stmt = $pdo->prepare('SELECT id FROM orders WHERE platform = ? AND store_id = ? AND platform_order_id = ? LIMIT 1');
            $stmt->execute([$platform, $storeId, $platformOrderId]);
        } else {
            $stmt = $pdo->prepare('SELECT id FROM orders WHERE platform = ? AND platform_order_id = ? LIMIT 1');
            $stmt->execute([$platform, $platformOrderId]);
        }

        return (int) ($stmt->fetchColumn() ?: 0);
    }



    /** @return array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>} */
    private function emptyImportReport(): array
    {
        return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'messages' => []];
    }



    /** @param array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>} $report */
    private function importReportMessage(array &$report, int $row, string $message): void
    {
        if (count($report['messages']) >= 30) {
            return;
        }

        $report['messages'][] = $row > 0 ? "第 {$row} 行：{$message}" : $message;
    }



    /** @param array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>} $report */
    private function importReportFail(array &$report, int $row, string $message): void
    {
        $report['failed']++;
        $this->importReportMessage($report, $row, $message);
    }



    private function resolveImportStoreId(string $tenantKey, string $platform, int $selectedStoreId): int
    {
        if ($selectedStoreId > 0) {
            return $selectedStoreId;
        }

        foreach ($this->storeRepository->stores($tenantKey) as $store) {
            if ((string) ($store['platform'] ?? '') === $platform && ($store['status'] ?? 'visible') !== 'hidden') {
                return (int) ($store['id'] ?? 0);
            }
        }

        return 0;
    }



    /** @param array<string, mixed> $base @param array<string, mixed> $incoming @return array<string, mixed> */
    private function mergeImportOrder(array $base, array $incoming): array
    {
        foreach ($incoming as $field => $value) {
            if ($field === 'items') {
                continue;
            }
            if ($value === '' || $value === 0 || $value === 0.0 || $value === null) {
                continue;
            }
            if ($field === 'customer' && is_array($value)) {
                $base['customer'] = array_replace(is_array($base['customer'] ?? null) ? $base['customer'] : [], $value);
                continue;
            }
            if ($field === 'extra' && is_array($value)) {
                $base['extra'] = array_replace(is_array($base['extra'] ?? null) ? $base['extra'] : [], $value);
                $base['platform_extra'] = array_replace(is_array($base['platform_extra'] ?? null) ? $base['platform_extra'] : [], $value);
                continue;
            }
            $base[$field] = $value;
        }

        return $base;
    }



    /** @param array<string, mixed> $order */
    private function normalizePlatformOrderPayload(array $order): array
    {
        $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
        $extra = is_array($order['platform_extra'] ?? null) ? $order['platform_extra'] : [];

        return [
            'platform' => preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($order['platform'] ?? '')) ?: '',
            'platform_order_id' => trim((string) ($order['platform_order_id'] ?? '')),
            'order_detail_id' => trim((string) ($order['order_detail_id'] ?? '')),
            'store_id' => max(0, (int) ($order['store_id'] ?? 0)),
            'order_date' => $this->sqlDateTime($order['order_date'] ?? null),
            'order_status' => trim((string) ($order['status'] ?? $order['order_status'] ?? '')),
            'customer_name' => trim((string) ($customer['name'] ?? '')),
            'customer_kana' => trim((string) ($customer['kana'] ?? '')),
            'customer_zip' => trim((string) ($customer['zip'] ?? '')),
            'customer_address' => trim((string) ($customer['address'] ?? '')),
            'customer_phone' => trim((string) ($customer['phone'] ?? '')),
            'customer_mail' => trim((string) ($customer['mail'] ?? '')),
            'pay_method' => trim((string) ($order['pay_method'] ?? '')),
            'ship_method' => trim((string) ($order['ship_method'] ?? '')),
            'total_item_price' => $this->moneyValue($order['total_item_price'] ?? 0),
            'postage_price' => $this->moneyValue($order['postage_price'] ?? 0),
            'pay_charge' => $this->moneyValue($order['pay_charge'] ?? 0),
            'total_price' => $this->moneyValue($order['total'] ?? $order['total_price'] ?? 0),
            'review_invited' => !empty($order['review_invited']) ? 1 : 0,
            'reviewed' => !empty($order['reviewed']) ? 1 : 0,
            'platform_extra' => $extra,
        ];
    }



    /** @param array<string, mixed> $payload */
    private function insertPlatformOrder(\PDO $pdo, array $payload): int
    {
        $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO orders
(platform, platform_order_id, order_detail_id, store_id, order_date, order_status, customer_name, customer_kana, customer_zip, customer_address, customer_phone, customer_mail, pay_method, ship_method, total_item_price, postage_price, pay_charge, total_price, review_invited, reviewed, platform_extra)
VALUES
(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);
        $stmt->execute([
            $payload['platform'],
            $payload['platform_order_id'],
            $payload['order_detail_id'] !== '' ? $payload['order_detail_id'] : null,
            $payload['store_id'] > 0 ? $payload['store_id'] : null,
            $payload['order_date'],
            $payload['order_status'],
            $payload['customer_name'],
            $payload['customer_kana'],
            $payload['customer_zip'],
            $payload['customer_address'],
            $payload['customer_phone'],
            $payload['customer_mail'],
            $payload['pay_method'],
            $payload['ship_method'],
            $payload['total_item_price'],
            $payload['postage_price'],
            $payload['pay_charge'],
            $payload['total_price'],
            $payload['review_invited'],
            $payload['reviewed'],
            $this->jsonValue($payload['platform_extra']),
        ]);

        return (int) $pdo->lastInsertId();
    }



    /** @param array<string, mixed> $payload */
    private function updatePlatformOrder(\PDO $pdo, int $orderId, array $payload): void
    {
        $stmt = $pdo->prepare(<<<'SQL'
UPDATE orders
SET order_detail_id = ?, order_date = ?, order_status = ?, customer_name = ?, customer_kana = ?, customer_zip = ?,
    customer_address = ?, customer_phone = ?, customer_mail = ?, pay_method = ?, ship_method = ?,
    total_item_price = ?, postage_price = ?, pay_charge = ?, total_price = ?, review_invited = ?, reviewed = ?,
    platform_extra = ?
WHERE id = ?
SQL);
        $stmt->execute([
            $payload['order_detail_id'] !== '' ? $payload['order_detail_id'] : null,
            $payload['order_date'],
            $payload['order_status'],
            $payload['customer_name'],
            $payload['customer_kana'],
            $payload['customer_zip'],
            $payload['customer_address'],
            $payload['customer_phone'],
            $payload['customer_mail'],
            $payload['pay_method'],
            $payload['ship_method'],
            $payload['total_item_price'],
            $payload['postage_price'],
            $payload['pay_charge'],
            $payload['total_price'],
            $payload['review_invited'],
            $payload['reviewed'],
            $this->jsonValue($payload['platform_extra']),
            $orderId,
        ]);
    }



    /** @param array<string, mixed> $item */
    private function normalizePlatformItemPayload(int $orderId, array $item, int $index): array
    {
        $extra = is_array($item['platform_extra'] ?? null) ? $item['platform_extra'] : [];
        $quantity = max(0, (int) ($item['quantity'] ?? 0));
        $unitPrice = $this->moneyValue($item['unit_price'] ?? 0);
        $postage = $this->moneyValue($item['postage_price'] ?? 0);
        $payCharge = $this->moneyValue($item['pay_charge'] ?? 0);
        $lineTotal = $this->moneyValue($item['line_total'] ?? 0);
        if ($lineTotal <= 0 && $unitPrice > 0) {
            $lineTotal = ($unitPrice * max(1, $quantity)) + $postage + $payCharge;
        }

        return [
            'order_id' => $orderId,
            'order_detail_id' => trim((string) ($item['order_detail_id'] ?? '')),
            'line_id' => trim((string) (($item['line_id'] ?? '') !== '' ? $item['line_id'] : (string) ($index + 1))),
            'source_type' => in_array(($item['source_type'] ?? 'pending'), ['cn_purchase', 'jp_stock', 'pending'], true) ? (string) $item['source_type'] : 'pending',
            'purchase_status' => trim((string) ($item['purchase_status'] ?? '未处理的订单')),
            'item_code' => trim((string) ($item['item_code'] ?? '')),
            'lot_number' => trim((string) ($item['lot_number'] ?? '')),
            'item_management_id' => trim((string) ($item['item_management_id'] ?? '')),
            'jp_warehouse_id' => trim((string) ($item['jp_warehouse_id'] ?? '')),
            'product_title' => trim((string) ($item['title'] ?? $item['product_title'] ?? '')),
            'item_option' => trim((string) ($item['option'] ?? $item['item_option'] ?? '')),
            'chinese_option' => trim((string) ($item['chinese_option'] ?? '')),
            'quantity' => $quantity,
            'weight' => $this->moneyValue($item['weight'] ?? 0),
            'material' => trim((string) ($item['material'] ?? '')),
            'unit_price' => $unitPrice,
            'postage_price' => $postage,
            'pay_charge' => $payCharge,
            'line_total' => $lineTotal,
            'amount' => $this->moneyValue($item['amount'] ?? 0),
            'item_comment' => trim((string) ($item['comment'] ?? $item['item_comment'] ?? '')),
            'caigou_user' => trim((string) ($item['buyer'] ?? $item['caigou_user'] ?? '')),
            'main_image' => trim((string) ($item['image'] ?? $item['main_image'] ?? '')),
            'sku_image' => trim((string) ($item['sku_image'] ?? '')),
            'platform_extra' => $extra,
        ];
    }



    /** @param array<string, mixed> $payload */
    private function findPlatformOrderItemId(\PDO $pdo, int $orderId, array $payload): int
    {
        if ($payload['order_detail_id'] !== '') {
            $stmt = $pdo->prepare('SELECT id FROM order_items WHERE order_id = ? AND order_detail_id = ? LIMIT 1');
            $stmt->execute([$orderId, $payload['order_detail_id']]);
            $id = (int) ($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        if ($payload['line_id'] !== '') {
            $stmt = $pdo->prepare('SELECT id FROM order_items WHERE order_id = ? AND line_id = ? LIMIT 1');
            $stmt->execute([$orderId, $payload['line_id']]);
            $id = (int) ($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        $stmt = $pdo->prepare('SELECT id FROM order_items WHERE order_id = ? AND item_code = ? AND item_option = ? LIMIT 1');
        $stmt->execute([$orderId, $payload['item_code'], $payload['item_option']]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }



    /** @param array<string, mixed> $payload */
    private function insertPlatformOrderItem(\PDO $pdo, array $payload): int
    {
        $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO order_items
(order_id, order_detail_id, line_id, source_type, purchase_status, item_code, lot_number, item_management_id, jp_warehouse_id, product_title, item_option, chinese_option, quantity, weight, material, unit_price, postage_price, pay_charge, line_total, amount, item_comment, caigou_user, main_image, sku_image, platform_extra)
VALUES
(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);
        $stmt->execute([
            $payload['order_id'],
            $payload['order_detail_id'],
            $payload['line_id'],
            $payload['source_type'],
            $payload['purchase_status'],
            $payload['item_code'],
            $payload['lot_number'],
            $payload['item_management_id'],
            $payload['jp_warehouse_id'] !== '' ? $payload['jp_warehouse_id'] : null,
            $payload['product_title'],
            $payload['item_option'],
            $payload['chinese_option'],
            $payload['quantity'],
            $payload['weight'],
            $payload['material'],
            $payload['unit_price'],
            $payload['postage_price'],
            $payload['pay_charge'],
            $payload['line_total'],
            $payload['amount'],
            $payload['item_comment'],
            $payload['caigou_user'] !== '' ? $payload['caigou_user'] : null,
            $payload['main_image'],
            $payload['sku_image'],
            $this->jsonValue($payload['platform_extra']),
        ]);

        return (int) $pdo->lastInsertId();
    }



    /** @param array<string, mixed> $payload */
    private function updatePlatformOrderItem(\PDO $pdo, int $itemId, array $payload): void
    {
        $stmt = $pdo->prepare(<<<'SQL'
UPDATE order_items
SET order_detail_id = ?, line_id = ?, item_code = ?, lot_number = ?, item_management_id = ?, product_title = ?,
    item_option = ?, chinese_option = ?, quantity = ?, weight = ?, material = ?, unit_price = ?, postage_price = ?,
    pay_charge = ?, line_total = ?, item_comment = ?, main_image = ?, sku_image = ?, platform_extra = ?
WHERE id = ?
SQL);
        $stmt->execute([
            $payload['order_detail_id'],
            $payload['line_id'],
            $payload['item_code'],
            $payload['lot_number'],
            $payload['item_management_id'],
            $payload['product_title'],
            $payload['item_option'],
            $payload['chinese_option'],
            $payload['quantity'],
            $payload['weight'],
            $payload['material'],
            $payload['unit_price'],
            $payload['postage_price'],
            $payload['pay_charge'],
            $payload['line_total'],
            $payload['item_comment'],
            $payload['main_image'],
            $payload['sku_image'],
            $this->jsonValue($payload['platform_extra']),
            $itemId,
        ]);
    }



    private function mergeOrderQuantityDetail(\PDO $pdo, int $orderId, string $quantityDetail): void
    {
        $stmt = $pdo->prepare('SELECT platform_extra FROM orders WHERE id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        $extra = $this->jsonArray($stmt->fetchColumn() ?: null);
        $extra['QuantityDetail'] = $quantityDetail;
        $pdo->prepare('UPDATE orders SET platform_extra = ? WHERE id = ?')->execute([$this->jsonValue($extra), $orderId]);
    }



    /** @param array<string, mixed> $identity */
    private function findImportItemId(\PDO $pdo, array $identity): int
    {
        $ids = $this->findImportItemIds($pdo, $identity);
        return $ids[0] ?? 0;
    }



    /** @param array<string, mixed> $identity @return array<int, int> */
    private function findImportItemIds(\PDO $pdo, array $identity): array
    {
        $platform = trim((string) ($identity['platform'] ?? ''));
        $orderId = trim((string) ($identity['platform_order_id'] ?? ''));
        $orderDetailId = trim((string) ($identity['order_detail_id'] ?? ''));
        $lineId = trim((string) ($identity['line_id'] ?? ''));
        $itemCode = trim((string) ($identity['item_code'] ?? ''));

        if ($orderId === '') {
            return [];
        }

        $conditions = ['o.platform_order_id = ?'];
        $params = [$orderId];
        if ($platform !== '') {
            $conditions[] = 'o.platform = ?';
            $params[] = $platform;
        }
        if ($orderDetailId !== '') {
            $conditions[] = 'i.order_detail_id = ?';
            $params[] = $orderDetailId;
        }
        if ($lineId !== '') {
            $conditions[] = 'i.line_id = ?';
            $params[] = $lineId;
        }
        if ($itemCode !== '') {
            $conditions[] = '(i.item_code = ? OR i.lot_number = ? OR i.item_management_id = ?)';
            array_push($params, $itemCode, $itemCode, $itemCode);
        }

        $stmt = $pdo->prepare(
            'SELECT i.id FROM order_items i INNER JOIN orders o ON o.id = i.order_id WHERE ' . implode(' AND ', $conditions) . ' ORDER BY i.id'
        );
        $stmt->execute($params);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }



    /** @return array<int, int> */
    private function findImportItemIdsByIntlNumber(\PDO $pdo, string $number): array
    {
        $number = trim($number);
        if ($number === '') {
            return [];
        }

        $stmt = $pdo->prepare('SELECT order_item_id FROM intl_shipments WHERE intl_number = ? OR intl_number LIKE ? OR intl_number LIKE ? OR intl_number LIKE ?');
        $stmt->execute([$number, $number . ',%', '%,' . $number, '%,' . $number . ',%']);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }



    private function mergeMysqlTrackingNumbers(\PDO $pdo, int $itemId, string $incoming): string
    {
        $incoming = trim($incoming);
        if ($incoming === '') {
            return '';
        }

        $stmt = $pdo->prepare('SELECT intl_number FROM intl_shipments WHERE order_item_id = ? LIMIT 1');
        $stmt->execute([$itemId]);
        $current = (string) ($stmt->fetchColumn() ?: '');
        $numbers = array_values(array_filter(array_map('trim', preg_split('/[,，\s]+/u', $current) ?: [])));
        if (!in_array($incoming, $numbers, true)) {
            $numbers[] = $incoming;
        }

        return implode(',', $numbers);
    }



    private function canAdvancePurchaseStatus(string $status): bool
    {
        return in_array($status, ['', '待处理', '未采购', '国内采购-准备'], true);
    }
}
