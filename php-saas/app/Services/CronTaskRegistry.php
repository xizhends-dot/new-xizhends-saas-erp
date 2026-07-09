<?php

declare(strict_types=1);

namespace Xizhen\Services;

use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;
use Xizhen\Core\StoreInterface;

final class CronTaskRegistry
{
    /** @var array<string, CronTaskInterface> */
    private array $tasks = [];

    public function __construct(private readonly StoreInterface $store)
    {
        $this->registerIfExists(Sync1688LogisticsTask::class);
        $this->registerIfExists(SyncJapanLogisticsTask::class);
        $this->registerIfExists(OrderMonitorTask::class);
        $this->registerIfExists(OrderArchiveTask::class);
        $this->registerIfExists(ProductImageDownloadTask::class);
        $this->registerIfExists(ImageCleanupTask::class);
    }

    /** @return array<string, CronTaskInterface> */
    public function all(): array
    {
        return $this->tasks;
    }

    public function get(string $key): ?CronTaskInterface
    {
        return $this->tasks[$key] ?? null;
    }

    /** @return array<int, array<string, string>> */
    public function definitions(): array
    {
        $definitions = [];
        foreach ($this->tasks as $task) {
            $oldSource = $task->oldSource();
            $definitions[] = [
                'key' => $task->key(),
                'name' => $task->name(),
                'description' => $task->description(),
                'schedule' => $task->schedule(),
                'old_source' => $oldSource,
                'old' => $oldSource,
            ];
        }

        usort($definitions, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        return $definitions;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{
     *     ok: bool,
     *     message: string,
     *     scanned: int,
     *     updated: int,
     *     skipped: int,
     *     failed: int,
     *     tenants: array<int, string>,
     *     logs: array<int, string>
     * }
     */
    public function run(string $key, ?string $tenantKey, array $options = []): array
    {
        $task = $this->get($key);
        if ($task === null) {
            throw new InvalidArgumentException("Unknown cron task: {$key}");
        }

        return $this->normalizeResult($task->run($tenantKey, $options), $tenantKey);
    }

    /** @param class-string $class */
    private function registerIfExists(string $class): void
    {
        if (!class_exists($class)) {
            return;
        }

        $task = $this->makeTask($class);
        $key = trim($task->key());
        if ($key === '') {
            throw new RuntimeException("Cron task {$class} returned an empty key.");
        }

        $this->tasks[$key] = $task;
    }

    /** @param class-string $class */
    private function makeTask(string $class): CronTaskInterface
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        $task = $constructor !== null && $constructor->getNumberOfParameters() > 0
            ? $reflection->newInstance($this->store)
            : $reflection->newInstance();

        if (!$task instanceof CronTaskInterface) {
            throw new RuntimeException("Cron task {$class} must implement " . CronTaskInterface::class . '.');
        }

        return $task;
    }

    /**
     * @param array<string, mixed> $result
     * @return array{
     *     ok: bool,
     *     message: string,
     *     scanned: int,
     *     updated: int,
     *     skipped: int,
     *     failed: int,
     *     tenants: array<int, string>,
     *     logs: array<int, string>
     * }
     */
    private function normalizeResult(array $result, ?string $tenantKey): array
    {
        $failed = $this->intValue($result['failed'] ?? 0);
        $tenants = $this->normalizeTenants($result['tenants'] ?? null);
        if ($tenants === [] && $tenantKey !== null && $tenantKey !== '') {
            $tenants = [$tenantKey];
        }

        return [
            'ok' => array_key_exists('ok', $result) ? (bool) $result['ok'] : $failed === 0,
            'message' => (string) ($result['message'] ?? ''),
            'scanned' => $this->intValue($result['scanned'] ?? 0),
            'updated' => $this->intValue($result['updated'] ?? 0),
            'skipped' => $this->intValue($result['skipped'] ?? 0),
            'failed' => $failed,
            'tenants' => $tenants,
            'logs' => array_values(array_map('strval', is_array($result['logs'] ?? null) ? $result['logs'] : [])),
        ];
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /** @return array<int, string> */
    private function normalizeTenants(mixed $value): array
    {
        if (is_string($value)) {
            return $value !== '' ? [$value] : [];
        }

        if (!is_array($value)) {
            return [];
        }

        $tenants = [];
        foreach ($value as $tenant) {
            $tenant = trim((string) $tenant);
            if ($tenant !== '') {
                $tenants[] = $tenant;
            }
        }

        return array_values(array_unique($tenants));
    }
}
