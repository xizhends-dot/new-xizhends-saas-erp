<?php

declare(strict_types=1);

namespace Xizhen\Repositories;

use Xizhen\Core\Permission;

final class OrderQueryRepository extends BaseRepository
{


    /** @return array<int, array<string, mixed>> */
    public function orders(string $tenantKey): array
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo) {
            return [];
        }

        $sql = <<<'SQL'
SELECT o.*, COALESCE(s.dpquancheng, s.dpqz, '') AS store_name
FROM orders o
LEFT JOIN stores s ON s.id = o.store_id
ORDER BY COALESCE(o.order_date, o.imported_at) DESC
LIMIT 200
SQL;
        $rows = $tenantPdo->query($sql)->fetchAll();

        return array_map(fn (array $row): array => $this->mapOrder($tenantPdo, $row), $rows);
    }



    /** @return array<int, array<string, mixed>> */
    public function ordersByYear(string $tenantKey, int $year): array
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo) {
            return [];
        }

        $stmt = $tenantPdo->prepare(<<<'SQL'
SELECT o.*, COALESCE(s.dpquancheng, s.dpqz, '') AS store_name
FROM orders o
LEFT JOIN stores s ON s.id = o.store_id
WHERE YEAR(COALESCE(o.order_date, o.imported_at)) = ?
ORDER BY COALESCE(o.order_date, o.imported_at) DESC
SQL);
        $stmt->execute([$year]);

        return array_map(fn (array $row): array => $this->mapOrder($tenantPdo, $row), $stmt->fetchAll());
    }



    /**
     * @param array<int, string> $stores
     * @return array<int, array<string, mixed>>
     */
    public function ordersForStores(string $tenantKey, array $stores): array
    {
        $stores = array_values(array_filter(array_map('trim', $stores)));
        if (!$stores || in_array('全部店铺', $stores, true)) {
            return $this->orders($tenantKey);
        }

        return array_values(array_filter(
            $this->orders($tenantKey),
            fn (array $order): bool => in_array((string) ($order['store'] ?? ''), $stores, true)
        ));
    }



    /** @return array<string, mixed>|null */
    public function order(string $tenantKey, int $orderId): ?array
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo || $orderId <= 0) {
            return null;
        }

        $stmt = $tenantPdo->prepare(<<<'SQL'
SELECT o.*, COALESCE(s.dpquancheng, s.dpqz, '') AS store_name
FROM orders o
LEFT JOIN stores s ON s.id = o.store_id
WHERE o.id = ?
LIMIT 1
SQL);
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();

        return $row ? $this->mapOrder($tenantPdo, $row) : null;
    }



    /**
     * @return array<int, array<string, mixed>>
     */
    public function purchaseStatusEvents(string $tenantKey, string $date, ?array $user = null, string $platform = ''): array
    {
        $pdo = $this->db->tenantPdo($tenantKey);
        if (!$pdo || !$this->tableExists($pdo, 'purchase_status_events')) {
            return [];
        }

        $date = trim($date);
        if ($date === '') {
            return [];
        }

        $conditions = ['created_date <= ?'];
        $params = [$date];
        $platform = trim($platform);
        if ($platform !== '') {
            $conditions[] = 'platform = ?';
            $params[] = $platform;
        }

        $stmt = $pdo->prepare('SELECT * FROM purchase_status_events WHERE ' . implode(' AND ', $conditions) . ' ORDER BY order_item_id, created_at, id');
        $stmt->execute($params);

        $rows = array_filter(
            $stmt->fetchAll(),
            fn (array $row): bool => $this->eventVisibleToUser($row, $user)
        );

        return array_values(array_map(fn (array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'platform' => (string) ($row['platform'] ?? ''),
            'order_id' => (int) ($row['order_id'] ?? 0),
            'order_item_id' => (int) ($row['order_item_id'] ?? 0),
            'platform_order_id' => (string) ($row['platform_order_id'] ?? ''),
            'item_code' => (string) ($row['item_code'] ?? ''),
            'store_name' => (string) ($row['store_name'] ?? ''),
            'operator' => (string) ($row['operator'] ?? ''),
            'user_type' => (string) ($row['user_type'] ?? ''),
            'buyer' => (string) ($row['buyer'] ?? ''),
            'action_type' => (string) ($row['action_type'] ?? ''),
            'old_status' => (string) ($row['old_status'] ?? ''),
            'new_status' => (string) ($row['new_status'] ?? ''),
            'source' => (string) ($row['source'] ?? ''),
            'tabaono' => (string) ($row['tabaono'] ?? ''),
            'cn_amount' => (float) ($row['cn_amount'] ?? 0),
            'caigou_time' => (string) ($row['caigou_time'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'created_date' => (string) ($row['created_date'] ?? ''),
        ], $rows));
    }



    /** @return array<string, mixed> */
    private function mapOrder(\PDO $pdo, array $row): array
    {
        $items = $this->itemsForOrder($pdo, (int) $row['id']);
        $extra = $this->jsonArray($row['platform_extra'] ?? null);
        $customerName = (string) (($row['customer_name'] ?? '') ?: $this->firstExtra($extra, ['ShipName', 'senderName']));
        $customerKana = (string) (($row['customer_kana'] ?? '') ?: $this->firstExtra($extra, ['senderKana']));
        $customerZip = (string) (($row['customer_zip'] ?? '') ?: $this->firstExtra($extra, ['ShipZipCode', 'senderZipCode', 'shipping_postal_code']));
        $customerAddress = (string) (($row['customer_address'] ?? '') ?: $this->firstExtra($extra, ['senderAddress', 'ShipAddress1', 'shipping_address_1']));
        $customerPhone = (string) (($row['customer_phone'] ?? '') ?: $this->firstExtra($extra, ['ShipPhoneNumber', 'senderPhoneNumber1']));
        $customerMail = (string) (($row['customer_mail'] ?? '') ?: $this->firstExtra($extra, ['BillMailAddress', 'mailAddress']));

        return [
            'id' => (int) $row['id'],
            'store_id' => (int) ($row['store_id'] ?? 0),
            'platform' => (string) $row['platform'],
            'platform_order_id' => (string) $row['platform_order_id'],
            'order_detail_id' => (string) ($row['order_detail_id'] ?? ''),
            'order_date' => (string) ($row['order_date'] ?? $row['imported_at'] ?? ''),
            'imported_at' => (string) ($row['imported_at'] ?? ''),
            'status' => (string) ($row['order_status'] ?? ''),
            'store' => (string) ($row['store_name'] ?? ''),
            'customer' => [
                'name' => $customerName,
                'kana' => $customerKana,
                'phone' => $customerPhone,
                'zip' => $customerZip,
                'address' => $customerAddress,
                'mail' => $customerMail,
            ],
            'pay_method' => (string) (($row['pay_method'] ?? '') ?: $this->firstExtra($extra, ['PayMethodName', 'settlementName'])),
            'ship_method' => (string) (($row['ship_method'] ?? '') ?: $this->firstExtra($extra, ['deliveryName'])),
            'total_item_price' => $this->moneyValue($row['total_item_price'] ?? $this->firstExtra($extra, ['totalItemPrice', 'QuantityDetail'])),
            'postage_price' => $this->moneyValue($row['postage_price'] ?? $this->firstExtra($extra, ['postagePrice', 'ShipCharge'])),
            'pay_charge' => $this->moneyValue($row['pay_charge'] ?? $this->firstExtra($extra, ['PayCharge'])),
            'total' => (float) ($row['total_price'] ?? 0),
            'review_invited' => !empty($row['review_invited']),
            'reviewed' => !empty($row['reviewed']),
            'platform_extra' => $extra,
            'items' => $items,
        ];
    }



    /** @return array<int, array<string, mixed>> */
    private function itemsForOrder(\PDO $pdo, int $orderId): array
    {
        $stmt = $pdo->prepare(<<<'SQL'
SELECT i.*, p.tabaono, p.caigou_link, p.buhuo_link, p.caigou_user AS purchase_user, p.caigou_time,
       p.caigou_ordernums, p.cn_amount, p.com_amount, p.cn_ship_number,
       j.out_status, j.assignee, j.location, j.out_no, j.out_cost, j.out_time,
       d.ship_company, d.ship_number, d.ship_quantity, d.receipt_city, d.jpship_status, d.jpship_completed_at, d.logistic_trace,
       x.intl_number, x.intl_status, x.intl_fee, x.intl_qty, x.intl_weight, x.tranship_comment, x.comment AS intl_comment
FROM order_items i
LEFT JOIN purchases p ON p.order_item_id = i.id
LEFT JOIN jp_shipments j ON j.order_item_id = i.id
LEFT JOIN domestic_shipments d ON d.order_item_id = i.id
LEFT JOIN intl_shipments x ON x.order_item_id = i.id
WHERE i.order_id = ?
ORDER BY i.id
SQL);
        $stmt->execute([$orderId]);

        return array_map(function (array $row) use ($pdo, $orderId): array {
            $extra = $this->jsonArray($row['platform_extra'] ?? null);
            $quantity = (int) ($row['quantity'] ?? 0);
            $unitPrice = $this->moneyValue($row['unit_price'] ?? null);
            if ($unitPrice <= 0) {
                $unitPrice = $this->firstMoney($extra, ['UnitPrice', 'itemPrice']);
            }
            $postagePrice = $this->moneyValue($row['postage_price'] ?? null);
            if ($postagePrice <= 0) {
                $postagePrice = $this->firstMoney($extra, ['postagePrice', 'ShipCharge']);
            }
            $payCharge = $this->moneyValue($row['pay_charge'] ?? null);
            if ($payCharge <= 0) {
                $payCharge = $this->firstMoney($extra, ['PayCharge']);
            }
            $lineTotal = $this->moneyValue($row['line_total'] ?? null);
            if ($lineTotal <= 0) {
                $lineTotal = $this->firstMoney($extra, ['totalItemPrice', 'TotalPrice']);
            }
            if ($lineTotal <= 0 && $unitPrice > 0) {
                $lineTotal = ($unitPrice * max(1, $quantity)) + $postagePrice + $payCharge;
            }
            $purchaseAmount = $this->moneyValue($row['amount'] ?? null);

            return [
                'id' => (int) $row['id'],
                'order_detail_id' => (string) (($row['order_detail_id'] ?? '') ?: $this->firstExtra($extra, ['orderDetailId'])),
                'line_id' => (string) (($row['line_id'] ?? '') ?: $this->firstExtra($extra, ['LineId'])),
                'item_code' => (string) (($row['item_code'] ?? '') ?: $this->firstExtra($extra, ['ItemId', 'itemCode', 'itemManagementId', 'lotnumber'])),
                'lot_number' => (string) (($row['lot_number'] ?? '') ?: $this->firstExtra($extra, ['lotnumber'])),
                'item_management_id' => (string) (($row['item_management_id'] ?? '') ?: $this->firstExtra($extra, ['ItemManagerId', 'itemManagementId'])),
                'jp_warehouse_id' => (string) ($row['jp_warehouse_id'] ?? ''),
                'title' => (string) (($row['product_title'] ?? '') ?: $this->firstExtra($extra, ['product_title', 'ItemTitle', 'itemName'])),
                'option' => (string) (($row['item_option'] ?? '') ?: $this->firstExtra($extra, ['SubCodeOption', 'itemOption', 'selectedChoice'])),
                'chinese_option' => (string) (($row['chinese_option'] ?? '') ?: $this->firstExtra($extra, ['chinese_option'])),
                'quantity' => $quantity,
                'source_type' => (string) $row['source_type'],
                'purchase_status' => (string) $row['purchase_status'],
                'buyer' => (string) (($row['purchase_user'] ?? '') ?: ($row['caigou_user'] ?? '')),
                'purchase_time' => (string) ($row['caigou_time'] ?? ''),
                'purchase_link' => (string) ($row['caigou_link'] ?? ''),
                'buhuo_link' => (string) ($row['buhuo_link'] ?? ''),
                'amount' => $purchaseAmount,
                'purchase_amount' => $purchaseAmount,
                'cn_amount' => $this->moneyValue($row['cn_amount'] ?? null),
                'com_amount' => $this->moneyValue($row['com_amount'] ?? null),
                'caigou_ordernums' => (string) ($row['caigou_ordernums'] ?? ''),
                'unit_price' => $unitPrice,
                'postage_price' => $postagePrice,
                'pay_charge' => $payCharge,
                'line_total' => $lineTotal,
                'material' => (string) ($row['material'] ?? ''),
                'weight' => $this->moneyValue($row['weight'] ?? null),
                'comment' => (string) (($row['item_comment'] ?? '') ?: $this->firstExtra($extra, ['comment'])),
                'tabaono' => (string) ($row['tabaono'] ?? ''),
                'ship_company' => (string) ($row['ship_company'] ?? ''),
                'ship_number' => (string) (($row['cn_ship_number'] ?? '') ?: ($row['ship_number'] ?? '')),
                'ship_quantity' => (int) ($row['ship_quantity'] ?? 0),
                'receipt_city' => (string) ($row['receipt_city'] ?? ''),
                'logistics' => (string) ($row['jpship_status'] ?? ''),
                'logistic_trace' => (string) ($row['logistic_trace'] ?? ''),
                'jpship_completed_at' => (string) ($row['jpship_completed_at'] ?? ''),
                'assignee' => $row['assignee'] ?? null,
                'out_status' => $row['out_status'] ?? null,
                'out_time' => (string) ($row['out_time'] ?? ''),
                'location' => (string) ($row['location'] ?? ''),
                'out_no' => (string) ($row['out_no'] ?? ''),
                'out_cost' => $this->moneyValue($row['out_cost'] ?? null),
                'intl_number' => (string) ($row['intl_number'] ?? ''),
                'intl_status' => (string) ($row['intl_status'] ?? ''),
                'intl_fee' => $this->moneyValue($row['intl_fee'] ?? null),
                'intl_qty' => (int) ($row['intl_qty'] ?? 0),
                'intl_weight' => $this->moneyValue($row['intl_weight'] ?? null),
                'tranship_comment' => (string) ($row['tranship_comment'] ?? ''),
                'intl_comment' => (string) ($row['intl_comment'] ?? ''),
                'image' => (string) (($row['main_image'] ?? '') ?: $this->firstExtra($extra, ['zhutu'], '/assets/no-image.svg')),
                'platform_extra' => $extra,
                'logs' => $this->logsForItem($pdo, $orderId, (int) $row['id']),
            ];
        }, $stmt->fetchAll());
    }



    /** @return array<int, array<string, mixed>> */
    private function logsForItem(\PDO $pdo, int $orderId, int $itemId): array
    {
        $stmt = $pdo->prepare(
            'SELECT operator, action_type, field_name, old_value, new_value, ip, created_at FROM order_logs WHERE order_id = ? AND (order_item_id = ? OR order_item_id IS NULL) ORDER BY created_at DESC LIMIT 20'
        );
        $stmt->execute([$orderId, $itemId]);

        return array_map(fn (array $row): array => [
            'time' => date('m-d H:i', strtotime((string) $row['created_at'])),
            'user' => (string) $row['operator'],
            'action' => (string) $row['action_type'],
            'field' => (string) $row['field_name'],
            'old' => (string) ($row['old_value'] ?? '-'),
            'new' => (string) ($row['new_value'] ?? '-'),
            'ip' => (string) $row['ip'],
        ], $stmt->fetchAll());
    }



    /** @param array<string, mixed> $row @param array<string, mixed>|null $user */
    private function eventVisibleToUser(array $row, ?array $user): bool
    {
        return $user === null || Permission::canAccessStore($user, (string) ($row['store_name'] ?? ''));
    }
}
