<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class SyncJapanLogisticsTask implements CronTaskInterface
{
    public function __construct(private readonly StoreInterface $store)
    {
    }

    public function key(): string
    {
        return 'logistics:jp';
    }

    public function name(): string
    {
        return '日本物流更新';
    }

    public function description(): string
    {
        return '查询佐川、日本邮政、雅玛多并回写国际物流状态。';
    }

    public function schedule(): string
    {
        return '每天 09:00 / 15:00 / 21:00';
    }

    public function oldSource(): string
    {
        return 'plugins/jpshipinfo/ + sagawa-shipinfo/ + cron/update_jpship_logistics.php';
    }

    public function run(?string $tenantKey, array $options = []): array
    {
        $service = new JapanLogisticsService($this->store);
        $tenants = $tenantKey !== null && $tenantKey !== ''
            ? [$tenantKey]
            : array_values(array_filter(array_map(static fn (array $tenant): string => (string) ($tenant['key'] ?? ''), $this->store->tenants())));
        $summary = ['ok' => true, 'message' => '', 'scanned' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'tenants' => []];
        $messages = [];
        foreach ($tenants as $key) {
            $result = $service->syncItems($key, [], $options, 'cron:jp');
            $summary['ok'] = $summary['ok'] && (bool) $result['ok'];
            foreach (['scanned', 'updated', 'skipped', 'failed'] as $field) {
                $summary[$field] += (int) $result[$field];
            }
            $summary['tenants'][] = $key;
            $messages[] = "{$key}: " . (string) $result['message'];
        }
        $summary['message'] = implode('；', $messages);

        return $summary;
    }
}
