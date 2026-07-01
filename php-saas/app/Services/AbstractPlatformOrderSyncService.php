<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

abstract class AbstractPlatformOrderSyncService implements PlatformOrderSyncInterface
{
    public function __construct(protected readonly StoreInterface $store)
    {
    }

    /**
     * @param array<string, mixed> $summary
     * @return array{ok: bool, message: string, searched: int, inserted: int, updated: int, skipped: int, items_inserted: int, items_updated: int}
     */
    protected function result(bool $ok, string $message, array $summary = [], int $searched = 0): array
    {
        return [
            'ok' => $ok,
            'message' => $message,
            'searched' => $searched,
            'inserted' => (int) ($summary['inserted'] ?? 0),
            'updated' => (int) ($summary['updated'] ?? 0),
            'skipped' => (int) ($summary['skipped'] ?? 0),
            'items_inserted' => (int) ($summary['items_inserted'] ?? 0),
            'items_updated' => (int) ($summary['items_updated'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    protected function apiConfig(array $store): array
    {
        $raw = trim((string) ($store['api_config'] ?? ''));
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $config = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $config[trim($key)] = trim($value);
        }

        return $config;
    }

    /** @param array<string, mixed> $config */
    protected function configValue(array $config, array|string $keys, ?string $envName = null): string
    {
        foreach ((array) $keys as $key) {
            $value = trim((string) ($config[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        if ($envName !== null) {
            return trim((string) (getenv($envName) ?: ''));
        }

        return '';
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function requestJson(string $url, array $options = []): array
    {
        $response = $this->requestText($url, $options);
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('平台 API 返回不是有效 JSON。');
        }

        return $data;
    }

    /** @param array<string, mixed> $options */
    protected function requestText(string $url, array $options = []): string
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('当前 PHP 环境缺少 curl 扩展。');
        }

        $method = strtoupper((string) ($options['method'] ?? 'GET'));
        $query = is_array($options['query'] ?? null) ? $options['query'] : [];
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $headers = array_values(array_filter(array_map('strval', is_array($options['headers'] ?? null) ? $options['headers'] : [])));
        $body = $options['body'] ?? null;
        if (is_array($body)) {
            $body = http_build_query($body);
        } elseif ($body !== null) {
            $body = (string) $body;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => (int) ($options['timeout'] ?? 30),
            CURLOPT_CONNECTTIMEOUT => (int) ($options['connect_timeout'] ?? 15),
        ]);
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        if ($body !== null && $body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            $detail = $error !== '' ? $error : trim(substr((string) $response, 0, 200));
            throw new \RuntimeException($detail !== '' ? "HTTP {$status}: {$detail}" : "HTTP {$status}");
        }

        return (string) $response;
    }

    /**
     * @return array{ok: bool, message: string, searched: int, inserted: int, updated: int, skipped: int, items_inserted: int, items_updated: int}
     */
    protected function markFailure(string $tenantKey, int $storeId, string $message): array
    {
        $this->store->markStoreSync($tenantKey, $storeId, '同步异常', $message);
        return $this->result(false, $message);
    }
}
