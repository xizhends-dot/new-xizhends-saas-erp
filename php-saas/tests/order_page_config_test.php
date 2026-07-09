<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Xizhen\Services\OrderPageConfigRegistry;

$registry = new OrderPageConfigRegistry();
$failures = [];
$check = static function (string $name, mixed $actual, mixed $expected) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = sprintf('%s: expected %s, got %s', $name, var_export($expected, true), var_export($actual, true));
    }
};
$checkTrue = static function (string $name, bool $actual) use (&$failures): void {
    if (!$actual) {
        $failures[] = $name . ': expected true, got false';
    }
};
$checkFalse = static function (string $name, bool $actual) use (&$failures): void {
    if ($actual) {
        $failures[] = $name . ': expected false, got true';
    }
};
$fieldKeys = static fn (string $platform): array => array_column($registry->filterFieldsFor($platform), 'key');
$fieldByKey = static function (string $platform) use ($registry): array {
    $result = [];
    foreach ($registry->filterFieldsFor($platform) as $field) {
        $result[(string) $field['key']] = $field;
    }

    return $result;
};
$has = static fn (string $platform, string $key): bool => in_array($key, $fieldKeys($platform), true);

$rakutenFields = $fieldByKey('r');
foreach ([
    'order_no',
    'tabaono',
    'customer_name',
    'phone',
    'mail',
    'cn_ship_no',
    'intl_ship_no',
    'status',
    'store',
    'source',
    'receipt_city',
    'page_size',
    'item_id',
    'item_management_id',
    'order_detail_id',
    'kana',
    'ship_method',
    'material',
    'carrier',
    'date_range',
    'late_ship',
    'intl_ship_empty',
    'frb_push',
    'review_invited',
    'reviewed',
    'in_delivery',
    'delivered',
] as $key) {
    $checkTrue("乐天筛选字段包含 {$key}", $has('r', $key));
}
$checkFalse('乐天不显示 lotNumber', $has('r', 'lot_number'));
$checkFalse('乐天不显示 lotNumber 为空', $has('r', 'lot_number_empty'));
$checkFalse('乐天不显示采购链接', $has('r', 'purchase_link'));
$checkFalse('乐天不显示支付方式', $has('r', 'pay_method'));
$check('采购状态字段类型', $rakutenFields['status']['type'] ?? null, 'select');
$check('采购状态字段选项来源', $rakutenFields['status']['optionsKey'] ?? null, 'statusOptions');
$check('店铺字段选项来源', $rakutenFields['store']['optionsKey'] ?? null, 'storeNames');
$check('客人姓名字段标签', $rakutenFields['customer_name']['label'] ?? null, '客人姓名');
$check('采购状态字段位于基础区', $rakutenFields['status']['section'] ?? null, 'basic');
$check('ItemId 查询字段位于基础区', $rakutenFields['item_id']['section'] ?? null, 'basic');
$check('ItemId 查询字段标签', $rakutenFields['item_id']['label'] ?? null, 'ItemId查询');
$check('国内签收地字段类型', $rakutenFields['receipt_city']['type'] ?? null, 'select');
$check('国内签收地字段选项来源', $rakutenFields['receipt_city']['optionsKey'] ?? null, 'receiptCityOptions');
$check('国内物流超时发货字段标签', $rakutenFields['late_ship']['label'] ?? null, '【国内物流】超时发货');
$check('超时发货字段位于标记区', $rakutenFields['late_ship']['section'] ?? null, 'flags');
$check('国际运单状态字段类型', $rakutenFields['intl_ship_empty']['type'] ?? null, 'select');
$check('国际运单状态字段名', $rakutenFields['intl_ship_empty']['name'] ?? null, 'kong');
$check('飞兔推送字段类型', $rakutenFields['frb_push']['type'] ?? null, 'select');
$check('乐天配達中字段名', $rakutenFields['in_delivery']['name'] ?? null, 'haitatsuchuu');
$check('日本配達中字段标签', $rakutenFields['in_delivery']['label'] ?? null, '【日本】配達中');
$check('乐天配達完了字段名', $rakutenFields['delivered']['name'] ?? null, 'haitatsukanryo');
$check('日本配達完了字段标签', $rakutenFields['delivered']['label'] ?? null, '【日本】配達完了');
$check('日期范围字段类型', $rakutenFields['date_range']['type'] ?? null, 'date_range');
$check('乐天日期开始字段名', $rakutenFields['date_range']['from'] ?? null, 'OrderTime');
$check('乐天日期结束字段名', $rakutenFields['date_range']['to'] ?? null, 'OrderTime2');
$check('每页显示字段类型', $rakutenFields['page_size']['type'] ?? null, 'select');
$check('每页显示字段位于控制区', $rakutenFields['page_size']['section'] ?? null, 'control');
$check('每页显示选项数', count($rakutenFields['page_size']['options'] ?? []), 4);

