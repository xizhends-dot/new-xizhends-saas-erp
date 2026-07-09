<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class ProductImageDownloadTask implements CronTaskInterface
{
    public function __construct(private readonly StoreInterface $store)
    {
    }

    public function key(): string
    {
        return 'product:image-download';
    }

    public function name(): string
    {
        return '商品主图下载';
    }

    public function description(): string
    {
        return '为最近订单中缺失主图的 Y/R/W/YP 商品自动抓取主图。';
    }

    public function schedule(): string
    {
        return '每 10 分钟运行一次。';
    }

    public function oldSource(): string
    {
        return 'cron/zhutu_downloader.php';
    }

    public function run(?string $tenantKey, array $options = []): array
    {
        $service = new ProductImageDownloadService($this->store);
        $tenants = $tenantKey !== null && $tenantKey !== ''
            ? [$tenantKey]
            : array_values(array_filter(array_map(static fn (array $tenant): string => (string) ($tenant['key'] ?? ''), $this->store->tenants())));
        $summary = ['ok' => true, 'message' => '', 'scanned' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'tenants' => [], 'logs' => []];
        $messages = [];
        $logger = is_callable($options['logger'] ?? null) ? $options['logger'] : null;

        foreach ($tenants as $key) {
            $this->appendLog($summary, "租户: {$key}", $logger);
            $result = $service->run($key, $options);
            foreach (['scanned', 'updated', 'skipped', 'failed'] as $field) {
                $summary[$field] += (int) ($result[$field] ?? 0);
            }
            $summary['tenants'][] = $key;
            $messages[] = "{$key}: " . (string) ($result['message'] ?? '');
            array_push($summary['logs'], ...(array) ($result['logs'] ?? []));
        }

        $summary['ok'] = $summary['failed'] === 0;
        $summary['message'] = implode('；', $messages);

        return $summary;
    }

    /** @param array<string, mixed> $summary */
    private function appendLog(array &$summary, string $line, ?callable $logger): void
    {
        $summary['logs'][] = $line;
        if ($logger !== null) {
            $logger($line);
        }
    }
}
