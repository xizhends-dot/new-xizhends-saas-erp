<?php

declare(strict_types=1);

namespace Xizhen\Services;

use RuntimeException;
use Xizhen\Core\StoreInterface;

final class OrderArchiveService
{
    public function __construct(
        private readonly StoreInterface $store,
        private readonly ?string $storageRoot = null
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return array{ok: bool, message: string, scanned: int, updated: int, skipped: int, failed: int}
     */
    public function run(string $tenantKey, array $options = []): array
    {
        $years = $this->yearsToArchive($tenantKey, $options);
        $dryRun = $this->boolOption($options, 'dry-run') || $this->boolOption($options, 'dry_run');
        $summary = ['ok' => true, 'message' => '', 'scanned' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($years as $year) {
            $orders = $this->store->ordersByYear($tenantKey, $year);
            if (!$orders) {
                $summary['skipped']++;
                continue;
            }

            $summary['scanned'] += count($orders);
            if ($dryRun) {
                $summary['skipped'] += count($orders);
                continue;
            }

            try {
                $this->writeArchive($tenantKey, $year, $orders);
                $orderIds = array_values(array_filter(array_map(
                    static fn (array $order): int => (int) ($order['id'] ?? 0),
                    $orders
                )));
                $this->store->deleteOrders($tenantKey, $orderIds);
                $summary['updated'] += count($orderIds);
            } catch (\Throwable) {
                $summary['failed']++;
            }
        }

        $summary['ok'] = $summary['failed'] === 0;
        $summary['message'] = sprintf(
            '扫描 %d 单，归档 %d 单，跳过 %d，失败 %d。',
            $summary['scanned'],
            $summary['updated'],
            $summary['skipped'],
            $summary['failed']
        );

        return $summary;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int, int>
     */
    private function yearsToArchive(string $tenantKey, array $options): array
    {
        $year = (int) ($options['year'] ?? 0);
        if ($year > 0) {
            return [$year];
        }

        $years = [];
        $currentYear = (int) date('Y');
        for ($candidate = 2010; $candidate < $currentYear; $candidate++) {
            if ($this->store->ordersByYear($tenantKey, $candidate) !== []) {
                $years[] = $candidate;
            }
        }

        return $years;
    }

    /** @param array<int, array<string, mixed>> $orders */
    private function writeArchive(string $tenantKey, int $year, array $orders): void
    {
        $directory = $this->tenantArchivesPath($tenantKey);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create archive directory: {$directory}");
        }

        $path = $directory . "/orders_{$year}.json";
        $json = json_encode($orders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($path, $json) === false) {
            throw new RuntimeException("Unable to write archive: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || count($decoded) !== count($orders)) {
            throw new RuntimeException("Archive verification failed: {$path}");
        }
    }

    private function tenantArchivesPath(string $tenantKey): string
    {
        return rtrim($this->baseStorageRoot(), '/\\') . DIRECTORY_SEPARATOR . $tenantKey . DIRECTORY_SEPARATOR . 'archives';
    }

    private function baseStorageRoot(): string
    {
        if ($this->storageRoot !== null && $this->storageRoot !== '') {
            return $this->storageRoot;
        }

        $basePath = defined('BASE_PATH') ? (string) constant('BASE_PATH') : dirname(__DIR__, 2);
        return $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tenants';
    }

    /** @param array<string, mixed> $options */
    private function boolOption(array $options, string $key): bool
    {
        $value = $options[$key] ?? false;
        if (is_string($value)) {
            return !in_array(strtolower($value), ['', '0', 'false', 'no', 'off'], true);
        }

        return (bool) $value;
    }
}
