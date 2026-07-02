<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class YahooShopOAuthService
{
    private const AUTH_URL = 'https://auth.login.yahoo.co.jp/yconnect/v2/authorization';
    private const TOKEN_URL = 'https://auth.login.yahoo.co.jp/yconnect/v2/token';

    public function __construct(private readonly StoreInterface $store)
    {
    }

    public function authorizationUrl(string $tenantKey, int $storeId, string $redirectUri): string
    {
        $store = $this->yahooStore($tenantKey, $storeId);
        $credentials = $this->credentials($store);
        $this->assertAuthorizationReady($credentials);

        $state = $this->createState($tenantKey, $storeId);

        return self::AUTH_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $credentials['client_id'],
            'redirect_uri' => $redirectUri,
            'scope' => 'openid',
            'nonce' => bin2hex(random_bytes(8)),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{ok: bool, message: string, tenant_key: string, store_id: int}
     */
    public function handleCallback(string $code, string $state, string $redirectUri): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new \RuntimeException('Yahoo OAuth 回调缺少授权码 code。');
        }

        $stateData = $this->decodeState($state);
        $tenantKey = (string) $stateData['tenant_key'];
        $storeId = (int) $stateData['store_id'];
        $store = $this->yahooStore($tenantKey, $storeId);
        $credentials = $this->credentials($store);
        $this->assertAuthorizationReady($credentials);

        $token = $this->requestToken($credentials, $code, $redirectUri);
        $now = time();
        $expiresIn = (int) ($token['expires_in'] ?? 0);
        $tokenPatch = [
            'access_token' => trim((string) ($token['access_token'] ?? '')),
            'refresh_token' => trim((string) ($token['refresh_token'] ?? '')),
            'token_type' => trim((string) ($token['token_type'] ?? 'Bearer')),
            'expires_in' => $expiresIn,
            'token_requested_at' => date('Y-m-d H:i:s', $now),
            'token_expires_at' => $expiresIn > 0 ? date('Y-m-d H:i:s', $now + $expiresIn) : '',
        ];
        if ($tokenPatch['access_token'] === '') {
            throw new \RuntimeException('Yahoo token endpoint 未返回 access_token。');
        }
        if ($tokenPatch['refresh_token'] === '') {
            unset($tokenPatch['refresh_token']);
        }

        $this->store->mergeStoreApiConfig($tenantKey, $storeId, $tokenPatch, '已配置');
        $this->deleteState($state);

        return [
            'ok' => true,
            'message' => 'Yahoo Shop 授权完成，access_token 已写回店铺 API 配置。',
            'tenant_key' => $tenantKey,
            'store_id' => $storeId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function yahooStore(string $tenantKey, int $storeId): array
    {
        $store = $this->store->store($tenantKey, $storeId);
        if (!$store || (string) ($store['platform'] ?? '') !== 'y') {
            throw new \RuntimeException('请选择 Yahoo Shop 店铺后再授权。');
        }

        return $store;
    }

    /**
     * @param array<string, mixed> $store
     * @return array{client_id: string, client_secret: string, seller_id: string}
     */
    private function credentials(array $store): array
    {
        $config = $this->apiConfig($store);

        return [
            'client_id' => $this->firstConfig($config, ['client_id', 'clientId', 'app_id', 'appId', 'AppID', 'appid', 'application_id'], ['YAHOO_SHOP_CLIENT_ID', 'YAHOO_SHOP_APP_ID', 'YAHOO_CLIENT_ID', 'YAHOO_APP_ID']),
            'client_secret' => $this->firstConfig($config, ['client_secret', 'clientSecret', 'app_secret', 'appSecret', 'Secret', 'secret'], ['YAHOO_SHOP_CLIENT_SECRET', 'YAHOO_SHOP_APP_SECRET', 'YAHOO_CLIENT_SECRET', 'YAHOO_APP_SECRET']),
            'seller_id' => $this->firstConfig($config, ['seller_id', 'sellerId', 'SellerId', 'store_account', 'storeAccount', 'store_id', 'shop_id', 'shopId', 'seller', 'dpid'], ['YAHOO_SHOP_SELLER_ID', 'YAHOO_SHOP_STORE_ACCOUNT', 'YAHOO_SELLER_ID']),
        ];
    }

    /** @param array<string, string> $credentials */
    private function assertAuthorizationReady(array $credentials): void
    {
        $missing = [];
        foreach (['client_id', 'client_secret', 'seller_id'] as $key) {
            if ($credentials[$key] === '') {
                $missing[] = $key;
            }
        }
        if ($missing) {
            throw new \RuntimeException('缺少 Yahoo Shop API 配置：' . implode(', ', $missing) . '。请先在店铺 API 配置中填写 AppID/Secret 和 seller_id。');
        }
    }

    /** @param array<string, string> $credentials @return array<string, mixed> */
    private function requestToken(array $credentials, string $code, string $redirectUri): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('当前 PHP 环境缺少 curl 扩展，无法交换 Yahoo OAuth token。');
        }

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode($credentials['client_id'] . ':' . $credentials['client_secret']),
            ],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'client_id' => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
            ]),
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            $detail = $error !== '' ? $error : trim(substr((string) $response, 0, 300));
            throw new \RuntimeException($detail !== '' ? "Yahoo OAuth token 交换失败：HTTP {$status}: {$detail}" : "Yahoo OAuth token 交换失败：HTTP {$status}");
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Yahoo OAuth token endpoint 返回不是有效 JSON。');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $store @return array<string, mixed> */
    private function apiConfig(array $store): array
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
            $key = trim($key);
            if ($key !== '') {
                $config[$key] = trim($value);
            }
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, string> $keys
     * @param array<int, string> $envNames
     */
    private function firstConfig(array $config, array $keys, array $envNames): string
    {
        $lower = [];
        foreach ($config as $key => $value) {
            $lower[strtolower((string) $key)] = $value;
        }

        foreach ($keys as $key) {
            $value = $config[$key] ?? $lower[strtolower($key)] ?? null;
            if (is_scalar($value)) {
                $value = trim((string) $value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        foreach ($envNames as $envName) {
            $value = trim((string) (getenv($envName) ?: ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function createState(string $tenantKey, int $storeId): string
    {
        $payload = json_encode([
            'tenant_key' => $tenantKey,
            'store_id' => $storeId,
            'issued_at' => time(),
            'nonce' => bin2hex(random_bytes(12)),
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            throw new \RuntimeException('生成 Yahoo OAuth state 失败。');
        }

        $state = bin2hex(random_bytes(24));
        $dir = $this->stateDir();
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('创建 Yahoo OAuth state 缓存目录失败。');
        }
        file_put_contents($this->statePath($state), $payload, LOCK_EX);

        return $state;
    }

    /** @return array{tenant_key: string, store_id: int, issued_at: int} */
    private function decodeState(string $state): array
    {
        $state = trim($state);
        if ($state === '' || !preg_match('/^[a-f0-9]{48}$/', $state)) {
            throw new \RuntimeException('Yahoo OAuth 回调缺少 state。');
        }

        $path = $this->statePath($state);
        if (!is_file($path)) {
            throw new \RuntimeException('Yahoo OAuth state 无效或已过期，请重新发起授权。');
        }

        $json = file_get_contents($path);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data)) {
            throw new \RuntimeException('Yahoo OAuth state 无效。');
        }

        $tenantKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($data['tenant_key'] ?? '')) ?: '';
        $storeId = (int) ($data['store_id'] ?? 0);
        $issuedAt = (int) ($data['issued_at'] ?? 0);
        if ($tenantKey === '' || $storeId <= 0 || $issuedAt <= 0) {
            throw new \RuntimeException('Yahoo OAuth state 缺少租户或店铺信息。');
        }
        if (time() - $issuedAt > 600) {
            throw new \RuntimeException('Yahoo OAuth state 已过期，请重新发起授权。');
        }

        return ['tenant_key' => $tenantKey, 'store_id' => $storeId, 'issued_at' => $issuedAt];
    }

    private function deleteState(string $state): void
    {
        $path = $this->statePath($state);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function statePath(string $state): string
    {
        return $this->stateDir() . DIRECTORY_SEPARATOR . 'state-' . hash('sha256', $state) . '.json';
    }

    private function stateDir(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'yahoo_oauth';
    }
}
