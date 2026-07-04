<?php

declare(strict_types=1);

namespace Xizhen\Services;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ImageCleanupService
{
    public function __construct(private readonly ?string $basePath = null)
    {
    }

    /**
     * @param array<string, mixed> $options
     * @return array{ok: bool, message: string, scanned: int, updated: int, skipped: int, failed: int}
     */
    public function run(string $tenantKey, array $options = []): array
    {
        $retentionMonths = max(1, (int) ($options['retention_months'] ?? $options['retention-months'] ?? 12));
        $dryRun = $this->boolOption($options, 'dry-run') || $this->boolOption($options, 'dry_run');
        $cutoff = strtotime("-{$retentionMonths} months");
        $root = $this->ordersImageRoot($tenantKey);
        $summary = ['ok' => true, 'message' => '', 'scanned' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        if (!is_dir($root) || $cutoff === false) {
            $summary['message'] = '图片目录不存在或保留期无效。';
            return $summary;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $summary['scanned']++;
            $path = $file->getPathname();
            if ($this->fileTimestamp($path) >= $cutoff) {
                $summary['skipped']++;
                continue;
            }

            if ($dryRun) {
                $summary['updated']++;
                continue;
            }

            if (@unlink($path)) {
                $summary['updated']++;
                $this->removeEmptyParents(dirname($path), $root);
            } else {
                $summary['failed']++;
            }
        }

        $summary['ok'] = $summary['failed'] === 0;
        $summary['message'] = sprintf(
            '扫描 %d 个文件，删除 %d 个，保留 %d 个，失败 %d 个。',
            $summary['scanned'],
            $summary['updated'],
            $summary['skipped'],
            $summary['failed']
        );

        return $summary;
    }

    private function fileTimestamp(string $path): int
    {
        $name = basename($path);
        if (preg_match('/^[^-]+-(\d{14})-/', $name, $matches) === 1) {
            $timestamp = strtotime((string) $matches[1]);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        $mtime = @filemtime($path);
        return $mtime !== false ? $mtime : time();
    }

    private function removeEmptyParents(string $directory, string $root): void
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $current = rtrim(str_replace('\\', '/', $directory), '/');

        while ($current !== '' && str_starts_with($current, $root) && $current !== $root) {
            $files = @scandir($current);
            if ($files === false || count(array_diff($files, ['.', '..'])) > 0) {
                return;
            }

            @rmdir($current);
            $current = str_replace('\\', '/', dirname($current));
        }
    }

    private function ordersImageRoot(string $tenantKey): string
    {
        return $this->basePath() . "/storage/tenants/{$tenantKey}/images/orders";
    }

    private function basePath(): string
    {
        if ($this->basePath !== null && $this->basePath !== '') {
            return rtrim($this->basePath, '/\\');
        }

        return defined('BASE_PATH') ? (string) constant('BASE_PATH') : dirname(__DIR__, 2);
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

