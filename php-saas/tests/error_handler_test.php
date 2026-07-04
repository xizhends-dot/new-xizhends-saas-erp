<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/Core/helpers.php';

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

use Xizhen\Core\ErrorHandler;

$logDir = sys_get_temp_dir() . '/xizhen_error_handler_test_' . bin2hex(random_bytes(4));
mkdir($logDir, 0777, true);
$_SERVER['REQUEST_URI'] = '/test-error';
$_SERVER['HTTP_ACCEPT'] = 'application/json';

ob_start();
ErrorHandler::register($logDir);
ErrorHandler::handleException(new RuntimeException('boom'));
$body = ob_get_clean();

$logFile = $logDir . '/app-' . date('Y-m-d') . '.log';
assert_same(true, is_file($logFile), 'exception writes log file');
$log = file_get_contents($logFile);
assert_same(true, str_contains((string) $log, '/test-error'), 'log contains URI');
assert_same(true, str_contains((string) $log, RuntimeException::class), 'log contains exception class');
assert_same(true, str_contains($body, '"ok":false'), 'ajax exception returns json body');

unlink($logFile);
rmdir($logDir);

echo "Error handler test passed.\n";

function assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}
