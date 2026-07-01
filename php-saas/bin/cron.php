<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Core/helpers.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'Xizhen\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Xizhen\Core\Config;
use Xizhen\Core\StoreFactory;
use Xizhen\Services\CronTaskRegistry;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only run from CLI." . PHP_EOL);
    exit(1);
}

try {
    exit(main($argv));
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL);
    if (hasDebugFlag($argv)) {
        fwrite(STDERR, $exception->getTraceAsString() . PHP_EOL);
    }
    exit(1);
}

/** @param array<int, string> $argv */
function main(array $argv): int
{
    $parsed = parseArguments($argv);
    $command = $parsed['command'];
    $options = $parsed['options'];

    $config = Config::load(BASE_PATH);
    $store = StoreFactory::make($config);
    $registry = new CronTaskRegistry($store);

    if ($command === 'list') {
        writeJson([
            'ok' => true,
            'tasks' => $registry->definitions(),
        ]);
        return 0;
    }

    if ($command === 'run') {
        $key = $parsed['key'];
        if ($key === null || $key === '') {
            writeUsage(STDERR);
            return 1;
        }

        $tenantKey = tenantKeyFromOptions($options);
        $result = $registry->run($key, $tenantKey, $options);
        writeJson([
            'ok' => $result['ok'],
            'task' => $key,
            'tenant' => $tenantKey,
            'result' => $result,
        ]);

        return $result['ok'] ? 0 : 1;
    }

    writeUsage(STDERR);
    return $command === 'help' ? 0 : 1;
}

/**
 * @param array<int, string> $argv
 * @return array{command: string, key: ?string, options: array<string, mixed>}
 */
function parseArguments(array $argv): array
{
    $tokens = array_slice($argv, 1);
    $command = (string) array_shift($tokens);
    if ($command === '') {
        $command = 'help';
    }

    $key = null;
    $options = [];

    foreach ($tokens as $token) {
        if (str_starts_with($token, '--')) {
            [$name, $value] = parseOption($token);
            $options[$name] = $value;
            $normalized = str_replace('-', '_', $name);
            if ($normalized !== $name) {
                $options[$normalized] = $value;
            }
            continue;
        }

        if ($command === 'run' && $key === null) {
            $key = $token;
            continue;
        }

        $options[] = $token;
    }

    return [
        'command' => $command,
        'key' => $key,
        'options' => $options,
    ];
}

/**
 * @return array{0: string, 1: mixed}
 */
function parseOption(string $token): array
{
    $option = substr($token, 2);
    if ($option === '') {
        return ['', true];
    }

    if (str_contains($option, '=')) {
        [$name, $value] = explode('=', $option, 2);
        return [$name, $value];
    }

    return [$option, true];
}

/** @param array<string, mixed> $options */
function tenantKeyFromOptions(array $options): ?string
{
    if (!empty($options['all-tenants']) || !empty($options['all_tenants'])) {
        return null;
    }

    $tenant = trim((string) ($options['tenant'] ?? ''));
    return $tenant !== '' ? $tenant : null;
}

/** @param array<string, mixed> $payload */
function writeJson(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
}

/** @param resource $stream */
function writeUsage($stream): void
{
    fwrite($stream, "Usage:" . PHP_EOL);
    fwrite($stream, "  php bin/cron.php list" . PHP_EOL);
    fwrite($stream, "  php bin/cron.php run logistics:1688 --tenant=erp --limit=100" . PHP_EOL);
    fwrite($stream, "  php bin/cron.php run logistics:jp --tenant=erp --limit=100 --days=30" . PHP_EOL);
    fwrite($stream, "  php bin/cron.php run logistics:jp --all-tenants --limit=100 --days=30" . PHP_EOL);
}

/** @param array<int, string> $argv */
function hasDebugFlag(array $argv): bool
{
    foreach ($argv as $arg) {
        if ($arg === '--debug' || str_starts_with($arg, '--debug=')) {
            return true;
        }
    }

    return false;
}
