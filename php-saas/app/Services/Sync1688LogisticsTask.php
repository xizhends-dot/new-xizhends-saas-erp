<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class Sync1688LogisticsTask implements CronTaskInterface
{
    public function __construct(private readonly StoreInterface $store)
    {
    }

    public function key(): string
    {
        return 'logistics:1688';
    }

    public function name(): string
    {
        return '1688 物流更新';
    }

    public function description(): string
    {
        return '同步国内采购 1688 单号的物流公司、运单号、物流状态和轨迹。';
    }

    public function schedule(): string
    {
        return '每 2 小时，00:00-08:00 可由系统 cron 跳过';
    }

    public function oldSource(): string
    {
        return 'plugins/1688api/func.php + cron/update_1688_logistics.php';
    }

    public function run(?string $tenantKey, array $options = []): array
    {
        return $this->runForTenants($tenantKey, $options, new Alibaba1688LogisticsService($this->store));
    }

    /** @param array<string, mixed> $options */
    private function runForTenants(?string $tenantKey, array $options, Alibaba1688LogisticsService $service): array
    {
        $tenants = $tenantKey !== null && $tenantKey !== ''
            ? [$tenantKey]
            : array_values(array_filter(array_map(static fn (array $tenant): string => (string) ($tenant['key'] ?? ''), $this->store->tenants())));
        $summary = ['ok' => true, 'message' => '', 'scanned' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'tenants' => []];
        $messages = [];
        foreach ($tenants as $key) {
            $result = $service->syncItems($key, [], $options, 'cron:1688');
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
