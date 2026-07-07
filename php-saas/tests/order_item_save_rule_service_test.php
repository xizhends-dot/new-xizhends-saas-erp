<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);

require $basePath . '/vendor/autoload.php';

use Xizhen\Services\OrderItemSaveRuleService;

$service = new OrderItemSaveRuleService();

assert_same(
    'buyer-a',
    $service->autoBuyer(
        ['tabaono' => '168812345678901234'],
        ['buyer' => '', 'tabaono' => ''],
        ['role' => '采购', 'username' => 'buyer-a']
    ),
    '采购角色首次写入1688单号时自动记录采购人'
);

assert_same(
    null,
    $service->autoBuyer(
        ['tabaono' => '168812345678901234'],
        ['buyer' => 'buyer-old', 'tabaono' => ''],
        ['role' => '采购', 'username' => 'buyer-a']
    ),
    '已有采购人时不覆盖'
);

assert_same(
    null,
    $service->autoBuyer(
        ['tabaono' => '168812345678901234'],
        ['buyer' => '', 'tabaono' => ''],
        ['role' => '客服', 'username' => 'support-a']
    ),
    '非采购角色不自动记录采购人'
);

assert_same(
    null,
    $service->autoBuyer(
        ['tabaono' => ''],
        ['buyer' => '', 'tabaono' => ''],
        ['role' => '采购', 'username' => 'buyer-a']
    ),
    '1688单号为空时不自动记录采购人'
);

$changes = $service->withAutoBuyer(
    ['ship_company' => '中通', 'tabaono' => '168812345678901234'],
    ['buyer' => '', 'tabaono' => ''],
    ['role' => '采购', 'username' => 'buyer-a']
);
assert_same('buyer-a', $changes['buyer'] ?? null, 'withAutoBuyer 会回填 buyer');

echo "Order item save rule service test OK\n";

function assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}
