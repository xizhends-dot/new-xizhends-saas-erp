<?php

declare(strict_types=1);

require __DIR__ . '/../app/Core/StoreInterface.php';
require __DIR__ . '/../app/Services/PlatformOrderSyncInterface.php';
require __DIR__ . '/../app/Services/RakutenUrlHelper.php';
require __DIR__ . '/../app/Services/RakutenOrderService.php';

use Xizhen\Services\RakutenOrderService;

$fixture = json_decode((string) file_get_contents(__DIR__ . '/../../old/rakuten_order_response.json'), true);
assert_true(is_array($fixture), 'fixture is valid json');

$model = $fixture['OrderModelList'][0] ?? null;
assert_true(is_array($model), 'fixture has one order model');

$service = (new ReflectionClass(RakutenOrderService::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(RakutenOrderService::class, 'mapOrderModel');
$method->setAccessible(true);

$mapped = $method->invoke($service, $model, [
    'id' => 7,
    'platform' => 'r',
    'name' => 'Rakuten Test Store',
    'legacy_dpid' => 'testshop',
    'api_config' => '',
], 'api_tester');

assert_true(is_array($mapped), 'mapped order is array');
assert_same('r', $mapped['platform'] ?? null, 'platform');
assert_same('435069-20260518-0143428507', $mapped['platform_order_id'] ?? null, 'platform_order_id');
assert_same('2026-05-18 15:13:49', $mapped['order_date'] ?? null, 'order_date');
assert_same('Rakuten Test Store', $mapped['store'] ?? null, 'store name');
assert_same('api_tester', $mapped['platform_extra']['user_name'] ?? null, 'legacy user_name');
assert_same('未处理的订单', $mapped['platform_extra']['beizhu'] ?? null, 'legacy beizhu');
assert_same('2026-05-18 15:13:49', $mapped['platform_extra']['OrderTime'] ?? null, 'legacy OrderTime');
assert_same('L1=1', $mapped['platform_extra']['QuantityDetail'] ?? null, 'legacy QuantityDetail');
assert_true(str_starts_with((string) ($mapped['platform_extra']['EntryPoint'] ?? ''), 'https://item.rakuten.co.jp/testshop/'), 'legacy EntryPoint');

$item = $mapped['items'][0] ?? null;
assert_true(is_array($item), 'mapped item exists');
assert_same('1', $item['line_id'] ?? null, 'item line_id');
assert_same(1, $item['quantity'] ?? null, 'item quantity');
assert_same('api_tester', $item['platform_extra']['user_name'] ?? null, 'item legacy user_name');
assert_same('未处理的订单', $item['purchase_status'] ?? null, 'item purchase_status');
assert_true(str_starts_with((string) ($item['image'] ?? ''), 'https://image.rakuten.co.jp/testshop/cabinet/main/'), 'item main image');
assert_same($item['image'] ?? null, $item['platform_extra']['zhutu'] ?? null, 'legacy zhutu');
assert_same('', $item['platform_extra']['skuimg'] ?? null, 'legacy skuimg');
assert_same('', $item['main_image'] ?? null, 'main_image waits for local downloader');
assert_same($item['platform_extra']['SubCodeOption'] ?? null, $item['option'] ?? null, 'item option keeps SubCodeOption only');
assert_true(
    ($item['platform_extra']['selectedChoice'] ?? '') !== '' && ($item['platform_extra']['selectedChoice'] ?? '') !== ($item['option'] ?? ''),
    'selectedChoice remains separate from item option'
);

$legacyApiFields = [
    'OrderId',
    'myid',
    'LineId',
    'ItemId',
    'Quantity',
    'SubCodeOption',
    'selectedChoice',
    'delvdateInfo',
    'OrderTime',
    'OrderStatus',
    'ShipName',
    'senderKana',
    'ShipAddress1',
    'ShipAddress2',
    'ShipCity',
    'ShipPrefecture',
    'ShipZipCode',
    'ShipPhoneNumber',
    'ShipRequestDate',
    'ShipRequestTime',
    'ShipNotes',
    'BillMailAddress',
    'PayMethodName',
    'PayStatus',
    'PayDate',
    'QuantityDetail',
    'ShipCharge',
    'PayCharge',
    'UnitPrice',
    'TotalPrice',
    'requestPrice',
    'cdate',
    'user_name',
    'beizhu',
    'ItemManagerId',
    'basketId',
];

foreach ($legacyApiFields as $field) {
    assert_true(
        array_key_exists($field, $mapped['platform_extra']) || array_key_exists($field, $item['platform_extra']),
        "legacy API field {$field} is covered"
    );
}

echo "Rakuten order mapping test passed.\n";

function assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function assert_true(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "{$label}: assertion failed" . PHP_EOL);
        exit(1);
    }
}
