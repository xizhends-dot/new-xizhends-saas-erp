<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;
use Xizhen\Core\View;

final class OrderAjaxService
{
    public function __construct(private readonly StoreInterface $store, private readonly AppService $app, private readonly View $view)
    {
    }

    /**
     * @param array<string, mixed>|null $user
     * @param array<string, mixed> $context
     * @return array{ok: bool, status: int, message: string, html?: string, order?: array<string, mixed>}
     */
    public function orderRow(string $tenantKey, int $orderId, ?array $user, array $context = []): array
    {
        $order = $this->accessibleOrder($tenantKey, $orderId, $user);
        if (!$order) {
            return ['ok' => false, 'status' => 404, 'message' => '订单不存在或无权访问。'];
        }

        $html = $this->renderPartial('tenant/partials/order_block', array_merge($context, [
            'tenantKey' => $tenantKey,
            'order' => $order,
            'seq' => (int) ($context['seq'] ?? 1),
            'returnUrl' => (string) ($context['returnUrl'] ?? tenant_url('/orders', $tenantKey)),
            'orderView' => (string) ($context['orderView'] ?? 'platform'),
            'statusOptions' => $this->app->purchaseStatuses($tenantKey),
        ]));

        return ['ok' => true, 'status' => 200, 'message' => '订单行已刷新。', 'html' => $html, 'order' => $order];
    }

    /**
     * @param array<string, mixed>|null $user
     * @return array{ok: bool, status: int, message: string, html?: string, order?: array<string, mixed>}
     */
    public function orderDetail(string $tenantKey, int $orderId, ?array $user): array
    {
        $order = $this->accessibleOrder($tenantKey, $orderId, $user);
        if (!$order) {
            return ['ok' => false, 'status' => 404, 'message' => '订单不存在或无权访问。'];
        }

        $html = $this->renderPartial('tenant/partials/order_detail_ajax', [
            'tenantKey' => $tenantKey,
            'order' => $order,
            'attachments' => $this->store->orderAttachments($tenantKey, $orderId),
            'statusOptions' => $this->app->purchaseStatuses($tenantKey),
        ]);

        return ['ok' => true, 'status' => 200, 'message' => '订单详情已刷新。', 'html' => $html, 'order' => $order];
    }

    /**
     * @param array<string, mixed>|null $user
     * @return array{ok: bool, status: int, message: string, items?: array<int, array<string, mixed>>}
     */
    public function logisticsReload(string $tenantKey, int $orderId, ?array $user): array
    {
        $order = $this->accessibleOrder($tenantKey, $orderId, $user);
        if (!$order) {
            return ['ok' => false, 'status' => 404, 'message' => '订单不存在或无权访问。'];
        }

        $items = [];
        foreach ((array) ($order['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = [
                'item_id' => (int) ($item['id'] ?? 0),
                'item_code' => (string) (($item['item_code'] ?? '') ?: ($item['lot_number'] ?? '')),
                'ship_company' => (string) ($item['ship_company'] ?? ''),
                'ship_number' => (string) ($item['ship_number'] ?? ''),
                'domestic_status' => (string) (($item['logistics'] ?? '') ?: ($item['logistic_trace'] ?? '')),
                'intl_number' => (string) ($item['intl_number'] ?? ''),
                'intl_status' => (string) ($item['intl_status'] ?? ''),
                'jpship_completed_at' => (string) ($item['jpship_completed_at'] ?? ''),
            ];
        }

        return ['ok' => true, 'status' => 200, 'message' => '物流状态已刷新。', 'items' => $items];
    }

    /**
     * @param array<string, mixed>|null $user
     * @return array{ok: bool, status: int, message: string, review_invited?: bool, reviewed?: bool}
     */
    public function toggleReview(string $tenantKey, int $orderId, string $field, ?array $user, string $operator): array
    {
        $field = $field === 'reviewed' ? 'reviewed' : 'review_invited';
        $order = $this->accessibleOrder($tenantKey, $orderId, $user);
        if (!$order) {
            return ['ok' => false, 'status' => 404, 'message' => '订单不存在或无权访问。'];
        }

        $next = empty($order[$field]);
        $this->store->updateOrderFlags($tenantKey, $orderId, [$field => $next], $operator);
        $order = $this->store->order($tenantKey, $orderId) ?: $order;

        return [
            'ok' => true,
            'status' => 200,
            'message' => $field === 'reviewed' ? '评价状态已更新。' : '邀评状态已更新。',
            'review_invited' => !empty($order['review_invited']),
            'reviewed' => !empty($order['reviewed']),
        ];
    }

    /** @param array<string, mixed>|null $user @return array<string, mixed>|null */
    private function accessibleOrder(string $tenantKey, int $orderId, ?array $user): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        foreach ($this->app->ordersForUser($tenantKey, $user) as $order) {
            if ((int) ($order['id'] ?? 0) === $orderId) {
                return $order;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function renderPartial(string $template, array $data): string
    {
        return $this->view->partial($template, $data);
    }
}
