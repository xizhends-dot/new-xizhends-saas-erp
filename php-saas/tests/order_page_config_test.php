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

$fields = $registry->filterFieldsFor('r');
$fieldKeys = array_column($fields, 'key');
$expectedKeys = [
    'order_no',
    'tabaono',
    'customer_name',
    'phone',
    'mail',
    'cn_ship_no',
    'intl_ship_no',
    'status',
    'receipt_city',
    'page_size',
];
foreach ($expectedKeys as $key) {
    $checkTrue("通用筛选字段包含 {$key}", in_array($key, $fieldKeys, true));
}
$checkFalse('通用筛选字段不包含店铺', in_array('store', $fieldKeys, true));
$fieldByKey = [];
foreach ($fields as $field) {
    $fieldByKey[(string) $field['key']] = $field;
}
$check('采购状态字段类型', $fieldByKey['status']['type'] ?? null, 'select');
$check('采购状态字段选项来源', $fieldByKey['status']['optionsKey'] ?? null, 'statusOptions');
$check('每页显示字段类型', $fieldByKey['page_size']['type'] ?? null, 'select');
$check('每页显示选项数', count($fieldByKey['page_size']['options'] ?? []), 4);

$toolMap = static function (array $tools): array {
    $result = [];
    foreach ($tools as $tool) {
        $result[(string) $tool['key']] = (bool) ($tool['visibleWhen'] ?? false);
    }

    return $result;
};

$adminTools = $toolMap($registry->exportToolsFor('r', ['role' => '公司管理员']));
foreach (['sync_orders', 'platform_orders_import', 'shipping_import', 'shipment_export', 'finance_export', 'customers_export'] as $key) {
    $checkTrue("公司管理员可见 {$key}", $adminTools[$key] ?? false);
}

$importUserTools = $toolMap($registry->exportToolsFor('r', ['role' => '客服', 'permissions' => ['导入导出']]));
$checkTrue('导入导出用户可见同步订单', $importUserTools['sync_orders'] ?? false);
$checkTrue('导入导出用户可见平台订单导入', $importUserTools['platform_orders_import'] ?? false);
$checkTrue('导入导出用户可见国际运单导入', $importUserTools['shipping_import'] ?? false);
$checkTrue('导入导出用户可见发货表导出', $importUserTools['shipment_export'] ?? false);
$checkTrue('导入导出用户可见财务表导出', $importUserTools['finance_export'] ?? false);

$financeTools = $toolMap($registry->exportToolsFor('r', ['role' => '客服', 'permissions' => ['财务导出']]));
$checkTrue('财务导出用户可见财务表导出', $financeTools['finance_export'] ?? false);
$checkFalse('财务导出用户不可见平台订单导入', $financeTools['platform_orders_import'] ?? false);

$customerTools = $toolMap($registry->exportToolsFor('r', ['role' => '客服', 'permissions' => ['客户资料']]));
$checkTrue('客户资料用户可见客户资料导出', $customerTools['customers_export'] ?? false);
$checkFalse('客户资料用户不可见发货表导出', $customerTools['shipment_export'] ?? false);

$editorTools = $toolMap($registry->exportToolsFor('r', ['role' => '客服', 'permissions' => ['订单编辑']]));
$checkTrue('订单编辑用户可见同步订单', $editorTools['sync_orders'] ?? false);
$checkFalse('订单编辑用户不可见平台订单导入', $editorTools['platform_orders_import'] ?? false);

$plainTools = $toolMap($registry->exportToolsFor('r', ['role' => '采购', 'permissions' => []]));
foreach ($plainTools as $key => $visible) {
    $checkFalse("无导入导出/订单编辑/客户资料权限用户不可见 {$key}", $visible);
}

if ($failures !== []) {
    echo "OrderPageConfigRegistry test FAILED:\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}

echo "OrderPageConfigRegistry test OK\n";
