<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class OrderMonitorTask implements CronTaskInterface
{
    public function __construct(private readonly StoreInterface $store)
    {
    }

    public function key(): string
    {
        return 'order:auto-complete';
    }

    public function name(): string
    {
        return '订单自动补全';
    }

    public function description(): string
    {
        return '按历史同商品订单自动回填采购链接、备注和转运备注。';
    }

    public function schedule(): string
    {
        return '每 10 分钟运行一次。';
    }

    public function oldSource(): string
    {
        return 'cron/order_monitor.php';
    }

    public function run(?string $tenantKey, array $options = []): array
    {
        $service = new OrderAutoCompleteService($this->store);
        $tenants = $tenantKey !== null && $tenantKey !== ''
            ? [$tenantKey]
            : array_values(array_filter(array_map(static fn (array $tenant): string => (string) ($tenant['key'] ?? ''), $this->store->tenants())));
        $summary = ['ok' => true, 'message' => '', 'scanned' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'tenants' => []];
        $messages = [];

        foreach ($tenants as $key) {
            $result = $service->run($key);
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

