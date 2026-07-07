<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/Core/helpers.php';

use Xizhen\Core\JsonStore;
use Xizhen\Services\PlatformOrderSyncRegistry;

$jsonPath = sys_get_temp_dir() . '/xizhen-platform-sync-registry-' . bin2hex(random_bytes(6)) . '.json';
@unlink($jsonPath);

$registry = new PlatformOrderSyncRegistry(new JsonStore($jsonPath));
$names = $registry->names();
@unlink($jsonPath);

$failures = [];
$assert = static function (string $label, bool $ok) use (&$failures): void {
    if (!$ok) {
        $failures[] = $label;
    }
};

foreach (['r', 'w', 'y'] as $code) {
    $assert("{$code} 平台允许同步", isset($names[$code]));
}
foreach (['m', 'q', 'yp'] as $code) {
    $assert("{$code} 平台不允许同步", !isset($names[$code]));
}

if ($failures !== []) {
    fwrite(STDERR, "Platform sync registry test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Platform sync registry test OK\n";
