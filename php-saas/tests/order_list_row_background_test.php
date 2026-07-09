<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
$css = file_get_contents($basePath . '/public/assets/app.css') ?: '';

$failures = [];
$assert = static function (string $label, bool $ok) use (&$failures): void {
    if (!$ok) {
        $failures[] = $label;
    }
};

$assert('订单普通内容单元格为白底', preg_match('/--old-list-order-row:\s*#FFFFFF\s*;/', $css) === 1);
$assert('订单商品明细内容单元格为白底', preg_match('/--old-list-item-row:\s*#FFFFFF\s*;/', $css) === 1);

if ($failures !== []) {
    fwrite(STDERR, "Order list row background test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Order list row background test OK\n";
