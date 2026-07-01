<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class Alibaba1688LogisticsService
{
    private const API_BASE = 'https://gw.open.1688.com/openapi';
    private const ARRIVAL_KEYWORDS = ['签收', '妈妈', '自提点', '派送成功', '菜鸟驿站', '兔喜快递超市', '已投递', '已妥投', '喵站', '兔喜生活'];

    public function __construct(private readonly StoreInterface $store)
    {
    }

    /**
     * @param array<int, int> $itemIds
     * @param array<string, mixed> $options
     * @return array{ok: bool, message: string, scanned: int, updated: int, skipped: int, failed: int, tenants: array<int, string>}
     */
    public function syncItems(string $tenantKey, array $itemIds = [], array $options = [], string $operator = '系统'): array
    {
        $credentials = $this->credentials($tenantKey);
        if (!$credentials) {
            return $this->result(false, '缺少 1688 API 配置。请在租户设置启用 1688 接口，并配置 apikeys.conf。', 0, 0, 0, 0, [$tenantKey]);
        }

        $limit = $this->positiveInt($options['limit'] ?? 0);
        $delay = $this->positiveInt($options['delay'] ?? 0);
        $targetItemIds = array_flip(array_values(array_unique(array_filter(array_map('intval', $itemIds)))));
        $records = $this->candidateItems($tenantKey, $targetItemIds, $limit);

        $scanned = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($records as $record) {
            $scanned++;
            $tabaono = (string) ($record['item']['tabaono'] ?? '');
            if (!$this->validOrderId($tabaono)) {
                $skipped++;
                continue;
            }

            try {
                $logistics = $this->fetchOrderLogistics($tabaono, $credentials);
                if (!$logistics['ok']) {
                    $failed++;
                    continue;
                }

                $changes = $this->changesFromLogistics($logistics);
                if (!$changes) {
                    $skipped++;
                    continue;
                }

                $this->store->updateOrderItem($tenantKey, (int) $record['item']['id'], $changes, $operator, '1688物流同步');
                $updated++;
            } catch (\Throwable) {
                $failed++;
            }

            if ($delay > 0 && $scanned < count($records)) {
                usleep($delay * 1000);
            }
        }

        $ok = $failed === 0;
        return $this->result(
            $ok,
            sprintf('1688 物流同步完成：扫描 %d 条，更新 %d 条，跳过 %d 条，失败 %d 条。', $scanned, $updated, $skipped, $failed),
            $scanned,
            $updated,
            $skipped,
            $failed,
            [$tenantKey]
        );
    }

    /**
     * @param array<string, mixed> $logistics
     * @return array<string, string>
     */
    private function changesFromLogistics(array $logistics): array
    {
        $changes = [];
        foreach ([
            'ship_company' => 'company',
            'ship_number' => 'bill_no',
            'logistics' => 'status',
            'logistic_trace' => 'trace',
        ] as $field => $key) {
            $value = trim((string) ($logistics[$key] ?? ''));
            if ($value !== '') {
                $changes[$field] = $value;
            }
        }

        return $changes;
    }

    /**
     * @param array{app_key: string, app_secret: string, access_token: string} $credentials
     * @return array{ok: bool, company: string, bill_no: string, status: string, trace: string, message: string}
     */
    private function fetchOrderLogistics(string $orderId, array $credentials): array
    {
        $info = $this->request($credentials, 'com.alibaba.logistics/alibaba.trade.getLogisticsInfos.buyerView', [
            'access_token' => $credentials['access_token'],
            'orderId' => $orderId,
            'webSite' => '1688',
        ]);
        if (!$info['ok']) {
            return $this->emptyLogistics(false, $info['message']);
        }

        $row = is_array($info['data']['result'][0] ?? null) ? $info['data']['result'][0] : [];
        $company = trim((string) ($row['logisticsCompanyName'] ?? ''));
        $billNo = trim((string) ($row['logisticsBillNo'] ?? ''));
        $status = $this->statusLabel((string) ($row['status'] ?? ''));

        $trace = $this->request($credentials, 'com.alibaba.logistics/alibaba.trade.getLogisticsTraceInfo.buyerView', [
            'access_token' => $credentials['access_token'],
            'orderId' => $orderId,
            'webSite' => '1688',
        ]);
        $steps = $trace['ok'] ? $this->traceSteps($trace['data']) : [];
        if ($status === '已签收') {
            $status = $this->verifiedSignedStatus($steps);
        }

        return [
            'ok' => true,
            'company' => $company,
            'bill_no' => $billNo,
            'status' => $status,
            'trace' => $this->formatTrace($steps),
            'message' => '',
        ];
    }

    /**
     * @param array{app_key: string, app_secret: string, access_token: string} $credentials
     * @param array<string, string> $args
     * @return array{ok: bool, data: array<string, mixed>, message: string}
     */
    private function request(array $credentials, string $namespace, array $args): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'data' => [], 'message' => '当前 PHP 环境缺少 curl 扩展。'];
        }

        $urlPath = 'param2/1/' . $namespace . '/' . rawurlencode($credentials['app_key']);
        $url = $this->signedUrl($urlPath, $credentials['app_secret'], $args);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            return ['ok' => false, 'data' => [], 'message' => $error !== '' ? $error : "1688 API HTTP {$status}"];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return ['ok' => false, 'data' => [], 'message' => '1688 API 返回不是有效 JSON。'];
        }
        if (($data['success'] ?? false) !== true) {
            return ['ok' => false, 'data' => $data, 'message' => (string) ($data['errorMessage'] ?? '1688 API 查询失败。')];
        }

        return ['ok' => true, 'data' => $data, 'message' => ''];
    }

    /** @param array<string, string> $args */
    private function signedUrl(string $urlPath, string $appSecret, array $args): string
    {
        $parts = [];
        foreach ($args as $key => $value) {
            $parts[] = $key . $value;
        }
        sort($parts, SORT_STRING);
        $signature = strtoupper(bin2hex(hash_hmac('sha1', $urlPath . implode('', $parts), $appSecret, true)));
        $args['_aop_signature'] = $signature;

        return self::API_BASE . '/' . $urlPath . '?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{app_key: string, app_secret: string, access_token: string}|null */
    private function credentials(string $tenantKey): ?array
    {
        $settings = $this->store->tenantSettings($tenantKey);
        $api = is_array($settings['api_1688'] ?? null) ? $settings['api_1688'] : [];
        if (empty($api['enabled'])) {
            return null;
        }

        $content = trim((string) ($api['config_content'] ?? ''));
        if ($content === '') {
            $path = trim((string) ($api['config_file'] ?? ''));
            $absolute = $path !== '' ? BASE_PATH . '/' . ltrim(str_replace('\\', '/', $path), '/') : '';
            if ($absolute !== '' && is_file($absolute)) {
                $content = trim((string) file_get_contents($absolute));
            }
        }

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            if (count($parts) >= 4) {
                return [
                    'app_key' => (string) $parts[1],
                    'app_secret' => (string) $parts[2],
                    'access_token' => (string) $parts[3],
                ];
            }
            if (str_contains($line, '=')) {
                parse_str(str_replace(["\t", ' '], '&', $line), $parsed);
                $appKey = trim((string) ($parsed['app_key'] ?? $parsed['AppKey'] ?? $parsed['key'] ?? ''));
                $secret = trim((string) ($parsed['app_secret'] ?? $parsed['Secret'] ?? $parsed['secret'] ?? ''));
                $token = trim((string) ($parsed['access_token'] ?? $parsed['Token'] ?? $parsed['token'] ?? ''));
                if ($appKey !== '' && $secret !== '' && $token !== '') {
                    return ['app_key' => $appKey, 'app_secret' => $secret, 'access_token' => $token];
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{accept_time: string, remark: string}>
     */
    private function traceSteps(array $data): array
    {
        $trace = is_array($data['logisticsTrace'][0]['logisticsSteps'] ?? null) ? $data['logisticsTrace'][0]['logisticsSteps'] : [];
        $steps = [];
        foreach ($trace as $step) {
            if (!is_array($step)) {
                continue;
            }
            $steps[] = [
                'accept_time' => trim((string) ($step['acceptTime'] ?? '')),
                'remark' => trim((string) ($step['remark'] ?? '')),
            ];
        }

        return $steps;
    }

    /** @param array<int, array{accept_time: string, remark: string}> $steps */
    private function verifiedSignedStatus(array $steps): string
    {
        if (!$steps) {
            return '';
        }

        foreach (self::ARRIVAL_KEYWORDS as $index => $keyword) {
            if ($this->traceHasWord($steps, $keyword, max(2, $index + 2))) {
                return '已签收';
            }
        }

        $lastTime = strtotime((string) ($steps[array_key_last($steps)]['accept_time'] ?? ''));
        if ($lastTime !== false && time() - $lastTime < 30 * 86400) {
            return '运输中';
        }

        return '';
    }

    /** @param array<int, array{accept_time: string, remark: string}> $steps */
    private function traceHasWord(array $steps, string $word, int $maxSteps): bool
    {
        $checked = 0;
        for ($i = count($steps) - 1; $i >= 0; $i--) {
            $checked++;
            if ($checked > $maxSteps) {
                break;
            }
            if (str_contains((string) ($steps[$i]['remark'] ?? ''), $word)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array{accept_time: string, remark: string}> $steps */
    private function formatTrace(array $steps): string
    {
        $lines = [];
        foreach (array_reverse($steps) as $step) {
            $line = trim($step['accept_time'] . ' ' . $step['remark']);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\r\n", $lines);
    }

    /** @return array<int, array{order: array<string, mixed>, item: array<string, mixed>}> */
    private function candidateItems(string $tenantKey, array $targetItemIds, int $limit): array
    {
        $records = [];
        foreach ($this->store->orders($tenantKey) as $order) {
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
                if (!in_array((string) ($item['purchase_status'] ?? ''), ['国内采购-已采购', '发货中'], true)) {
                    continue;
                }
                if (trim((string) ($item['tabaono'] ?? '')) === '') {
                    continue;
                }
                $records[] = ['order' => $order, 'item' => $item];
                if ($limit > 0 && count($records) >= $limit) {
                    return $records;
                }
            }
        }

        return $records;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'WAITACCEPT' => '未受理',
            'CANCEL' => '已撤销',
            'ACCEPT' => '已受理',
            'TRANSPORT' => '运输中',
            'NOGET' => '揽件失败',
            'SIGN' => '已签收',
            'UNSIGN' => '签收异常',
            default => '',
        };
    }

    private function validOrderId(string $orderId): bool
    {
        return preg_match('/^\d{16,19}$/', trim($orderId)) === 1;
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

    /** @return array{ok: bool, company: string, bill_no: string, status: string, trace: string, message: string} */
    private function emptyLogistics(bool $ok, string $message): array
    {
        return ['ok' => $ok, 'company' => '', 'bill_no' => '', 'status' => '', 'trace' => '', 'message' => $message];
    }
}
