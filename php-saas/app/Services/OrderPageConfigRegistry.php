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
            ['key' => 'order_no', 'label' => '订单号', 'type' => 'text', 'section' => 'basic', 'views' => ['platform', 'purchase', 'jp']],
            ['key' => 'tabaono', 'label' => '1688订单号', 'type' => 'text', 'section' => 'basic', 'views' => ['platform', 'purchase']],
            ['key' => 'customer_name', 'label' => '收件人姓名', 'type' => 'text', 'section' => 'basic', 'views' => ['platform']],
            ['key' => 'phone', 'label' => '客人电话', 'type' => 'text', 'section' => 'basic', 'views' => ['platform']],
            ['key' => 'mail', 'label' => '客人邮箱', 'type' => 'text', 'section' => 'basic', 'views' => ['platform']],
            ['key' => 'cn_ship_no', 'label' => '国内发货单号', 'type' => 'text', 'section' => 'basic', 'views' => ['platform', 'purchase']],
            ['key' => 'intl_ship_no', 'label' => '国际发货单号', 'type' => 'text', 'section' => 'basic', 'views' => ['platform', 'purchase', 'jp']],
            ['key' => 'status', 'label' => '采购状态', 'type' => 'select', 'optionsKey' => 'statusOptions', 'section' => 'basic', 'views' => ['platform', 'purchase', 'jp']],
            ['key' => 'store', 'label' => '店铺', 'type' => 'select', 'optionsKey' => 'storeNames', 'section' => 'basic', 'views' => ['platform', 'jp']],
            ['key' => 'source', 'label' => '货源地', 'type' => 'select', 'section' => 'basic', 'views' => ['platform'], 'options' => [
                ['value' => 'all', 'label' => '全部货源地'],
                ['value' => 'jp_stock', 'label' => '日本仓'],
                ['value' => 'cn_purchase', 'label' => '国内采购'],
                ['value' => 'pending', 'label' => '待定'],
            ]],
            ['key' => 'receipt_city', 'label' => '国内签收地', 'type' => 'text', 'section' => 'basic', 'views' => ['platform', 'purchase']],
            ['key' => 'page_size', 'label' => '每页显示', 'type' => 'select', 'section' => 'basic', 'views' => ['platform', 'purchase', 'jp'], 'options' => [
                ['value' => '100', 'label' => '100'],
                ['value' => '200', 'label' => '200'],
                ['value' => '500', 'label' => '500'],
                ['value' => '1000', 'label' => '1000'],
            ]],
            ['key' => 'item_id', 'label' => 'ItemId / 商品名', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform', 'purchase', 'jp']],
            ['key' => 'item_management_id', 'label' => '商品管理ID', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform', 'purchase']],
            ['key' => 'order_detail_id', 'label' => '订单明细ID', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform']],
            ['key' => 'lot_number', 'label' => 'lotNumber', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform', 'purchase']],
            ['key' => 'lot_number_empty', 'label' => 'lotNumber为空', 'type' => 'checkbox', 'section' => 'advanced', 'views' => ['platform']],
            ['key' => 'kana', 'label' => '片假名', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform']],
            ['key' => 'product_name', 'label' => '商品标题', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform', 'purchase']],
            ['key' => 'pay_method', 'label' => '支付方式', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform']],
            ['key' => 'ship_method', 'label' => '运送方式', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform']],
            ['key' => 'material', 'label' => '材质', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform']],
            ['key' => 'location', 'label' => '仓位', 'type' => 'text', 'section' => 'advanced', 'views' => ['jp']],
            ['key' => 'carrier', 'label' => '物流公司', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform', 'purchase', 'jp']],
            ['key' => 'buyer', 'label' => '采购人 / 发货员', 'type' => 'select', 'section' => 'advanced', 'views' => ['purchase', 'jp'], 'options' => [
                ['value' => '王五', 'label' => '王五'],
                ['value' => '李四', 'label' => '李四'],
                ['value' => '赵六', 'label' => '赵六'],
            ]],
            ['key' => 'purchase_link', 'label' => '采购链接', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform', 'purchase']],
            ['key' => 'comment', 'label' => '订单备注', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform', 'purchase']],
            ['key' => 'purchase_comment', 'label' => '采购备注', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform']],
            ['key' => 'intl_ship_empty', 'label' => '国际单号为空', 'type' => 'checkbox', 'section' => 'advanced', 'views' => ['platform']],
            ['key' => 'date_range', 'label' => '日期范围', 'type' => 'date_range', 'from' => 'date_from', 'to' => 'date_to', 'section' => 'advanced', 'views' => ['platform', 'purchase', 'jp']],
            ['key' => 'late_ship', 'label' => '超时发货', 'type' => 'checkbox', 'section' => 'flags', 'views' => ['platform', 'purchase']],
            ['key' => 'in_delivery', 'label' => '配達中', 'type' => 'checkbox', 'section' => 'flags', 'views' => ['platform', 'jp']],
            ['key' => 'delivered', 'label' => '配達完了', 'type' => 'checkbox', 'section' => 'flags', 'views' => ['platform', 'jp']],
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
                'group' => 'sync',
                'needsDateRange' => false,
                'visibleWhen' => Permission::hasAny($user, ['导入导出', '订单编辑']),
            ],
            [
                'key' => 'platform_orders_import',
                'label' => '平台订单导入',
                'action' => '/import-export',
                'method' => 'get',
                'job' => 'platform_orders_import',
                'group' => 'import',
                'needsDateRange' => false,
                'visibleWhen' => Permission::has($user, '导入导出'),
            ],
            [
                'key' => 'shipping_import',
                'label' => '国际运单导入',
                'action' => '/import-export',
                'method' => 'get',
                'job' => 'shipping_import',
                'group' => 'import',
                'needsDateRange' => false,
                'visibleWhen' => Permission::has($user, '导入导出'),
            ],
            [
                'key' => 'shipment_export',
                'label' => '发货表导出',
                'action' => '/orders/export',
                'method' => 'post',
                'type' => 'shipment',
                'group' => 'shipment',
                'needsDateRange' => true,
                'visibleWhen' => Permission::has($user, '导入导出'),
            ],
            [
                'key' => 'platform_export',
                'label' => '平台订单表导出',
                'action' => '/orders/export',
                'method' => 'post',
                'type' => 'platform',
                'group' => 'shipment',
                'needsDateRange' => true,
                'visibleWhen' => Permission::has($user, '导入导出'),
            ],
            [
                'key' => 'finance_export',
                'label' => '财务表导出',
                'action' => '/import-export/finance-placeholder/export',
                'method' => 'get',
                'group' => 'finance',
                'needsDateRange' => true,
                'visibleWhen' => Permission::hasAny($user, ['导入导出', '财务导出']),
            ],
            [
                'key' => 'customers_export',
                'label' => '客户资料导出',
                'action' => '/import-export/customers/export',
                'method' => 'get',
                'group' => 'finance',
                'needsDateRange' => true,
                'visibleWhen' => Permission::has($user, '客户资料'),
            ],
            [
                'key' => 'delivery_notice_export',
                'label' => '发货通知表导出',
                'action' => '/orders/export',
                'method' => 'post',
                'type' => 'delivery_notice',
                'group' => 'delivery',
                'needsDateRange' => true,
                'visibleWhen' => Permission::has($user, '导入导出'),
            ],
            [
                'key' => 'xizhen_delivery_export',
                'label' => '西阵发货表导出',
                'action' => '/orders/xizhen-delivery/export',
                'method' => 'post',
                'group' => 'delivery',
                'needsDateRange' => true,
                'visibleWhen' => Permission::has($user, '导入导出'),
            ],
        ];
    }
}