$checkTrue('Yahoo显示邀评', $has('y', 'review_invited'));
$checkTrue('Yahoo显示评价', $has('y', 'reviewed'));
$checkTrue('Yahoo显示采购链接', $has('y', 'purchase_link'));
$checkFalse('Yahoo不显示 lotNumber', $has('y', 'lot_number'));
$check('Yahoo采购链接字段名', ($fieldByKey('y')['purchase_link']['name'] ?? null), 'caigoulink');
$check('Yahoo运送方式字段名', ($fieldByKey('y')['ship_method']['name'] ?? null), 'PayStatus');

$checkTrue('Mercari 显示 lotNumber', $has('m', 'lot_number'));
$checkFalse('Mercari 不显示 lotNumber 为空', $has('m', 'lot_number_empty'));
$checkFalse('Mercari 不显示邀评', $has('m', 'review_invited'));
$checkFalse('Mercari 不显示评价', $has('m', 'reviewed'));
$checkTrue('Mercari 显示飞兔推送', $has('m', 'frb_push'));
$checkTrue('Mercari 显示国际运单状态', $has('m', 'intl_ship_empty'));

$checkTrue('雅拍显示 lotNumber', $has('yp', 'lot_number'));
$checkTrue('雅拍显示 lotNumber 为空', $has('yp', 'lot_number_empty'));
$checkTrue('雅拍显示商品标题', $has('yp', 'product_name'));
$checkFalse('雅拍不显示 ItemId', $has('yp', 'item_id'));
$checkFalse('雅拍不显示运送方式', $has('yp', 'ship_method'));
$checkFalse('雅拍不显示片假名', $has('yp', 'kana'));
$check('雅拍商品标题字段名', ($fieldByKey('yp')['product_name']['name'] ?? null), 'product_title');

$checkTrue('Wowma 显示支付方式', $has('w', 'pay_method'));
$checkFalse('Wowma 不显示 lotNumber', $has('w', 'lot_number'));
$check('Wowma 支付方式字段名', ($fieldByKey('w')['pay_method']['name'] ?? null), 'settlementName');

$checkFalse('Qoo10 不显示邀评', $has('q', 'review_invited'));
$checkFalse('Qoo10 不显示评价', $has('q', 'reviewed'));
$checkFalse('Qoo10 不显示国际运单状态', $has('q', 'intl_ship_empty'));
$checkFalse('Qoo10 不显示飞兔推送', $has('q', 'frb_push'));
$checkFalse('Qoo10 不显示 lotNumber', $has('q', 'lot_number'));

