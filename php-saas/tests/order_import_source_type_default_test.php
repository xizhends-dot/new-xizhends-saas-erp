<?php

declare(strict_types=1);

require __DIR__ . '/../app/Core/Db.php';
require __DIR__ . '/../app/Core/Permission.php';
require __DIR__ . '/../app/Repositories/BaseRepository.php';
require __DIR__ . '/../app/Repositories/OrderMutationRepository.php';
require __DIR__ . '/../app/Repositories/StoreRepository.php';
require __DIR__ . '/../app/Repositories/OrderImportRepository.php';

use Xizhen\Repositories\OrderImportRepository;

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$repository = (new ReflectionClass(OrderImportRepository::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(OrderImportRepository::class, 'normalizePlatformItemPayload');
$method->setAccessible(true);

$payload = $method->invoke($repository, 123, [
    'line_id' => '1',
    'quantity' => 2,
    'unit_price' => 100,
    'image' => 'https://image.rakuten.co.jp/shop/cabinet/main/item.jpg',
], 0);

restore_error_handler();

assert_same('pending', $payload['source_type'] ?? null, 'missing source_type defaults to pending');
assert_same(200.0, $payload['line_total'] ?? null, 'line total fallback');
assert_same('', $payload['main_image'] ?? null, 'remote image is not stored as local main_image');
assert_same('https://image.rakuten.co.jp/shop/cabinet/main/item.jpg', $payload['platform_extra']['zhutu'] ?? null, 'remote image is kept as legacy zhutu');

echo "Order import source type default test passed.\n";

function assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}
