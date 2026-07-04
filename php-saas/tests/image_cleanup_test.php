<?php

declare(strict_types=1);

require __DIR__ . '/../app/Services/ImageCleanupService.php';

use Xizhen\Services\ImageCleanupService;

$basePath = temp_base_path();
$ordersRoot = $basePath . '/storage/tenants/erp/images/orders';

$expiredByName = $ordersRoot . '/101/201/main-20240101010101-deadbeef-download.jpg';
$freshByName = $ordersRoot . '/102/202/main-' . date('YmdHis') . '-fresh001-download.jpg';
$expiredByMtime = $ordersRoot . '/103/203/manual-image.jpg';
write_file($expiredByName, 'old');
write_file($freshByName, 'fresh');
write_file($expiredByMtime, 'mtime-old');
touch($expiredByMtime, strtotime('-14 months'));

$service = new ImageCleanupService($basePath);
$dryRun = $service->run('erp', ['retention-months' => 12, 'dry-run' => true]);
assert_same(3, $dryRun['scanned'] ?? null, 'dry-run scanned count');
assert_same(2, $dryRun['updated'] ?? null, 'dry-run would delete expired count');
assert_same(1, $dryRun['skipped'] ?? null, 'dry-run skipped fresh count');
assert_same(true, is_file($expiredByName), 'dry-run keeps expired-by-name file');
assert_same(true, is_file($expiredByMtime), 'dry-run keeps expired-by-mtime file');

$result = $service->run('erp', ['retention-months' => 12]);
assert_same(3, $result['scanned'] ?? null, 'cleanup scanned count');
assert_same(2, $result['updated'] ?? null, 'cleanup deleted count');
assert_same(1, $result['skipped'] ?? null, 'cleanup skipped count');
assert_same(false, is_file($expiredByName), 'expired-by-name file deleted');
assert_same(false, is_dir(dirname($expiredByName)), 'empty item directory removed');
assert_same(false, is_file($expiredByMtime), 'expired-by-mtime file deleted');
assert_same(true, is_file($freshByName), 'fresh file remains');
assert_same(true, is_dir(dirname($freshByName)), 'fresh item directory remains');

echo "Image cleanup test passed.\n";

function temp_base_path(): string
{
    $path = sys_get_temp_dir() . '/xizhen-image-cleanup-' . bin2hex(random_bytes(6));
    mkdir($path, 0777, true);

    return str_replace('\\', '/', $path);
}

function write_file(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, $contents);
}

function assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