$check('乐天订单号字段名', $registry->fieldNameFor('order_no', 'r'), 'orderId');
$check('Yahoo订单号字段名', $registry->fieldNameFor('order_no', 'y'), 'orderId');
foreach (['yp', 'w', 'm', 'q'] as $platform) {
    $check("{$platform} 订单号字段名", $registry->fieldNameFor('order_no', $platform), 'ziid');
}
foreach (['r', 'y', 'yp', 'w', 'm', 'q'] as $platform) {
    $checkTrue("{$platform} 显示国内物流超时发货", $has($platform, 'late_ship'));
    $checkTrue("{$platform} 显示日本配達中", $has($platform, 'in_delivery'));
    $checkTrue("{$platform} 显示日本配達完了", $has($platform, 'delivered'));
}
$check('乐天 ItemId 字段名', $registry->fieldNameFor('item_id', 'r'), 'ItemId');
$check('Yahoo ItemId 字段名', $registry->fieldNameFor('item_id', 'y'), 'ItemId');
foreach (['w', 'm', 'q'] as $platform) {
    $check("{$platform} ItemId 字段名", $registry->fieldNameFor('item_id', $platform), 'itemManagementId');
}
$check('乐天运送方式字段名', $registry->fieldNameFor('ship_method', 'r'), 'yunshu');
$check('Yahoo运送方式字段名', $registry->fieldNameFor('ship_method', 'y'), 'PayStatus');
$check('Wowma 运送方式字段名', $registry->fieldNameFor('ship_method', 'w'), 'deliveryName');
$check('Mercari 运送方式字段名', $registry->fieldNameFor('ship_method', 'm'), 'deliveryName');
$check('Qoo10 运送方式字段名', $registry->fieldNameFor('ship_method', 'q'), 'deliveryName');
$check('乐天片假名字段名', $registry->fieldNameFor('kana', 'r'), 'pianjiaming');
foreach (['y', 'w', 'm', 'q'] as $platform) {
    $check("{$platform} 片假名字段名", $registry->fieldNameFor('kana', $platform), 'senderKana');
}
$check('乐天订单时间字段名', $registry->orderDateFieldFor('r'), 'OrderTime');
$check('Yahoo订单时间字段名', $registry->orderDateFieldFor('y'), 'OrderTime');
foreach (['yp', 'w', 'm', 'q'] as $platform) {
    $check("{$platform} 订单时间字段名", $registry->orderDateFieldFor($platform), 'orderDate');
}

$normalized = $registry->normalizeFilterInput('y', [
    'orderId' => 'Y-100',
    'ItemId' => 'ITEM-1',
    'PayStatus' => '配送中',
    'pianjiaming' => '旧字段不应优先',
    'senderKana' => 'ヤマダ',
    'caigoulink' => 'https://example.test/item',
    'OrderTime' => '2026-07-01',
    'OrderTime2' => '2026-07-02',
]);
$check('normalize 订单号', $normalized['order_no'] ?? null, 'Y-100');
$check('normalize ItemId', $normalized['item_id'] ?? null, 'ITEM-1');
$check('normalize Yahoo PayStatus 特判', $normalized['ship_method'] ?? null, '配送中');
$check('normalize 片假名', $normalized['kana'] ?? null, 'ヤマダ');
$check('normalize 采购链接', $normalized['purchase_link'] ?? null, 'https://example.test/item');

$toolMap = static function (array $tools): array {
    $result = [];
    foreach ($tools as $tool) {
        $result[(string) $tool['key']] = (bool) ($tool['visibleWhen'] ?? false);
    }

    return $result;
};
$toolsByKey = static function (array $tools): array {
    $result = [];
    foreach ($tools as $tool) {
        $result[(string) $tool['key']] = $tool;
    }

    return $result;
};

$adminTools = $toolMap($registry->exportToolsFor('r', ['role' => '公司管理员', 'is_company_admin' => true]));
foreach (['sync_orders', 'platform_orders_import', 'product_image_download', 'purchase_import', 'shipping_import', 'shipment_export', 'platform_export', 'finance_export', 'customers_export', 'delivery_notice_export', 'xizhen_delivery_export', 'export_template'] as $key) {
    $checkTrue("公司管理员可见 {$key}", $adminTools[$key] ?? false);
}

$visibleToolKeys = array_values(array_map(
    static fn (array $tool): string => (string) $tool['key'],
    array_filter($registry->exportToolsFor('r', ['role' => '公司管理员', 'is_company_admin' => true]), static fn (array $tool): bool => !empty($tool['visibleWhen']))
));
$check('可见导出工具顺序', $visibleToolKeys, [
    'sync_orders',
    'platform_orders_import',
    'product_image_download',
    'purchase_import',
    'shipping_import',
    'shipment_export',
    'platform_export',
    'finance_export',
    'customers_export',
    'delivery_notice_export',
    'xizhen_delivery_export',
    'export_template',
]);
$adminToolsByKey = $toolsByKey($registry->exportToolsFor('r', ['role' => '公司管理员', 'is_company_admin' => true]));
foreach (['platform_orders_import', 'product_image_download', 'purchase_import', 'shipping_import', 'shipment_export', 'platform_export', 'export_template'] as $key) {
    $check("常用工具 {$key} 分组", $adminToolsByKey[$key]['group'] ?? null, 'primary');
}
foreach (['finance_export', 'customers_export', 'delivery_notice_export', 'xizhen_delivery_export'] as $key) {
    $check("低频导出 {$key} 分组", $adminToolsByKey[$key]['group'] ?? null, 'more');
}

