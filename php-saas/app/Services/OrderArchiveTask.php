<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class OrderArchiveTask implements CronTaskInterface
{
    public function __construct(private readonly StoreInterface $store)
    {
    }

    public function key(): string
    {
        return 'order:archive';
    }

    public function name(): string
    {
        return '订单归档';
    }

    public function description(): string
    {
        return '将历史年份订单写入租户归档 JSON，校验后从活动订单删除。';
    }

    public function schedule(): string
    {
        return '每年年初或人工指定年份运行。';
    }

    public function oldSource(): string
    {
        return 'cron/order_archive.php';
    }

    public function run(?string $tenantKey, array $options = []): array
    {
        $service = new OrderArchiveService($this->store);
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

