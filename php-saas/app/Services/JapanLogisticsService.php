<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class JapanLogisticsService
{
    private const SAGAWA_URL = 'https://k2k.sagawa-exp.co.jp/p/web/okurijosearch.do?okurijoNo=%s';
    private const JAPANPOST_URL = 'https://trackings.post.japanpost.jp/services/srv/search/direct?reqCodeNo1=%s';
    private const YAMATO_URL = 'http://nanoappli.com/tracking/api/%s.json';

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
        $mapping = (string) (($this->store->globalSettings()['logistics_mapping']['tracking_query'] ?? '') ?: '');
        if (trim($mapping) === '') {
            return $this->result(false, '缺少日本物流“物流状态查询”映射，请先由超管在系统设置中配置运单前缀到 sagawa/japanpost/yamato。', 0, 0, 0, 0, [$tenantKey]);
        }

        $limit = $this->positiveInt($options['limit'] ?? 0);
        $days = $this->positiveInt($options['days'] ?? 30) ?: 30;
        $delay = $this->positiveInt($options['delay'] ?? 0);
        $targetItemIds = array_flip(array_values(array_unique(array_filter(array_map('intval', $itemIds)))));
        $records = $this->candidateItems($tenantKey, $targetItemIds, $limit, $days);

        $scanned = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($records as $record) {
            $scanned++;
            $numbers = $this->trackingNumbers((string) (($record['item']['intl_number'] ?? '') ?: ($record['item']['ship_number'] ?? '')));
            if (!$numbers) {
                $skipped++;
                continue;
            }

            $result = $this->bestTrackingResult($numbers, $mapping);
            if (!$result['queried']) {
                $skipped++;
            } elseif (!$result['ok']) {
                $failed++;
            } elseif ($result['status'] === '') {
                $skipped++;
            } else {
                $this->store->updateOrderItem($tenantKey, (int) $record['item']['id'], [
                    'logistics' => $result['status'],
                ], $operator, '日本物流同步');
                $updated++;
            }

            if ($delay > 0 && $scanned < count($records)) {
                usleep($delay * 1000);
            }
        }

        return $this->result(
            $failed === 0,
            sprintf('日本物流同步完成：扫描 %d 条，更新 %d 条，跳过 %d 条，失败 %d 条。', $scanned, $updated, $skipped, $failed),
            $scanned,
            $updated,
            $skipped,
            $failed,
            [$tenantKey]
        );
    }

    /** @param array<int, string> $numbers @return array{queried: bool, ok: bool, status: string, completed_date: string} */
    private function bestTrackingResult(array $numbers, string $mapping): array
    {
        $valid = [];
        $queried = false;
        $failed = 0;
        foreach ($numbers as $number) {
            $carrier = $this->detectCarrier($mapping, $number);
            if ($carrier === '') {
                continue;
            }
            $queried = true;
            $result = $this->query($carrier, $number);
            if (!$result['ok']) {
                $failed++;
                continue;
            }
            if ($result['status'] !== '') {
                $valid[] = $result;
            }
        }

        foreach ($valid as $result) {
            if ($this->isCompleteStatus($result['status'])) {
                return ['queried' => true, 'ok' => true, 'status' => $result['status'], 'completed_date' => $result['completed_date']];
            }
        }
        if ($valid) {
            $result = $valid[0];
            return ['queried' => true, 'ok' => true, 'status' => $result['status'], 'completed_date' => $result['completed_date']];
        }

        return ['queried' => $queried, 'ok' => $queried && $failed === 0, 'status' => '', 'completed_date' => ''];
    }

    /** @return array{ok: bool, status: string, completed_date: string} */
    private function query(string $carrier, string $number): array
    {
        return match ($carrier) {
            'sagawa' => $this->querySagawa($number),
            'japanpost' => $this->queryJapanPost($number),
            'yamato' => $this->queryYamato($number),
            default => ['ok' => false, 'status' => '', 'completed_date' => ''],
        };
    }

    /** @return array{ok: bool, status: string, completed_date: string} */
    private function querySagawa(string $number): array
    {
        $response = $this->httpGet(sprintf(self::SAGAWA_URL, rawurlencode($number)));
        if (!$response['ok']) {
            return ['ok' => false, 'status' => '', 'completed_date' => ''];
        }
        if (!preg_match('/<span.*?class="state">(?P<state>.*?)<\/span>/is', $response['body'], $matched)) {
            return ['ok' => false, 'status' => '', 'completed_date' => ''];
        }

        $status = $this->cleanStatus($matched['state']);
        if ($status === '該当なし') {
            return ['ok' => true, 'status' => '', 'completed_date' => ''];
        }
        if ($this->isCompleteStatus($status)) {
            $status = str_contains($status, 'お客様引渡完了') ? 'お客様引渡完了' : '配達完了';
        }

        return ['ok' => true, 'status' => $status, 'completed_date' => $this->extractLatestDate($response['body'])];
    }

    /** @return array{ok: bool, status: string, completed_date: string} */
    private function queryJapanPost(string $number): array
    {
        $response = $this->httpGet(sprintf(self::JAPANPOST_URL, rawurlencode($number)));
        if (!$response['ok']) {
            return ['ok' => false, 'status' => '', 'completed_date' => ''];
        }
        if (str_contains($response['body'], 'お問い合わせ番号が見つかりません')) {
            return ['ok' => true, 'status' => '', 'completed_date' => ''];
        }

        $keywords = ['お届け済み', '引受', '到着', '発送', '持ち出し中', 'ご不在', '保管', '配達中', '返送', '差出', '転送', '配達希望受付', '調査中', '国際交換局', '通関手続中', '税関検査のため税関へ提示'];
        $pattern = '/<td[^>]*>\s*(' . implode('|', array_map('preg_quote', $keywords)) . '[^<]*)\s*<\/td>/iu';
        if (!preg_match_all($pattern, $response['body'], $matches)) {
            return ['ok' => false, 'status' => '', 'completed_date' => ''];
        }

        $status = trim((string) end($matches[1]));
        if (str_contains($status, 'お届け済み') || str_contains($status, 'お客様引渡完了')) {
            $status = str_contains($status, 'お客様引渡完了') ? 'お客様引渡完了' : '配達完了';
        }

        return ['ok' => true, 'status' => $status, 'completed_date' => $this->extractLatestDate($response['body'])];
    }

    /** @return array{ok: bool, status: string, completed_date: string} */
    private function queryYamato(string $number): array
    {
        $response = $this->httpGet(sprintf(self::YAMATO_URL, rawurlencode($number)));
        if (!$response['ok']) {
            return ['ok' => false, 'status' => '', 'completed_date' => ''];
        }

        $data = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', trim($response['body'])) ?? $response['body'], true);
        if (!is_array($data)) {
            return ['ok' => false, 'status' => '', 'completed_date' => ''];
        }

        $status = trim((string) ($data['status'] ?? ''));
        $list = is_array($data['statusList'] ?? null) ? $data['statusList'] : [];
        if ($status === '' && $list) {
            $last = end($list);
            $status = is_array($last) ? trim((string) ($last['status'] ?? '')) : '';
        }
        if ($status === '伝票番号未登録') {
            $status = '';
        }

        return ['ok' => true, 'status' => $status, 'completed_date' => $this->extractYamatoDate($list)];
    }

    /** @return array{ok: bool, body: string, message: string} */
    private function httpGet(string $url): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'body' => '', 'message' => '当前 PHP 环境缺少 curl 扩展。'];
        }

        $proxy = $this->proxy();
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => 'deflate, gzip',
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: ja,en-US;q=0.7,en;q=0.3',
                'Connection: keep-alive',
            ],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ];
        if ($proxy !== '') {
            $options[CURLOPT_PROXY] = $proxy;
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            return ['ok' => false, 'body' => '', 'message' => $error !== '' ? $error : "HTTP {$status}"];
        }

        return ['ok' => true, 'body' => (string) $body, 'message' => ''];
    }

    /** @return array<int, array{order: array<string, mixed>, item: array<string, mixed>}> */
    private function candidateItems(string $tenantKey, array $targetItemIds, int $limit, int $days): array
    {
        $records = [];
        $from = strtotime("-{$days} days");
        foreach ($this->store->orders($tenantKey) as $order) {
            foreach ($order['items'] ?? [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemId = (int) ($item['id'] ?? 0);
                if ($targetItemIds && !isset($targetItemIds[$itemId])) {
                    continue;
                }
                $status = (string) ($item['purchase_status'] ?? '');
                if (!in_array($status, ['已发货代订单', '已发日本', '已发出荷通知', '日本仓库已处理'], true)) {
                    continue;
                }
                if ($this->isCompleteStatus((string) ($item['logistics'] ?? ''))) {
                    continue;
                }
                $number = trim((string) (($item['intl_number'] ?? '') ?: ($item['ship_number'] ?? '')));
                if ($number === '') {
                    continue;
                }
                $date = strtotime((string) (($item['purchase_time'] ?? '') ?: ($order['order_date'] ?? '')));
                if ($date !== false && $date < $from) {
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

    /** @return array<int, string> */
    private function trackingNumbers(string $raw): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => trim($value),
            preg_split('/[,，\s]+/u', $raw) ?: []
        ))));
    }

    private function detectCarrier(string $mapping, string $number): string
    {
        $matches = [];
        foreach (preg_split('/\R/', trim($mapping)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$prefix, $carrier] = array_map('trim', explode('=', $line, 2));
            if ($prefix !== '' && str_starts_with($number, $prefix)) {
                $matches[$prefix] = strtolower($carrier);
            }
        }
        uksort($matches, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $carrier = reset($matches);

        return in_array($carrier, ['sagawa', 'japanpost', 'yamato'], true) ? (string) $carrier : '';
    }

    private function cleanStatus(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strip_tags($value);
        return trim((string) preg_replace('/\s+/u', '', $value));
    }

    private function isCompleteStatus(string $status): bool
    {
        return str_starts_with($status, '配達完了') || str_starts_with($status, 'お客様引渡完了') || str_contains($status, 'お届け済み');
    }

    private function extractLatestDate(string $html): string
    {
        if (preg_match_all('/(\d{4}\/\d{1,2}\/\d{1,2})\s*(\d{1,2}:\d{2})/', $html, $matches) && $matches[0]) {
            $index = count($matches[0]) - 1;
            return $this->formatDate($matches[1][$index], $matches[2][$index]);
        }
        if (preg_match_all('/(\d{1,2}\/\d{1,2})\s+(\d{1,2}:\d{2})/', $html, $matches) && $matches[0]) {
            $index = count($matches[0]) - 1;
            return $this->formatDate($matches[1][$index], $matches[2][$index]);
        }

        return '';
    }

    /** @param array<int, mixed> $list */
    private function extractYamatoDate(array $list): string
    {
        for ($i = count($list) - 1; $i >= 0; $i--) {
            $row = is_array($list[$i]) ? $list[$i] : [];
            if ($this->isCompleteStatus((string) ($row['status'] ?? ''))) {
                return $this->formatDate((string) ($row['date'] ?? ''), (string) ($row['time'] ?? ''));
            }
        }
        $last = is_array(end($list)) ? end($list) : [];

        return $last ? $this->formatDate((string) ($last['date'] ?? ''), (string) ($last['time'] ?? '')) : '';
    }

    private function formatDate(string $date, string $time = ''): string
    {
        $date = trim($date);
        $time = trim($time) ?: '00:00';
        if ($date === '') {
            return '';
        }

        $parts = explode('/', $date);
        if (count($parts) === 2) {
            $year = (int) date('Y');
            $candidate = sprintf('%04d-%02d-%02d %s:00', $year, (int) $parts[0], (int) $parts[1], $time);
            $ts = strtotime($candidate);
            if ($ts !== false && $ts > time() + 30 * 86400) {
                $candidate = sprintf('%04d-%02d-%02d %s:00', $year - 1, (int) $parts[0], (int) $parts[1], $time);
            }
            return $candidate;
        }

        $ts = strtotime($date . ' ' . $time);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : '';
    }

    private function proxy(): string
    {
        $proxy = is_array($this->store->globalSettings()['proxy'] ?? null) ? $this->store->globalSettings()['proxy'] : [];
        if (empty($proxy['enabled'])) {
            return '';
        }

        return trim((string) ($proxy['rotation_proxy'] ?? ''));
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
}