$importUserTools = $toolMap($registry->exportToolsFor('r', ['role' => '客服', 'permissions' => ['导入导出']]));
$checkTrue('导入导出用户可见同步订单', $importUserTools['sync_orders'] ?? false);
$checkTrue('导入导出用户可见平台订单导入', $importUserTools['platform_orders_import'] ?? false);
$checkTrue('导入导出用户可见下载订单图', $importUserTools['product_image_download'] ?? false);
$checkTrue('导入导出用户可见国际运单导入', $importUserTools['shipping_import'] ?? false);
$checkTrue('导入导出用户可见发货表导出', $importUserTools['shipment_export'] ?? false);
$checkFalse('导入导出用户不可见财务表导出', $importUserTools['finance_export'] ?? false);
$checkFalse('导入导出客服无公司设置不可见模板入口', $importUserTools['export_template'] ?? false);

$buyerRakutenTools = $toolMap($registry->exportToolsFor('r', ['role' => '采购', 'permissions' => ['导入导出', '采购导入导出']]));
$checkTrue('采购角色有导入导出时乐天可见采购单导入', $buyerRakutenTools['purchase_import'] ?? false);
$checkTrue('采购角色有导入导出时沿用现有权限可见同步订单', $buyerRakutenTools['sync_orders'] ?? false);
$checkTrue('采购角色有导入导出时可见平台订单导入', $buyerRakutenTools['platform_orders_import'] ?? false);
$checkTrue('采购角色有导入导出时可见下载订单图', $buyerRakutenTools['product_image_download'] ?? false);
$buyerPurchaseOnlyTools = $toolMap($registry->exportToolsFor('r', ['role' => '采购', 'permissions' => ['采购导入导出']]));
$checkFalse('采购角色仅采购导入导出无导入导出不可见采购单导入', $buyerPurchaseOnlyTools['purchase_import'] ?? false);
$buyerMercariTools = $toolMap($registry->exportToolsFor('m', ['role' => '采购', 'permissions' => []]));
$checkFalse('采购角色默认权限 Mercari 不显示采购单导入', $buyerMercariTools['purchase_import'] ?? false);

$financeTools = $toolMap($registry->exportToolsFor('r', ['role' => '客服', 'permissions' => ['导入导出', '财务导出']]));
$checkTrue('导入导出加财务导出用户可见财务表导出', $financeTools['finance_export'] ?? false);
$checkTrue('导入导出加财务导出用户可见平台订单导入', $financeTools['platform_orders_import'] ?? false);

$financeOnlyTools = $toolMap($registry->exportToolsFor('r', ['role' => '客服', 'permissions' => ['财务导出']]));
$checkFalse('仅财务导出无导入导出不可见财务表导出', $financeOnlyTools['finance_export'] ?? false);

$importWithoutFinanceTools = $toolMap($registry->exportToolsFor('r', ['role' => '客服', 'permissions' => ['导入导出']]));
$checkFalse('有导入导出但无财务导出不可见财务表导出', $importWithoutFinanceTools['finance_export'] ?? false);

$financeNamedTools = $toolMap($registry->exportToolsFor('r', ['role' => '客服', 'username' => 'caiwu', 'permissions' => ['导入导出', '财务导出']]));
$checkTrue('caiwu 用户可见财务表导出', $financeNamedTools['finance_export'] ?? false);

$customerTools = $toolMap($registry->exportToolsFor('r', ['role' => '客服', 'username' => 'xizhends', 'permissions' => ['导入导出', '客户资料']]));
$checkTrue('客户资料用户可见客户资料导出', $customerTools['customers_export'] ?? false);
$checkTrue('客户资料用户有导入导出时可见发货表导出', $customerTools['shipment_export'] ?? false);

$editorTools = $toolMap($registry->exportToolsFor('r', ['role' => '客服', 'permissions' => ['订单编辑']]));
$checkTrue('订单编辑用户可见同步订单', $editorTools['sync_orders'] ?? false);
$checkTrue('订单编辑用户可见下载订单图', $editorTools['product_image_download'] ?? false);
$checkFalse('订单编辑用户不可见平台订单导入', $editorTools['platform_orders_import'] ?? false);

