<?php

declare(strict_types=1);

require __DIR__ . '/../app/Core/StoreInterface.php';
require __DIR__ . '/../app/Core/TenantFeature.php';
require __DIR__ . '/../app/Core/Permission.php';
require __DIR__ . '/../app/Core/JsonStore.php';
require __DIR__ . '/../app/Services/OrderArchiveService.php';

use Xizhen\Core\JsonStore;
use Xizhen\Services\OrderArchiveService;

$jsonPath = temp_json_path();
$storageRoot = temp_storage_root();
$store = new JsonStore($jsonPath);
$orders = $store->ordersByYear('erp', 2025);

assert_same([101, 103], array_map(static fn (array $order): int => (int) $order['id'], $orders), 'ordersByYear filters by imported_at year with order_date fallback');
assert_same(1001, (int) ($orders[0]['items'][0]['id'] ?? 0), 'ordersByYear keeps items');

$service = new OrderArchiveService($store, $storageRoot);
$dryRun = $service->run('erp', ['year' => 2025, 'dry-run' => true]);
assert_same(2, $dryRun['scanned'] ?? null, 'dry-run scanned order count');
assert_same(0, $dryRun['updated'] ?? null, 'dry-run does not update');
assert_same(2, $dryRun['skipped'] ?? null, 'dry-run skipped order count');
assert_same(false, is_file($storageRoot . '/erp/archives/orders_2025.json'), 'dry-run does not write archive');
assert_same(3, count((new JsonStore($jsonPath))->orders('erp')), 'dry-run does not delete orders');

$result = $service->run('erp', ['year' => 2025]);
assert_same(2, $result['scanned'] ?? null, 'archive scanned order count');
assert_same(2, $result['updated'] ?? null, 'archive deletes archived order count');
assert_same(0, $result['failed'] ?? null, 'archive failed count');
assert_same(true, is_file($storageRoot . '/erp/archives/orders_2025.json'), 'archive file is written');

$archived = json_decode((string) file_get_contents($storageRoot . '/erp/archives/orders_2025.json'), true);
assert_same([101, 103], array_map(static fn (array $order): int => (int) $order['id'], is_array($archived) ? $archived : []), 'archive file contains expected orders');
assert_same([102], array_map(static fn (array $order): int => (int) $order['id'], (new JsonStore($jsonPath))->orders('erp')), 'archived orders are deleted');

$autoJsonPath = temp_json_path();
$autoStorageRoot = temp_storage_root();
$autoResult = (new OrderArchiveService(new JsonStore($autoJsonPath), $autoStorageRoot))->run('erp');
assert_same(2, $autoResult['scanned'] ?? null, 'auto archive discovers historical years through ordersByYear');
assert_same(true, is_file($autoStorageRoot . '/erp/archives/orders_2025.json'), 'auto archive writes discovered historical year');

echo "Order archive test passed.\n";

function temp_json_path(): string
{
    $path = sys_get_temp_dir() . '/xizhen-order-archive-' . bin2hex(random_bytes(6)) . '.json';
    $data = [
        'platforms' => [],
        'tenants' => [
            ['key' => 'erp', 'name' => 'ERP'],
        ],
        'admins' => [],
        'orders' => [
            'erp' => [
                [
                    'id' => 101,
                    'order_date' => '2024-12-31 23:59:59',
                    'imported_at' => '2025-01-02 10:00:00',
                    'items' => [
                        ['id' => 1001],
                    ],
                ],
                [
                    'id' => 102,
                    'order_date' => '2026-01-01 00:00:00',
                    'imported_at' => '2026-01-01 00:00:00',
                    'items' => [
                        ['id' => 1002],
                    ],
                ],
                [
                    'id' => 103,
                    'order_date' => '2025-07-04 12:00:00',
                    'imported_at' => '',
                    'items' => [
                        ['id' => 1003],
                    ],
                ],
            ],
        ],
    ];
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return $path;
}

function temp_storage_root(): string
{
    $path = sys_get_temp_dir() . '/xizhen-order-archive-storage-' . bin2hex(random_bytes(6));
    mkdir($path, 0777, true);

    return $path;
}

function assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}
