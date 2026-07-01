<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class ExpressLogisticsService
{
    private const API_URL = 'https://route.showapi.com/2650-3';
    private const DEFAULT_COMPANY = 'auto';
    private const DEFAULT_PHONE = '0000';
    private const DEFAULT_DAYS = 62;
    private const SIGNED_KEYWORDS = [
        '签收',
        '代收',
        '兔喜快递超市',
        '菜鸟驿站',
        '已投递',
        '正在为您派件',
        '正在派件',
        '已到达 义乌陆港',
        '派送成功',
        '自提点',
        '妈妈',
    ];
    private const SHOWAPI_ERRORS = [
        '-1' => '系统调用错误',
        '-2' => '可调用次数或金额为0',
        '-3' => '读取超时',
        '-4' => '服务端返回数据解析错误',
        '-5' => '后端服务器DNS解析错误',
        '-6' => '服务不存在或未上线',
        '-7' => 'API创建者的网关资源不足',
        '-1000' => '系统维护',
        '-1002' => 'showapi_appid字段必传',
        '-1003' => 'showapi_sign字段必传',
        '-1004' => '签名sign验证有误',
        '-1005' => 'showapi_timestamp无效',
        '-1006' => 'app无权限调用接口',
        '-1007' => '没有订购套餐',
        '-1008' => '服务商关闭对您的调用权限',
        '-1009' => '调用频率受限',
        '-1010' => '找不到您的应用',
        '-1011' => '子授权app_child_id无效',
        '-1012' => '子授权已过期或失效',
        '-1013' => '子授权ip受限',
        '-1014' => 'token权限无效',
    ];

    private readonly AppService $appService;

    public function __construct(private readonly StoreInterface $store)
    {
        $this->appService = new AppService($store);
    }

    /**
     * @param array<string, mixed> $options Supported keys: company, phone, timeout, connect_timeout.
     * @return array{
     *     ok: bool,
     *     code: string,
     *     message: string,
     *     tracking_no: string,
     *     carrier_code: string,
     *     carrier_name: string,
     *     status: string,
     *     logistics_status: string,
     *     trace: string,
     *     steps: array<int, array{time: string, context: string}>
     * }
     */
    public function query(string $tenantKey, string $trackingNo, string $company = self::DEFAULT_COMPANY, array $options = []): array
    {
        $trackingNo = $this->firstTrackingNumber($trackingNo);
        if ($trackingNo === '') {
            return $this->emptyQuery(false, 'empty_tracking_no', '快递单号为空。', '', $company);
        }

        $credentials = $this->credentials($tenantKey);
        if ($credentials === null) {
            return $this->emptyQuery(false, 'missing_config', '缺少 ShowAPI 配置，请通过租户配置或环境变量提供 app_id/sign。', $trackingNo, $company);
        }

        return $this->queryWithCredentials($credentials, $trackingNo, $company, $options);
    }

    /**
     * @param array<int, int> $itemIds
     * @param array<string, mixed> $options Supported keys: user, system_scope, company, phone, days, limit, delay, timeout, connect_timeout.
     * @param array<string, mixed>|null $currentUser
     * @return array{ok: bool, message: string, scanned: int, updated: int, skipped: int, failed: int, tenants: array<int, string>}
     */
    public function syncItems(string $tenantKey, array $itemIds = [], array $options = [], string $operator = '系统', ?array $currentUser = null): array
    {
        $credentials = $this->credentials($tenantKey);
        if ($credentials === null) {
            return $this->result(false, '缺少 ShowAPI 配置，请通过租户配置或环境变量提供 app_id/sign。', 0, 0, 0, 0, [$tenantKey]);
        }

        $user = $currentUser;
        if ($user === null && is_array($options['user'] ?? null)) {
            $user = $options['user'];
        }
        $systemScope = !empty($options['system_scope']);
        if ($user === null && !$systemScope) {
            return $this->result(false, '缺少当前用户上下文，已拒绝跨店铺范围同步。', 0, 0, 0, 0, [$tenantKey]);
        }

        $limit = $this->positiveInt($options['limit'] ?? 0);
        $days = $this->positiveInt($options['days'] ?? self::DEFAULT_DAYS) ?: self::DEFAULT_DAYS;
        $delay = $this->positiveInt($options['delay'] ?? 0);
        $company = (string) ($options['company'] ?? self::DEFAULT_COMPANY);
        $records = $this->candidateItems(
            $tenantKey,
            array_flip(array_values(array_unique(array_filter(array_map('intval', $itemIds))))),
            $limit,
            $days,
            $systemScope ? null : $user
        );

        $scanned = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $cache = [];
        foreach ($records as $record) {
            $scanned++;
            $trackingNo = $record['tracking_no'];
            if ($trackingNo === '') {
                $skipped++;
                continue;
            }

            $cacheKey = $this->carrierCode($company) . "\n" . $trackingNo;
            if (!array_key_exists($cacheKey, $cache)) {
                $cache[$cacheKey] = $this->queryWithCredentials($credentials, $trackingNo, $company, $options);
            }

            $query = $cache[$cacheKey];
            if (!$query['ok']) {
                $failed++;
                continue;
            }

            $changes = $this->changesFromQuery($query);
            if (!$changes) {
                $skipped++;
                continue;
            }

            try {
                $this->store->updateOrderItem($tenantKey, (int) $record['item_id'], $changes, $operator, 'ShowAPI国内物流同步');
                $updated++;
            } catch (\Throwable) {
                $failed++;
            }

            if ($delay > 0 && $scanned < count($records)) {
                usleep($delay * 1000);
            }
        }

        return $this->result(
            $failed === 0,
            sprintf('TB/PDD 国内物流同步完成：扫描 %d 条，更新 %d 条，跳过 %d 条，失败 %d 条。', $scanned, $updated, $skipped, $failed),
            $scanned,
            $updated,
            $skipped,
            $failed,
            [$tenantKey]
        );
    }

    /**
     * @param array{app_id: string, sign: string, proxy: string, phone: string} $credentials
     * @param array<string, mixed> $options
     * @return array{
     *     ok: bool,
     *     code: string,
     *     message: string,
     *     tracking_no: string,
     *     carrier_code: string,
     *     carrier_name: string,
     *     status: string,
     *     logistics_status: string,
     *     trace: string,
     *     steps: array<int, array{time: string, context: string}>
     * }
     */
    private function queryWithCredentials(array $credentials, string $trackingNo, string $company, array $options): array
    {
        if (!function_exists('curl_init')) {
            return $this->emptyQuery(false, 'curl_missing', '当前 PHP 环境缺少 curl 扩展。', $trackingNo, $company);
        }

        $carrierCode = $this->carrierCode($company);
        $phone = $this->phone((string) ($options['phone'] ?? $credentials['phone'] ?? self::DEFAULT_PHONE));
        $url = self::API_URL . '?' . http_build_query([
            'showapi_appid' => $credentials['app_id'],
            'showapi_sign' => $credentials['sign'],
            'nu' => $trackingNo,
            'com' => $carrierCode,
            'phone' => $phone,
        ], '', '&', PHP_QUERY_RFC3986);

        $response = $this->httpGet($url, [
            'proxy' => $credentials['proxy'],
            'timeout' => $this->positiveInt($options['timeout'] ?? 30) ?: 30,
            'connect_timeout' => $this->positiveInt($options['connect_timeout'] ?? 15) ?: 15,
        ]);
        if (!$response['ok']) {
            return $this->emptyQuery(false, 'http_error', $response['message'], $trackingNo, $carrierCode);
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || !array_key_exists('showapi_res_code', $data)) {
            return $this->emptyQuery(false, 'invalid_response', 'ShowAPI 返回不是有效业务 JSON。', $trackingNo, $carrierCode);
        }

        $resCode = (string) $data['showapi_res_code'];
        if ((int) $data['showapi_res_code'] !== 0) {
            $desc = self::SHOWAPI_ERRORS[$resCode] ?? (string) ($data['showapi_res_error'] ?? 'ShowAPI 查询失败。');
            return $this->emptyQuery(false, 'api_error', "ShowAPI 返回错误 {$resCode}: {$desc}", $trackingNo, $carrierCode);
        }

        $body = is_array($data['showapi_res_body'] ?? null) ? $data['showapi_res_body'] : [];
        $steps = $this->steps((array) ($body['data'] ?? []));
        $status = $this->statusFromSteps($steps);
        $carrierName = trim((string) ($body['expTextName'] ?? ''));

        return [
            'ok' => true,
            'code' => 'ok',
            'message' => $status !== '' ? "物流接口调用成功：{$status}" : '物流接口调用成功，暂无法判断状态。',
            'tracking_no' => $trackingNo,
            'carrier_code' => $carrierCode,
            'carrier_name' => $carrierName,
            'status' => $status,
            'logistics_status' => $this->logisticsStatus($status),
            'trace' => $this->formatTrace($steps),
            'steps' => $steps,
        ];
    }

    /**
     * @param array<string, string> $options
     * @return array{ok: bool, body: string, message: string}
     */
    private function httpGet(string $url, array $options): array
    {
        $ch = curl_init($url);
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => 'deflate, gzip',
            CURLOPT_TIMEOUT => (int) $options['timeout'],
            CURLOPT_CONNECTTIMEOUT => (int) $options['connect_timeout'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json,text/plain,*/*',
                'Accept-Language: zh-CN,en;q=0.5',
                'Connection: keep-alive',
            ],
            CURLOPT_USERAGENT => 'Mozilla/5.0',
        ];
        if (trim((string) ($options['proxy'] ?? '')) !== '') {
            $curlOptions[CURLOPT_PROXY] = trim((string) $options['proxy']);
        }
        curl_setopt_array($ch, $curlOptions);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            return ['ok' => false, 'body' => '', 'message' => $error !== '' ? $error : "ShowAPI HTTP {$status}"];
        }

        return ['ok' => true, 'body' => (string) $body, 'message' => ''];
    }

    /**
     * @param array<int|string, mixed> $rawSteps
     * @return array<int, array{time: string, context: string}>
     */
    private function steps(array $rawSteps): array
    {
        $steps = [];
        foreach ($rawSteps as $step) {
            if (!is_array($step)) {
                continue;
            }

            $context = trim((string) ($step['context'] ?? ''));
            $context = preg_replace('/[\r\n\t]+/u', ' ', $context) ?? $context;
            $time = trim((string) ($step['time'] ?? ''));
            if ($time === '' && $context === '') {
                continue;
            }

            $steps[] = ['time' => $time, 'context' => $context];
        }

        return $steps;
    }

    /** @param array<int, array{time: string, context: string}> $steps */
    private function statusFromSteps(array $steps): string
    {
        $status = count($steps) > 1 ? '发货中' : '';
        foreach (array_slice($steps, 0, 3) as $step) {
            foreach (self::SIGNED_KEYWORDS as $keyword) {
                if (str_contains($step['context'], $keyword)) {
                    return '已到货';
                }
            }
        }

        return $status;
    }

    /** @param array<int, array{time: string, context: string}> $steps */
    private function formatTrace(array $steps): string
    {
        $lines = [];
        foreach ($steps as $step) {
            $line = trim($step['time'] . ' ' . $step['context']);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\r\n", $lines);
    }

    /** @param array<string, mixed> $query @return array<string, string> */
    private function changesFromQuery(array $query): array
    {
        $status = trim((string) ($query['status'] ?? ''));
        if ($status === '') {
            return [];
        }

        $changes = [];
        $carrierName = trim((string) ($query['carrier_name'] ?? ''));
        if ($carrierName !== '') {
            $changes['ship_company'] = $carrierName;
        }

        $changes['purchase_status'] = $status;

        $logisticsStatus = trim((string) ($query['logistics_status'] ?? ''));
        if ($logisticsStatus !== '') {
            $changes['logistics'] = $logisticsStatus;
        }

        $trace = trim((string) ($query['trace'] ?? ''));
        if ($trace !== '') {
            $changes['logistic_trace'] = $trace;
        }

        return $changes;
    }

    private function logisticsStatus(string $status): string
    {
        return match ($status) {
            '发货中' => '运输中',
            '已到货' => '已签收',
            default => '',
        };
    }

    /**
     * @param array<int, int> $targetItemIds
     * @param array<string, mixed>|null $user
     * @return array<int, array{item_id: int, tracking_no: string}>
     */
    private function candidateItems(string $tenantKey, array $targetItemIds, int $limit, int $days, ?array $user): array
    {
        $records = [];
        $from = strtotime("-{$days} days");
        foreach ($this->appService->ordersForUser($tenantKey, $user) as $order) {
            foreach ($order['items'] ?? [] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $itemId = (int) ($item['id'] ?? 0);
                if ($targetItemIds && !isset($targetItemIds[$itemId])) {
                    continue;
                }
                if (($item['source_type'] ?? '') !== 'cn_purchase') {
                    continue;
                }
                if (!$this->isCandidateStatus((string) ($item['purchase_status'] ?? ''))) {
                    continue;
                }

                $trackingNo = $this->firstTrackingNumber((string) ($item['ship_number'] ?? ''));
                if ($trackingNo === '') {
                    continue;
                }

                $date = strtotime((string) (($item['purchase_time'] ?? '') ?: ($order['imported_at'] ?? $order['order_date'] ?? '')));
                if ($date !== false && $from !== false && $date < $from) {
                    continue;
                }

                $records[] = ['item_id' => $itemId, 'tracking_no' => $trackingNo];
                if ($limit > 0 && count($records) >= $limit) {
                    return $records;
                }
            }
        }

        return $records;
    }

    private function isCandidateStatus(string $status): bool
    {
        return in_array($status, ['国内采购-已采购', '国内采购-TB/PDD已采购', '发货中'], true);
    }

    /** @return array{app_id: string, sign: string, proxy: string, phone: string}|null */
    private function credentials(string $tenantKey): ?array
    {
        $settings = $this->store->tenantSettings($tenantKey);
        foreach ($this->tenantCredentialSections($settings) as $section) {
            if (array_key_exists('enabled', $section) && empty($section['enabled'])) {
                continue;
            }

            $appId = $this->firstString($section, ['app_id', 'appid', 'appId', 'AppID']);
            $sign = $this->firstString($section, ['sign', 'secret', 'app_secret', 'Sign']);
            if ($appId !== '' && $sign !== '') {
                return [
                    'app_id' => $appId,
                    'sign' => $sign,
                    'proxy' => $this->firstString($section, ['proxy', 'rotation_proxy']),
                    'phone' => $this->phone($this->firstString($section, ['phone', 'default_phone']) ?: self::DEFAULT_PHONE),
                ];
            }
        }

        $appId = $this->env(['EXPRESS_SHOWAPI_APP_ID', 'EXPRESS_SHOWAPI_APPID', 'SHOWAPI_APP_ID', 'SHOWAPI_APPID', 'XIZHEN_SHOWAPI_APP_ID']);
        $sign = $this->env(['EXPRESS_SHOWAPI_SIGN', 'SHOWAPI_SIGN', 'XIZHEN_SHOWAPI_SIGN']);
        if ($appId === '' || $sign === '') {
            return null;
        }

        return [
            'app_id' => $appId,
            'sign' => $sign,
            'proxy' => $this->env(['EXPRESS_SHOWAPI_PROXY', 'SHOWAPI_PROXY', 'XIZHEN_SHOWAPI_PROXY']),
            'phone' => $this->phone($this->env(['EXPRESS_SHOWAPI_PHONE', 'SHOWAPI_PHONE']) ?: self::DEFAULT_PHONE),
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<int, array<string, mixed>>
     */
    private function tenantCredentialSections(array $settings): array
    {
        $sections = [];
        foreach (['showapi', 'express_showapi'] as $key) {
            if (is_array($settings[$key] ?? null)) {
                $sections[] = $settings[$key];
            }
        }

        $logistics = is_array($settings['logistics'] ?? null) ? $settings['logistics'] : [];
        foreach (['showapi', 'express_showapi'] as $key) {
            if (is_array($logistics[$key] ?? null)) {
                $sections[] = $logistics[$key];
            }
        }

        return $sections;
    }

    /** @param array<string, mixed> $section @param array<int, string> $keys */
    private function firstString(array $section, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($section[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** @param array<int, string> $names */
    private function env(array $names): string
    {
        foreach ($names as $name) {
            $value = getenv($name);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private function firstTrackingNumber(string $value): string
    {
        $parts = preg_split('/[,，\s]+/u', trim($value)) ?: [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                return $part;
            }
        }

        return '';
    }

    private function carrierCode(string $company): string
    {
        $company = trim($company);
        $company = preg_replace('/[\r\n\t]+/u', '', $company) ?? '';

        return $company !== '' ? $company : self::DEFAULT_COMPANY;
    }

    private function phone(string $phone): string
    {
        $phone = preg_replace('/\D+/', '', $phone) ?? '';

        return $phone !== '' ? substr($phone, -8) : self::DEFAULT_PHONE;
    }

    private function positiveInt(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /**
     * @return array{ok: bool, message: string, scanned: int, updated: int, skipped: int, failed: int, tenants: array<int, string>}
     */
    private function result(bool $ok, string $message, int $scanned, int $updated, int $skipped, int $failed, array $tenants): array
    {
        return [
            'ok' => $ok,
            'message' => $message,
            'scanned' => $scanned,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
            'tenants' => $tenants,
        ];
    }

    /**
     * @return array{
     *     ok: bool,
     *     code: string,
     *     message: string,
     *     tracking_no: string,
     *     carrier_code: string,
     *     carrier_name: string,
     *     status: string,
     *     logistics_status: string,
     *     trace: string,
     *     steps: array<int, array{time: string, context: string}>
     * }
     */
    private function emptyQuery(bool $ok, string $code, string $message, string $trackingNo, string $company): array
    {
        return [
            'ok' => $ok,
            'code' => $code,
            'message' => $message,
            'tracking_no' => $trackingNo,
            'carrier_code' => $this->carrierCode($company),
            'carrier_name' => '',
            'status' => '',
            'logistics_status' => '',
            'trace' => '',
            'steps' => [],
        ];
    }
}