$noImportExportTools = $toolMap($registry->exportToolsFor('r', [
    'role' => '客服',
    'permissions' => ['订单编辑', '财务导出', '客户资料', '公司设置', '采购导入导出'],
]));
$checkTrue('无导入导出但有订单编辑仍可见同步订单', $noImportExportTools['sync_orders'] ?? false);
foreach (['platform_orders_import', 'purchase_import', 'shipping_import', 'shipment_export', 'platform_export', 'finance_export', 'customers_export', 'delivery_notice_export', 'xizhen_delivery_export', 'export_template'] as $key) {
    $checkFalse("无导入导出权限用户不可见导入导出工具 {$key}", $noImportExportTools[$key] ?? false);
}
$checkTrue('无导入导出但有订单编辑仍可见下载订单图', $noImportExportTools['product_image_download'] ?? false);

$plainTools = $toolMap($registry->exportToolsFor('r', [
    'role' => '客服',
    'permissions' => [],
    'permission_overrides' => [
        'deny' => ['导入导出', '订单编辑', '采购导入导出', '财务导出', '客户资料', '公司设置'],
    ],
]));
foreach ($plainTools as $key => $visible) {
    $checkFalse("无导入导出/订单编辑/客户资料权限用户不可见 {$key}", $visible);
}

$templateTools = $toolMap($registry->exportToolsFor('r', ['role' => '客服', 'permissions' => ['公司设置']]));
$checkFalse('仅公司设置无导入导出不可见模板入口', $templateTools['export_template'] ?? false);
$templateWithImportTools = $toolMap($registry->exportToolsFor('r', ['role' => '客服', 'permissions' => ['导入导出', '公司设置']]));
$checkTrue('导入导出加公司设置权限用户可见模板入口', $templateWithImportTools['export_template'] ?? false);

$yahooSyncTools = $toolMap($registry->exportToolsFor('y', ['role' => '客服', 'permissions' => ['订单编辑']]));
$wowmaSyncTools = $toolMap($registry->exportToolsFor('w', ['role' => '客服', 'permissions' => ['订单编辑']]));
$mercariSyncTools = $toolMap($registry->exportToolsFor('m', ['role' => '客服', 'permissions' => ['订单编辑']]));
$qoo10SyncTools = $toolMap($registry->exportToolsFor('q', ['role' => '客服', 'permissions' => ['订单编辑']]));
$ypSyncTools = $toolMap($registry->exportToolsFor('yp', ['role' => '客服', 'permissions' => ['订单编辑']]));
$checkTrue('Yahoo 可见同步订单', $yahooSyncTools['sync_orders'] ?? false);
$checkTrue('Wowma 可见同步订单', $wowmaSyncTools['sync_orders'] ?? false);
$checkFalse('Mercari 不可见同步订单', $mercariSyncTools['sync_orders'] ?? false);
$checkFalse('Qoo10 不可见同步订单', $qoo10SyncTools['sync_orders'] ?? false);
$checkFalse('雅虎拍卖不可见同步订单', $ypSyncTools['sync_orders'] ?? false);

$mercariTools = $registry->exportToolsFor('m', ['role' => '公司管理员', 'is_company_admin' => true]);
$mercariTodo = array_values(array_filter($mercariTools, static fn (array $tool): bool => ($tool['key'] ?? '') === 'mercari_new_import_todo'))[0] ?? [];
$checkTrue('Mercari 新版导入仅标 TODO', !empty($mercariTodo['todo']));
$checkFalse('Mercari 新版导入不显示不可用入口', (bool) ($mercariTodo['visibleWhen'] ?? false));

$ypTools = $registry->exportToolsFor('yp', ['role' => '公司管理员', 'is_company_admin' => true]);
$ypShipment = array_values(array_filter($ypTools, static fn (array $tool): bool => ($tool['key'] ?? '') === 'yahoo_auction_qoo10_shipment_export'))[0] ?? [];
$checkTrue('雅拍出荷处理表复用 Qoo10 预置模板入口', !empty($ypShipment['visibleWhen']));
$check('雅拍出荷处理表动作', $ypShipment['action'] ?? null, '/import-export/platform-special/export');
$check('雅拍出荷处理表模板', $ypShipment['params']['template_id'] ?? null, 'builtin_qoo10');

if ($failures !== []) {
    echo "OrderPageConfigRegistry test FAILED:\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}

echo "OrderPageConfigRegistry test OK\n";
