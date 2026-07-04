<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class CustomerServiceDeductionService
{
    public function __construct(private readonly StoreInterface $store)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rows(string $tenantKey): array
    {
        $map = $this->settingsMap($tenantKey);
        $rows = [];
        foreach ($this->store->users($tenantKey) as $user) {
            if (($user['role'] ?? '') !== '客服') {
                continue;
            }

            $userId = (int) ($user['id'] ?? 0);
            $username = (string) ($user['username'] ?? '');
            $hasUserField = array_key_exists('profit_deduction', $user);
            $settingsKey = (string) $userId;
            $settingsValue = $map[$settingsKey] ?? ($username !== '' ? ($map[$username] ?? null) : null);
            $raw = $hasUserField ? $user['profit_deduction'] : ($settingsValue ?? 70);

            $rows[] = [
                'id' => $userId,
                'name' => (string) (($user['name'] ?? '') ?: $username),
                'username' => $username,
                'status' => (string) ($user['status'] ?? 'active'),
                'deduction' => $this->normalizePercent($raw),
                'deduction_display' => $this->formatPercent($this->normalizePercent($raw)),
                'source' => $hasUserField ? 'user_field' : ($settingsValue === null ? 'default' : 'tenant_settings'),
                'source_label' => $hasUserField ? '用户字段' : ($settingsValue === null ? '默认值' : '租户设置'),
            ];
        }

        usort($rows, static fn (array $left, array $right): int => strcmp((string) $left['username'], (string) $right['username']));

        return $rows;
    }

    /** @return array{total: int, configured: int, defaulted: int, average: float} */
    public function summary(string $tenantKey): array
    {
        $rows = $this->rows($tenantKey);
        $total = count($rows);
        $configured = count(array_filter($rows, static fn (array $row): bool => ($row['source'] ?? '') !== 'default'));
        $sum = array_sum(array_map(static fn (array $row): float => (float) ($row['deduction'] ?? 0), $rows));

        return [
            'total' => $total,
            'configured' => $configured,
            'defaulted' => $total - $configured,
            'average' => $total > 0 ? round($sum / $total, 2) : 0.0,
        ];
    }

    /**
     * @param array<int|string, mixed> $deductions
     * @return array{ok: bool, message: string, errors: array<string, string>, payload?: array<string, mixed>}
     */
    public function previewUpdate(string $tenantKey, array $deductions, array $operator = []): array
    {
        $supportUsers = [];
        foreach ($this->rows($tenantKey) as $row) {
            $supportUsers[(int) $row['id']] = $row;
        }

        $map = $this->settingsMap($tenantKey);
        $errors = [];
        foreach ($deductions as $userId => $value) {
            $userId = (int) $userId;
            if (!isset($supportUsers[$userId])) {
                $errors[(string) $userId] = '只能维护客服账号扣点。';
                continue;
            }
            $map[(string) $userId] = $this->normalizePercent($value);
        }

        if ($errors !== []) {
            return [
                'ok' => false,
                'message' => '请修正客服扣点后再提交。',
                'errors' => $errors,
            ];
        }

        return [
            'ok' => true,
            'message' => '客服扣点数据已校验。',
            'errors' => [],
            'payload' => [
                'tenant_key' => $tenantKey,
                'customer_service_deductions' => $map,
                'operator_id' => (int) ($operator['id'] ?? 0),
                'operator_name' => (string) (($operator['name'] ?? '') ?: ($operator['username'] ?? '')),
            ],
        ];
    }

    /**
     * @param array<int|string, mixed> $deductions
     * @return array{ok: bool, message: string, errors: array<string, string>, saved: int}
     */
    public function saveToTenantSettings(string $tenantKey, array $deductions, array $operator = []): array
    {
        $preview = $this->previewUpdate($tenantKey, $deductions, $operator);
        if (!$preview['ok']) {
            return [
                'ok' => false,
                'message' => $preview['message'],
                'errors' => $preview['errors'],
                'saved' => 0,
            ];
        }

        $settings = $this->store->tenantSettings($tenantKey);
        $profit = is_array($settings['profit'] ?? null) ? $settings['profit'] : [];
        $payload = $preview['payload'] ?? [];
        $profit['customer_service_deductions'] = $payload['customer_service_deductions'] ?? [];
        $this->store->saveTenantSettings($tenantKey, ['profit' => $profit]);

        return [
            'ok' => true,
            'message' => '客服扣点已保存。',
            'errors' => [],
            'saved' => count((array) ($payload['customer_service_deductions'] ?? [])),
        ];
    }

    public function normalizePercent(mixed $value): float
    {
        $number = is_numeric($value) ? (float) $value : 70.0;
        if ($number < 0) {
            $number = 0.0;
        }
        if ($number > 100) {
            $number = 100.0;
        }

        return round($number, 2);
    }

    /** @return array<string, float> */
    private function settingsMap(string $tenantKey): array
    {
        $settings = $this->store->tenantSettings($tenantKey);
        $profit = is_array($settings['profit'] ?? null) ? $settings['profit'] : [];
        $raw = is_array($profit['customer_service_deductions'] ?? null) ? $profit['customer_service_deductions'] : [];
        $map = [];
        foreach ($raw as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            $map[$key] = $this->normalizePercent($value);
        }

        return $map;
    }

    private function formatPercent(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
