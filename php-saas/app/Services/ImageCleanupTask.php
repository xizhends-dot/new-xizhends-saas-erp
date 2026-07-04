<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class ImageCleanupTask implements CronTaskInterface
{
    public function __construct(private readonly StoreInterface $store)
    {
    }

    public function key(): string
    {
        return 'storage:image-cleanup';
    }

    public function name(): string
    {
        return '订单图片清理';
    }

    public function description(): string
    {
        return '按文件时间清理租户订单图片，可通过 dry-run 预览。';
    }

    public function schedule(): string
    {
        return '每月运行一次。';
    }

    public function oldSource(): string
    {
        return 'cron/cleanup_old_images.php + cron/cleanup_old_images_preview.php';
    }

    public function run(?string $tenantKey, array $options = []): array
    {
        $service = new ImageCleanupService();
        $tenants = $tenantKey !== null && $tenantKey !== ''
            ? [$tenantKey]
            : array_values(array_filter(array_map(static fn (array $tenant): string => (string) ($tenant['key'] ?? ''), $this->store->tenants())));
        $summary = ['ok' => true, 'message' => '', 'scanned' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'tenants' => []];
        $messages = [];

        foreach ($tenants as $key) {
            $result = $service->run($key, $options);
            foreach (['scanned', 'updated', 'skipped', 'failed'] as $field) {
                $summary[$field] += (int) ($result[$field] ?? 0);
            }
            $summary['tenants'][] = $key;
            $messages[] = "{$key}: " . (string) ($result['message'] ?? '');
        }

        $summary['ok'] = $summary['failed'] === 0;
        $summary['message'] = implode('；', $messages);

        return $summary;
    }
}

