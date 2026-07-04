<?php

declare(strict_types=1);

require __DIR__ . '/../app/Core/StoreInterface.php';
require __DIR__ . '/../app/Core/TenantFeature.php';
require __DIR__ . '/../app/Core/Permission.php';
require __DIR__ . '/../app/Core/JsonStore.php';
require __DIR__ . '/../app/Services/OrderAutoCompleteService.php';

use Xizhen\Core\JsonStore;
use Xizhen\Services\OrderAutoCompleteService;

$path = temp_store_path();
$store = new JsonStore($path);
$service = new OrderAutoCompleteService($store);

$result = $service->run('erp');

assert_same(8, $result['scanned'] ?? null, 'scanned count');
assert_same(3, $result['updated'] ?? null, 'updated count');
assert_same(7, $result['skipped'] ?? null, 'skipped count');
assert_same(0, $result['failed'] ?? null, 'failed count');

$orders = (new JsonStore($path))->orders('erp');
$items = [];
foreach ($orders as $order) {
    foreach ($order['items'] as $item) {
        $items[(int) ($item['id'] ?? 0)] = $item;
    }
}

assert_same('https://example.com/newer', $items[10]['purchase_link'] ?? null, 'purchase_link uses newest valid historical URLs');
assert_same('silk', $items[10]['material'] ?? null, 'material uses newest non-empty historical value');
assert_same('new fragile', $items[10]['tranship_comment'] ?? null, 'tranship_comment uses newest non-empty historical value');
assert_same('keep me', $items[14]['purchase_link'] ?? null, 'existing purchase_link is not overwritten');

echo "Order auto complete test passed.\n";

function temp_store_path(): string
{
    $path = sys_get_temp_dir() . '/xizhen-order-auto-complete-' . bin2hex(random_bytes(6)) . '.json';
    $data = [
        'platforms' => [],
        'tenants' => [
            ['key' => 'erp', 'name' => 'ERP'],
        ],
        'admins' => [],
        'orders' => [
            'erp' => [
                [
                    'id' => 5,
                    'platform' => 'r',
                    'platform_order_id' => 'old-invalid',
                    'order_date' => '2025-01-01 10:00:00',
                    'imported_at' => '2025-01-01 10:00:00',
                    'items' => [
                        [
                            'id' => 15,
                            'item_code' => 'SKU-1',
                            'purchase_link' => '缺货 https://example.com/invalid',
                            'material' => 'wool',
                            'tranship_comment' => 'old fragile',
                        ],
                    ],
                ],
                [
                    'id' => 6,
                    'platform' => 'r',
                    'platform_order_id' => 'old-valid',
                    'order_date' => '2025-01-02 10:00:00',
                    'imported_at' => '2025-01-02 10:00:00',
                    'items' => [
                        [
                            'id' => 16,
                            'item_code' => 'SKU-1',
                            'purchase_link' => 'buy https://example.com/a text https://example.com/b',
                            'material' => 'cotton',
                            'tranship_comment' => 'fragile',
                        ],
                    ],
                ],
                [
                    'id' => 7,
                    'platform' => 'r',
                    'platform_order_id' => 'newer-valid',
                    'order_date' => '2025-01-03 10:00:00',
                    'imported_at' => '2025-01-03 10:00:00',
                    'items' => [
                        [
                            'id' => 8,
                            'item_code' => 'SKU-1',
                            'purchase_link' => 'https://example.com/newer',
                            'material' => 'silk',
                            'tranship_comment' => 'new fragile',
                        ],
                    ],
                ],
                [
                    'id' => 8,
                    'platform' => 'r',
                    'platform_order_id' => 'new',
                    'order_date' => '2025-01-04 10:00:00',
                    'imported_at' => '2025-01-04 10:00:00',
                    'items' => [
                        [
                            'id' => 10,
                            'item_code' => 'SKU-1',
                            'purchase_link' => '',
                            'material' => '',
                            'tranship_comment' => '',
                        ],
                        [
                            'id' => 11,
                            'item_code' => '',
                            'purchase_link' => '',
                            'material' => '',
                            'tranship_comment' => '',
                        ],
                        [
                            'id' => 12,
                            'item_code' => 'SKU-2',
                            'purchase_link' => '',
                            'material' => '',
                            'tranship_comment' => '',
                        ],
                        [
                            'id' => 13,
                            'item_code' => 'SKU-3',
                            'purchase_link' => '',
                            'material' => 'has material',
                            'tranship_comment' => 'has comment',
                        ],
                        [
                            'id' => 14,
                            'item_code' => 'SKU-9',
                            'purchase_link' => 'keep me',
                            'material' => 'keep material',
                            'tranship_comment' => 'keep comment',
                        ],
                    ],
                ],
            ],
        ],
    ];
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return $path;
}

function assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}
