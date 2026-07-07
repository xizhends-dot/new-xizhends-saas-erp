<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/Core/helpers.php';

use Xizhen\Core\JsonStore;
use Xizhen\Services\AppService;

$jsonPath = sys_get_temp_dir() . '/xizhen-platform-catalog-' . bin2hex(random_bytes(6)) . '.json';
@unlink($jsonPath);

$store = new JsonStore($jsonPath);
$platforms = $store->platforms();
$service = new AppService($store);
$menu = $service->platformMenu('erp');
@unlink($jsonPath);

$failures = [];
$assertSame = static function (string $label, mixed $expected, mixed $actual) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
};

$assertSame('平台目录顺序', ['r', 'y', 'w', 'm', 'q', 'yp'], array_column($platforms, 'code'));
$assertSame('平台目录名称', ['Rakuten', 'Yahoo', 'Wowma', 'Mercari', 'Qoo10', '雅虎拍卖'], array_column($platforms, 'name'));
$assertSame('ERP 左侧平台菜单顺序', ['r', 'y', 'w', 'm', 'q'], array_column($menu, 'code'));

if ($failures !== []) {
    fwrite(STDERR, "Platform catalog test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Platform catalog test OK\n";
