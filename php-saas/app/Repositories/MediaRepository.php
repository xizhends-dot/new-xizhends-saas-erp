<?php

declare(strict_types=1);

namespace Xizhen\Repositories;

final class MediaRepository extends BaseRepository
{


    public function updateOrderItemImage(string $tenantKey, int $itemId, string $kind, string $path): void
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo || $itemId <= 0 || !in_array($kind, ['main', 'sku'], true)) {
            return;
        }

        $column = $kind === 'sku' ? 'sku_image' : 'main_image';
        $stmt = $tenantPdo->prepare("SELECT id, order_id, {$column} AS old_path FROM order_items WHERE id = ? LIMIT 1");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if (!$item) {
            return;
        }

        $update = $tenantPdo->prepare("UPDATE order_items SET {$column} = ? WHERE id = ?");
        $update->execute([$path, $itemId]);
        $this->insertItemLog(
            $tenantPdo,
            (int) $item['order_id'],
            $itemId,
            $kind === 'sku' ? '替换SKU图' : '替换主图',
            $column,
            (string) ($item['old_path'] ?? ''),
            $path
        );
    }



    /** @return array<int, array<string, mixed>> */
    public function orderAttachments(string $tenantKey, int $orderId): array
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo || $orderId <= 0 || !$this->tableExists($tenantPdo, 'order_attachments')) {
            return [];
        }

        $stmt = $tenantPdo->prepare('SELECT * FROM order_attachments WHERE order_id = ? AND deleted_at IS NULL ORDER BY created_at DESC, id DESC');
        $stmt->execute([$orderId]);

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'order_id' => (int) $row['order_id'],
            'order_item_id' => (int) ($row['order_item_id'] ?? 0),
            'type' => (string) $row['attachment_type'],
            'title' => (string) $row['title'],
            'path' => (string) $row['path'],
            'source' => (string) $row['source'],
            'uploaded_by' => (string) $row['uploaded_by'],
            'size' => (string) ($row['size_label'] ?? ''),
            'created_at' => (string) $row['created_at'],
        ], $stmt->fetchAll());
    }



    /** @param array<string, mixed> $data */
    public function addOrderAttachment(string $tenantKey, int $orderId, array $data): void
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo || $orderId <= 0 || !$this->tableExists($tenantPdo, 'order_attachments')) {
            return;
        }

        $title = trim((string) ($data['title'] ?? ''));
        $path = trim((string) ($data['path'] ?? ''));
        if ($title === '' || $path === '') {
            return;
        }

        $stmt = $tenantPdo->prepare(
            'INSERT INTO order_attachments (order_id, order_item_id, attachment_type, title, path, source, uploaded_by, size_label) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $orderId,
            (int) ($data['order_item_id'] ?? 0) ?: null,
            trim((string) ($data['type'] ?? '附件')) ?: '附件',
            $title,
            $path,
            trim((string) ($data['source'] ?? '手工登记')) ?: '手工登记',
            trim((string) ($data['uploaded_by'] ?? '租户管理员')) ?: '租户管理员',
            trim((string) ($data['size'] ?? '')),
        ]);
    }



    public function deleteOrderAttachment(string $tenantKey, int $orderId, int $attachmentId): void
    {
        $tenantPdo = $this->db->tenantPdo($tenantKey);
        if (!$tenantPdo || $orderId <= 0 || $attachmentId <= 0 || !$this->tableExists($tenantPdo, 'order_attachments')) {
            return;
        }

        $tenantPdo->prepare('UPDATE order_attachments SET deleted_at = NOW() WHERE id = ? AND order_id = ?')->execute([$attachmentId, $orderId]);
    }
}
