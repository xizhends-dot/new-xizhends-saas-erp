<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
require $basePath . '/app/Services/ExportFieldRegistry.php';

use Xizhen\Services\ExportFieldRegistry;

$failures = [];
$check = static function (string $name, mixed $actual, mixed $expected) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = sprintf('%s: expected %s, got %s', $name, var_export($expected, true), var_export($actual, true));
    }
};

$order = [
    'platform_order_id' => 'W-1001',
    'store' => '一号店',
    'order_date' => '2026-07-01 10:00:00',
    'platform' => 'w',
    'ship_method' => 'ヤマト',
    'customer' => [
        'name' => '山田太郎',
        'phone' => '9012345678',
        'zip' => '1500001',
        'prefecture' => '東京都',
        'city' => '渋谷区',
        'address1' => '神南1-2-3',
        'address2' => '',
    ],
];
$item = [
    'order_detail_id' => 'D-01',
    'title' => '茶碗',
    'option' => 'Red',
    'chinese_option' => '红色',
    'quantity' => 3,
    'weight' => '1.2',
    'material' => '陶器',
    'comment' => '备注A',
    'tranship_comment' => '转运B',
    'ship_company' => '申通',
    'ship_number' => '77300012345',
    'intl_number' => '3680001112223',
    'intl_status' => '已签收',
    'logistics' => '在途',
    'logistic_trace' => '东京营业所',
    'unit_price' => '2900',
    'amount' => '15.5',
    'cn_amount' => '8',
    'com_amount' => '2',
    'sku_image' => 'storage/tenants/erp/img/a.jpg',
];

$check('订单号', ExportFieldRegistry::resolve('order.platform_order_id', $order, $item), 'W-1001');
$check('店铺名', ExportFieldRegistry::resolve('order.store', $order, $item), '一号店');
$check('明细ID', ExportFieldRegistry::resolve('item.order_detail_id', $order, $item), 'D-01');
$check('明细ID回退订单号', ExportFieldRegistry::resolve('item.order_detail_id', $order, ['order_detail_id' => ''] + $item), 'W-1001');
$check('电话补0', ExportFieldRegistry::resolve('customer.phone', $order, $item), '09012345678');
$check('电话已有0不重复补', ExportFieldRegistry::resolve('customer.phone', ['customer' => ['phone' => '090-1234']] + $order, $item), '090-1234');
$check('邮编7位不动', ExportFieldRegistry::resolve('customer.zip', $order, $item), '1500001');
$check('邮编不足补0', ExportFieldRegistry::resolve('customer.zip', ['customer' => ['zip' => '54321']] + $order, $item), '0054321');
$check('地址拼接', ExportFieldRegistry::resolve('customer.address', $order, $item), '東京都渋谷区神南1-2-3');
$check('地址回退address', ExportFieldRegistry::resolve('customer.address', ['customer' => ['address' => '整体地址']] + $order, $item), '整体地址');
$check('中文规格', ExportFieldRegistry::resolve('item.chinese_option', $order, $item), '红色');
$check('中文规格回退option', ExportFieldRegistry::resolve('item.chinese_option', $order, ['chinese_option' => ''] + $item), 'Red');
$check('数量int', ExportFieldRegistry::resolve('item.quantity', $order, $item), 3);
$check('数量最小1', ExportFieldRegistry::resolve('item.quantity', $order, ['quantity' => 0] + $item), 1);
$check('国内快递公司', ExportFieldRegistry::resolve('logistics.ship_company', $order, $item), '申通');
$check('国内快递公司回退ship_method', ExportFieldRegistry::resolve('logistics.ship_company', $order, ['ship_company' => ''] + $item), 'ヤマト');
$check('国内单号拼接', ExportFieldRegistry::resolve('logistics.domestic_full', $order, $item), '申通 77300012345');
$check('国际单号', ExportFieldRegistry::resolve('logistics.intl_tracking', $order, $item), '3680001112223');
$check('国际单号回退国内', ExportFieldRegistry::resolve('logistics.intl_tracking', $order, ['intl_number' => ''] + $item), '77300012345');
$check('国际状态回退logistics', ExportFieldRegistry::resolve('logistics.intl_status', $order, ['intl_status' => ''] + $item), '在途');
$check('USD折算', ExportFieldRegistry::resolve('money.usd_unit_price', $order, $item), '10');
$check('USD为0输出空', ExportFieldRegistry::resolve('money.usd_unit_price', $order, ['unit_price' => '0'] + $item), '');
$check('图片回退链', ExportFieldRegistry::resolve('item.image', $order, ['sku_image' => '', 'main_image' => 'm.jpg'] + $item), 'm.jpg');
$check('wowma代码368', ExportFieldRegistry::resolve('generated.wowma_carrier_code', $order, $item), '1');
$check('wowma代码361', ExportFieldRegistry::resolve('generated.wowma_carrier_code', $order, ['intl_number' => '3610009'] + $item), '2');
$check('wowma无匹配回退公司', ExportFieldRegistry::resolve('generated.wowma_carrier_code', $order, ['intl_number' => '999', 'ship_company' => 'EMS'] + $item), 'EMS');
$check('今天md', ExportFieldRegistry::resolve('generated.today_md', $order, $item), date('m-d'));
$check('今天ymd', ExportFieldRegistry::resolve('generated.today_ymd', $order, $item), date('Y/m/d'));
$check('未知key返回空', ExportFieldRegistry::resolve('no.such_key', $order, $item), '');
$check('has已知', ExportFieldRegistry::has('order.platform_order_id'), true);
$check('has未知', ExportFieldRegistry::has('no.such_key'), false);
$check('image类型标记', ExportFieldRegistry::fields()['item.image']['type'], 'image');

$groups = ExportFieldRegistry::groups();
if (!isset($groups['订单'], $groups['收件人'], $groups['商品'], $groups['物流'], $groups['金额'], $groups['图片'], $groups['生成值'])) {
    $failures[] = 'groups() 缺少预期分组: ' . implode(',', array_keys($groups));
}

if ($failures !== []) {
    echo "ExportFieldRegistry test FAILED:\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}
echo "ExportFieldRegistry test OK (" . count(ExportFieldRegistry::fields()) . " fields)\n";
