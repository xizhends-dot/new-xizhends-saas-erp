<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\Permission;

final class OrderPageConfigRegistry
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function filterFieldsFor(string $platform): array
    {
        return [
            ['key' => 'order_no', 'label' => '订单号', 'type' => 'text'],
            ['key' => 'tabaono', 'label' => '1688订单号', 'type' => 'text'],
            ['key' => 'customer_name', 'label' => '收件人姓名', 'type' => 'text'],
            ['key' => 'phone', 'label' => '客人电话', 'type' => 'text'],
            ['key' => 'mail', 'label' => '客人邮箱', 'type' => 'text'],
            ['key' => 'cn_ship_no', 'label' => '国内发货单号', 'type' => 'text'],
            ['key' => 'intl_ship_no', 'label' => '国际发货单号', 'type' => 'text'],
            ['key' => 'status', 'label' => '采购状态', 'type' => 'select', 'optionsKey' => 'statusOptions'],
            ['key' => 'receipt_city', 'label' => '国内签收地', 'type' => 'text'],
            ['key' => 'page_size', 'label' => '每页显示', 'type' => 'select', 'options' => [
                ['value' => '100', 'label' => '100'],
                ['value' => '200', 'label' => '200'],
                ['value' => '500', 'label' => '500'],
                ['value' => '1000', 'label' => '1000'],
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function exportToolsFor(string $platform, array $user): array
    {
        return [
            [
                'key' => 'sync_orders',
                'label' => '同步订单',
                'action' => '/orders/platform/sync',
                'method' => 'post',
                'needsDateRange' => false,
                'visibleWhen' => Permission::hasAny($user, ['导入导出', '订单编辑']),
            ],
            [
                'key' => 'platform_orders_import',
                'label' => '平台订单导入',
                'action' => '/import-export',
                'method' => 'get',
                'job' => 'platform_orders_import',
                'needsDateRange' => false,
                'visibleWhen' => Permission::has($user, '导入导出'),
            ],
            [
                'key' => 'shipping_import',
                'label' => '国际运单导入',
                'action' => '/import-export',
                'method' => 'get',
                'job' => 'shipping_import',
                'needsDateRange' => false,
                'visibleWhen' => Permission::has($user, '导入导出'),
            ],
            [
                'key' => 'shipment_export',
                'label' => '发货表导出',
                'action' => '/orders/export',
                'method' => 'post',
                'type' => 'shipment',
                'needsDateRange' => true,
                'visibleWhen' => Permission::has($user, '导入导出'),
            ],
            [
                'key' => 'finance_export',
                'label' => '财务表导出',
                'action' => '/import-export/finance-placeholder/export',
                'method' => 'get',
                'needsDateRange' => true,
                'visibleWhen' => Permission::hasAny($user, ['导入导出', '财务导出']),
            ],
            [
                'key' => 'customers_export',
                'label' => '客户资料导出',
                'action' => '/import-export/customers/export',
                'method' => 'get',
                'needsDateRange' => true,
                'visibleWhen' => Permission::has($user, '客户资料'),
            ],
        ];
    }
}
