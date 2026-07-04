<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class OrderAutoCompleteService
{
    private const URL_PATTERN = '/https?:\/\/\S+/i';

    public function __construct(private readonly StoreInterface $store)
    {
    }

    /**
     * @return array{ok: bool, message: string, scanned: int, updated: int, skipped: int, failed: int}
     */
    public function run(string $tenantKey): array
    {
        $items = $this->flattenItems($this->store->orders($tenantKey));
        $groups = $this->groupsByItemCode($items);
        $summary = ['ok' => true, 'message' => '', 'scanned' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($items as $item) {
            $summary['scanned']++;
            $itemId = (int) ($item['id'] ?? 0);
            $itemCode = trim((string) ($item['item_code'] ?? ''));
            if ($itemId <= 0 || $itemCode === '') {
                $summary['skipped']++;
                continue;
            }

            $updated = 0;
            $attempted = false;
            $candidates = $groups[$itemCode] ?? [];
            if (trim((string) ($item['purchase_link'] ?? '')) === '') {
                $value = $this->purchaseLinkFromCandidates($itemId, $candidates);
                if ($value !== '') {
                    $attempted = true;
                    $updated += $this->updateField($tenantKey, $itemId, 'purchase_link', $value, '自动填充采购链接', $summary);
                }
            }

            if (trim((string) ($item['material'] ?? '')) === '') {
                $value = $this->fieldFromCandidates($itemId, $candidates, 'material');
                if ($value !== '') {
                    $attempted = true;
                    $updated += $this->updateField($tenantKey, $itemId, 'material', $value, '自动填充备注', $summary);
                }
            }

            if (trim((string) ($item['tranship_comment'] ?? '')) === '') {
                $value = $this->fieldFromCandidates($itemId, $candidates, 'tranship_comment');
                if ($value !== '') {
                    $attempted = true;
                    $updated += $this->updateField($tenantKey, $itemId, 'tranship_comment', $value, '自动填充转运备注', $summary);
                }
            }

            if ($updated === 0 && !$attempted) {
                $summary['skipped']++;
            }
        }

        $summary['ok'] = $summary['failed'] === 0;
        $summary['message'] = sprintf(
            '扫描 %d 条，更新 %d 次，跳过 %d 条，失败 %d 次。',
            $summary['scanned'],
            $summary['updated'],
            $summary['skipped'],
            $summary['failed']
        );

        return $summary;
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private function flattenItems(array $orders): array
    {
        $items = [];
        foreach ($orders as $order) {
            foreach (($order['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $item['order_id'] = (int) ($order['id'] ?? 0);
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupsByItemCode(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $itemCode = trim((string) ($item['item_code'] ?? ''));
            if ($itemCode === '') {
                continue;
            }
            $groups[$itemCode][] = $item;
        }

        foreach ($groups as &$group) {
            usort(
                $group,
                static function (array $left, array $right): int {
                    $orderCompare = (int) ($right['order_id'] ?? 0) <=> (int) ($left['order_id'] ?? 0);
                    if ($orderCompare !== 0) {
                        return $orderCompare;
                    }

                    return (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0);
                }
            );
        }
        unset($group);

        return $groups;
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function purchaseLinkFromCandidates(int $itemId, array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if ((int) ($candidate['id'] ?? 0) === $itemId) {
                continue;
            }

            $purchaseLink = (string) ($candidate['purchase_link'] ?? '');
            if ($purchaseLink === '' || str_contains($purchaseLink, '缺货')) {
                continue;
            }

            if (preg_match_all(self::URL_PATTERN, $purchaseLink, $matches) === false || ($matches[0] ?? []) === []) {
                continue;
            }

            return implode("\r\n", $matches[0]);
        }

        return '';
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function fieldFromCandidates(int $itemId, array $candidates, string $field): string
    {
        foreach ($candidates as $candidate) {
            if ((int) ($candidate['id'] ?? 0) === $itemId) {
                continue;
            }

            $value = (string) ($candidate[$field] ?? '');
            if (trim($value) !== '') {
                return $value;
            }
        }

        return '';
    }

    /** @param array{updated: int, failed: int} $summary */
    private function updateField(string $tenantKey, int $itemId, string $field, string $value, string $action, array &$summary): int
    {
        try {
            $this->store->updateOrderItem($tenantKey, $itemId, [$field => $value], '系统:order_monitor', $action);
            $summary['updated']++;
            return 1;
        } catch (\Throwable) {
            $summary['failed']++;
            return 0;
        }
    }
}
