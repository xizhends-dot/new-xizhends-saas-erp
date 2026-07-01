<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class WaybillCheckService
{
    /** @var array<string, array{label: string, url: string}> */
    private const JP_CARRIERS = [
        'sagawa' => [
            'label' => '佐川急便',
            'url' => 'https://k2k.sagawa-exp.co.jp/p/web/okurijosearch.do?okurijoNo=%s',
        ],
        'japanpost' => [
            'label' => '日本郵便',
            'url' => 'https://trackings.post.japanpost.jp/services/srv/search/direct?reqCodeNo1=%s',
        ],
        'yamato' => [
            'label' => 'ヤマト運輸',
            'url' => 'https://member.kms.kuronekoyamato.co.jp/parcel/detail?pno=%s',
        ],
    ];

    /** @var array<int, string> */
    private const DOMESTIC_EXPECTED_STATUSES = [
        '国内采购-已采购',
        '国内采购-TB/PDD已采购',
        '发货中',
        '已到货',
        '已发货代订单',
        '已发日本',
        '已发出荷通知',
        '已到货问题件',
    ];

    /** @var array<int, string> */
    private const JP_EXPECTED_STATUSES = [
        '已发货代订单',
        '已发日本',
        '已发出荷通知',
        '日本仓库已处理',
        '发货中',
    ];

    /** @var array<int, string> */
    private const COMPLETE_STATUS_KEYWORDS = [
        '配達完了',
        'お客様引渡完了',
        'お届け済み',
        '签收',
        '已签收',
        '已送达',
        '妥投',
    ];

    /** @var array<int, string> */
    private const PLACEHOLDER_NUMBERS = [
        '-',
        '--',
        '0',
        '00',
        '000',
        '0000',
        '00000000',
        'na',
        'n/a',
        'none',
        'null',
        '无',
        '暂无',
        '未发',
        '未发货',
        '待定',
        '空',
        'なし',
    ];

    private AppService $appService;

    public function __construct(private readonly StoreInterface $store, ?AppService $appService = null)
    {
        $this->appService = $appService ?? new AppService($store);
    }

    /**
     * @param array<string, mixed>|null $user
     * @param array<string, mixed> $filters
     * @return array{
     *     filters: array<string, mixed>,
     *     summary: array<string, int>,
     *     rows: array<int, array<string, mixed>>,
     *     duplicates: array<string, array<int, array<string, mixed>>>
     * }
     */
    public function report(string $tenantKey, ?array $user = null, array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $records = $this->candidateRecords($tenantKey, $user, $filters);
        $indexes = $this->buildNumberIndexes($records);
        $summary = [
            'scanned' => count($records),
            'shown' => 0,
            'ok' => 0,
            'empty' => 0,
            'invalid' => 0,
            'duplicate' => 0,
            'pending' => 0,
            'jp_jumpable' => 0,
            'jp_missing_mapping' => 0,
        ];

        $rows = [];
        $limit = (int) $filters['limit'];
        foreach ($records as $record) {
            $row = $this->buildRow($tenantKey, $record['order'], $record['item'], $indexes, (string) $filters['scope']);
            $this->accumulateSummary($summary, $row);

            if (!$this->rowMatchesStatus($row, (string) $filters['status'])) {
                continue;
            }

            if ($limit === 0 || count($rows) < $limit) {
                $rows[] = $row;
            }
        }
        $summary['shown'] = count($rows);

        return [
            'filters' => $filters,
            'summary' => $summary,
            'rows' => $rows,
            'duplicates' => [
                'domestic' => $this->duplicateGroups($indexes['domestic']),
                'jp' => $this->duplicateGroups($indexes['jp']),
            ],
        ];
    }

    /**
     * @return array{ok: bool, number: string, carrier: string, carrier_label: string, url: string, message: string}
     */
    public function japaneseTrackingUrl(string $tenantKey, string $number, string $carrierHint = ''): array
    {
        unset($tenantKey);

        $numbers = $this->trackingNumbers($number);
        $number = $numbers[0] ?? trim($number);
        if ($number === '') {
            return $this->trackingUrlResult(false, '', '', '', '运单号为空。');
        }

        $carrier = $this->detectJapaneseCarrier($number, $carrierHint);
        if ($carrier === '') {
            return $this->trackingUrlResult(false, $number, '', '', '没有匹配到日本快递公司映射。');
        }

        $definition = self::JP_CARRIERS[$carrier];
        return $this->trackingUrlResult(
            true,
            $number,
            $carrier,
            sprintf($definition['url'], rawurlencode($number)),
            ''
        );
    }

    /** @return array<string, string> */
    public function japaneseCarrierLabels(): array
    {
        $labels = [];
        foreach (self::JP_CARRIERS as $code => $definition) {
            $labels[$code] = $definition['label'];
        }

        return $labels;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $status = trim((string) ($filters['status'] ?? 'needs_check'));
        if (!in_array($status, ['all', 'needs_check', 'empty', 'invalid', 'duplicate', 'pending', 'ok'], true)) {
            $status = 'needs_check';
        }

        $scope = trim((string) ($filters['scope'] ?? 'all'));
        if (!in_array($scope, ['all', 'domestic', 'jp'], true)) {
            $scope = 'all';
        }

        $limit = (int) ($filters['limit'] ?? 500);
        if ($limit < 0) {
            $limit = 0;
        }
        if ($limit > 2000) {
            $limit = 2000;
        }

        return [
            'from' => $this->dateFilter($filters['from'] ?? $filters['date_from'] ?? $filters['cdate'] ?? ''),
            'to' => $this->dateFilter($filters['to'] ?? $filters['date_to'] ?? $filters['cdate2'] ?? ''),
            'platform' => trim((string) ($filters['platform'] ?? '')),
            'store' => trim((string) ($filters['store'] ?? '')),
            'keyword' => trim((string) ($filters['keyword'] ?? $filters['q'] ?? '')),
            'scope' => $scope,
            'status' => $status,
            'limit' => $limit,
        ];
    }

    /**
     * @param array<string, mixed>|null $user
     * @param array<string, mixed> $filters
     * @return array<int, array{order: array<string, mixed>, item: array<string, mixed>}>
     */
    private function candidateRecords(string $tenantKey, ?array $user, array $filters): array
    {
        $records = [];
        foreach ($this->appService->ordersForUser($tenantKey, $user) as $order) {
            if (!$this->orderMatchesFilters($order, $filters)) {
                continue;
            }

            foreach ($order['items'] ?? [] as $item) {
                if (!is_array($item) || !$this->itemMatchesKeyword($order, $item, (string) $filters['keyword'])) {
                    continue;
                }

                $records[] = ['order' => $order, 'item' => $item];
            }
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $filters
     */
    private function orderMatchesFilters(array $order, array $filters): bool
    {
        if ($filters['platform'] !== '' && (string) ($order['platform'] ?? '') !== $filters['platform']) {
            return false;
        }
        if ($filters['store'] !== '' && (string) ($order['store'] ?? '') !== $filters['store']) {
            return false;
        }

        $date = $this->orderDate($order);
        if ($filters['from'] !== '' && $date !== '' && strcmp(substr($date, 0, 10), (string) $filters['from']) < 0) {
            return false;
        }
        if ($filters['to'] !== '' && $date !== '' && strcmp(substr($date, 0, 10), (string) $filters['to']) > 0) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $order */
    private function orderDate(array $order): string
    {
        foreach (['imported_at', 'created_at', 'order_date'] as $field) {
            $value = trim((string) ($order[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $item */
    private function itemMatchesKeyword(array $order, array $item, string $keyword): bool
    {
        if ($keyword === '') {
            return true;
        }

        $haystack = implode(' ', [
            $order['platform_order_id'] ?? '',
            $order['store'] ?? '',
            $order['customer']['name'] ?? '',
            $order['customer']['phone'] ?? '',
            $item['item_code'] ?? '',
            $item['title'] ?? '',
            $item['ship_company'] ?? '',
            $item['ship_number'] ?? '',
            $item['intl_number'] ?? '',
            $item['tabaono'] ?? '',
        ]);

        return str_contains($this->lower($haystack), $this->lower($keyword));
    }

    /**
     * @param array<int, array{order: array<string, mixed>, item: array<string, mixed>}> $records
     * @return array{domestic: array<string, array<string, mixed>>, jp: array<string, array<string, mixed>>}
     */
    private function buildNumberIndexes(array $records): array
    {
        $indexes = ['domestic' => [], 'jp' => []];
        foreach ($records as $record) {
            $order = $record['order'];
            $item = $record['item'];
            $entry = [
                'order_id' => (int) ($order['id'] ?? 0),
                'order_no' => (string) ($order['platform_order_id'] ?? ''),
                'item_id' => (int) ($item['id'] ?? 0),
                'item_code' => (string) ($item['item_code'] ?? ''),
                'store' => (string) ($order['store'] ?? ''),
            ];

            foreach ($this->trackingNumbers((string) ($item['ship_number'] ?? '')) as $number) {
                $this->addIndexEntry($indexes['domestic'], $number, $entry);
            }
            foreach ($this->trackingNumbers($this->japanWaybillRaw($item)) as $number) {
                $this->addIndexEntry($indexes['jp'], $number, $entry);
            }
        }

        return $indexes;
    }

    /**
     * @param array<string, array<string, mixed>> $index
     * @param array<string, mixed> $entry
     */
    private function addIndexEntry(array &$index, string $number, array $entry): void
    {
        $key = $this->numberKey($number);
        if ($key === '') {
            return;
        }

        $index[$key] ??= ['number' => $number, 'orders' => [], 'items' => []];
        $index[$key]['orders'][(int) $entry['order_id']] = $entry['order_no'];
        $index[$key]['items'][(int) $entry['item_id']] = $entry;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     * @param array{domestic: array<string, array<string, mixed>>, jp: array<string, array<string, mixed>>} $indexes
     * @return array<string, mixed>
     */
    private function buildRow(string $tenantKey, array $order, array $item, array $indexes, string $scope): array
    {
        $domesticNumbers = $this->trackingNumbers((string) ($item['ship_number'] ?? ''));
        $jpNumbers = $this->trackingNumbers($this->japanWaybillRaw($item));
        $issues = [];
        $severities = [];

        if ($scope !== 'jp') {
            $this->appendDomesticIssues($issues, $severities, $item, $domesticNumbers, $indexes['domestic']);
        }
        if ($scope !== 'domestic') {
            $this->appendJapanIssues($issues, $severities, $tenantKey, $item, $jpNumbers, $indexes['jp']);
        }

        $jpNumber = $jpNumbers[0] ?? '';
        $tracking = $jpNumber !== ''
            ? $this->japaneseTrackingUrl($tenantKey, $jpNumber, (string) ($item['ship_company'] ?? ''))
            : $this->trackingUrlResult(false, '', '', '', '');

        $severity = $this->rowSeverity($severities);
        return [
            'order_id' => (int) ($order['id'] ?? 0),
            'item_id' => (int) ($item['id'] ?? 0),
            'platform' => (string) ($order['platform'] ?? ''),
            'platform_order_id' => (string) ($order['platform_order_id'] ?? ''),
            'store' => (string) ($order['store'] ?? ''),
            'order_date' => $this->orderDate($order),
            'customer_name' => (string) ($order['customer']['name'] ?? ''),
            'item_code' => (string) ($item['item_code'] ?? ''),
            'title' => (string) ($item['title'] ?? ''),
            'source_type' => (string) ($item['source_type'] ?? ''),
            'purchase_status' => (string) ($item['purchase_status'] ?? ($item['out_status'] ?? '')),
            'domestic_number' => implode(', ', $domesticNumbers),
            'domestic_company' => (string) ($item['ship_company'] ?? ''),
            'jp_number' => implode(', ', $jpNumbers),
            'jp_carrier' => $tracking['carrier_label'],
            'jp_tracking_url' => $tracking['url'],
            'logistics' => (string) ($item['logistics'] ?? ''),
            'issues' => array_keys($issues),
            'issue_labels' => array_values($issues),
            'severity' => $severity,
            'status_label' => $this->statusLabel($issues, $severity),
        ];
    }

    /**
     * @param array<string, string> $issues
     * @param array<int, string> $severities
     * @param array<string, mixed> $item
     * @param array<int, string> $numbers
     * @param array<string, array<string, mixed>> $index
     */
    private function appendDomesticIssues(array &$issues, array &$severities, array $item, array $numbers, array $index): void
    {
        $required = $this->requiresDomesticWaybill($item);
        if ($required && !$numbers) {
            $this->addIssue($issues, $severities, 'empty_domestic', '缺国内运单', 'warning');
            return;
        }

        foreach ($numbers as $number) {
            $invalid = $this->invalidNumberReason($number);
            if ($invalid !== '') {
                $this->addIssue($issues, $severities, 'invalid_domestic', '国内运单无效：' . $invalid, 'danger');
            }
            if ($this->isDuplicateAcrossOrders($index, $number)) {
                $this->addIssue($issues, $severities, 'duplicate_domestic', '国内运单跨订单重复', 'danger');
            }
        }

        if ($numbers && !$this->hasCompleteLogistics((string) ($item['logistics'] ?? ''))) {
            $this->addIssue($issues, $severities, 'pending_domestic', '国内运单待核对', 'warning');
        }
    }

    /**
     * @param array<string, string> $issues
     * @param array<int, string> $severities
     * @param array<string, mixed> $item
     * @param array<int, string> $numbers
     * @param array<string, array<string, mixed>> $index
     */
    private function appendJapanIssues(array &$issues, array &$severities, string $tenantKey, array $item, array $numbers, array $index): void
    {
        $required = $this->requiresJapanWaybill($item);
        if ($required && !$numbers) {
            $this->addIssue($issues, $severities, 'empty_jp', '缺国际运单', 'warning');
            return;
        }

        foreach ($numbers as $number) {
            $invalid = $this->invalidNumberReason($number);
            if ($invalid !== '') {
                $this->addIssue($issues, $severities, 'invalid_jp', '国际运单无效：' . $invalid, 'danger');
            }
            if ($this->isDuplicateAcrossOrders($index, $number)) {
                $this->addIssue($issues, $severities, 'duplicate_jp', '国际运单跨订单重复', 'danger');
            }

            $tracking = $this->japaneseTrackingUrl($tenantKey, $number, (string) ($item['ship_company'] ?? ''));
            if (!$tracking['ok']) {
                $this->addIssue($issues, $severities, 'missing_jp_mapping', '日本快递未匹配', 'warning');
            }
        }

        if ($numbers && !$this->hasCompleteLogistics((string) ($item['logistics'] ?? ''))) {
            $this->addIssue($issues, $severities, 'pending_jp', '国际运单待核对', 'warning');
        }
    }

    /**
     * @param array<string, string> $issues
     * @param array<int, string> $severities
     */
    private function addIssue(array &$issues, array &$severities, string $code, string $label, string $severity): void
    {
        $issues[$code] = $label;
        $severities[] = $severity;
    }

    /** @param array<string, mixed> $item */
    private function requiresDomesticWaybill(array $item): bool
    {
        if (($item['source_type'] ?? '') !== 'cn_purchase') {
            return false;
        }

        $status = (string) ($item['purchase_status'] ?? '');
        return in_array($status, self::DOMESTIC_EXPECTED_STATUSES, true)
            || trim((string) ($item['purchase_time'] ?? '')) !== ''
            || trim((string) ($item['tabaono'] ?? '')) !== '';
    }

    /** @param array<string, mixed> $item */
    private function requiresJapanWaybill(array $item): bool
    {
        $status = (string) (($item['out_status'] ?? '') ?: ($item['purchase_status'] ?? ''));
        if (in_array($status, self::JP_EXPECTED_STATUSES, true)) {
            return true;
        }

        return trim((string) ($item['intl_number'] ?? '')) !== ''
            || trim((string) ($item['intl_fee'] ?? '')) !== ''
            || trim((string) ($item['intl_qty'] ?? '')) !== ''
            || trim((string) ($item['intl_weight'] ?? '')) !== '';
    }

    /** @param array<string, mixed> $item */
    private function japanWaybillRaw(array $item): string
    {
        $intlNumber = trim((string) ($item['intl_number'] ?? ''));
        if ($intlNumber !== '') {
            return $intlNumber;
        }

        $shipNumber = trim((string) ($item['ship_number'] ?? ''));
        if ($shipNumber === '') {
            return '';
        }

        $carrier = $this->detectJapaneseCarrier($shipNumber, (string) ($item['ship_company'] ?? ''));
        if ($carrier !== '') {
            return $shipNumber;
        }

        return ($item['source_type'] ?? '') === 'jp_stock' ? $shipNumber : '';
    }

    /** @return array<int, string> */
    private function trackingNumbers(string $raw): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => trim($value),
            preg_split('/[,，、\s]+/u', $raw) ?: []
        ), static fn (string $value): bool => $value !== '')));
    }

    private function invalidNumberReason(string $number): string
    {
        $trimmed = trim($number);
        $lower = $this->lower($trimmed);
        if (in_array($lower, self::PLACEHOLDER_NUMBERS, true)) {
            return '占位符';
        }

        if (preg_match('/[<>"\'\\\\]/u', $trimmed)) {
            return '包含非法字符';
        }

        $key = $this->numberKey($trimmed);
        $length = strlen($key);
        if ($length < 8) {
            return '长度过短';
        }
        if ($length > 40) {
            return '长度过长';
        }
        if (preg_match('/^([A-Z0-9])\1{7,}$/', $key)) {
            return '重复字符';
        }

        return '';
    }

    private function numberKey(string $number): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($number));
    }

    /** @param array<string, array<string, mixed>> $index */
    private function isDuplicateAcrossOrders(array $index, string $number): bool
    {
        $key = $this->numberKey($number);
        if ($key === '' || !isset($index[$key])) {
            return false;
        }

        return count(array_filter(array_keys((array) $index[$key]['orders']))) > 1;
    }

    private function hasCompleteLogistics(string $status): bool
    {
        foreach (self::COMPLETE_STATUS_KEYWORDS as $keyword) {
            if ($keyword !== '' && str_contains($status, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function detectJapaneseCarrier(string $number, string $carrierHint = ''): string
    {
        $mapping = (array) ($this->store->globalSettings()['logistics_mapping'] ?? []);
        foreach (['jp_carrier', 'tracking_query'] as $key) {
            $carrier = $this->matchCarrierMapping((string) ($mapping[$key] ?? ''), $number);
            if ($carrier !== '') {
                return $carrier;
            }
        }

        return $this->normalizeCarrier($carrierHint);
    }

    private function matchCarrierMapping(string $mapping, string $number): string
    {
        $matches = [];
        foreach (preg_split('/\R/', trim($mapping)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }

            [$prefix, $carrier] = array_map('trim', explode('=', $line, 2));
            if ($prefix !== '' && str_starts_with($number, $prefix)) {
                $normalized = $this->normalizeCarrier($carrier);
                if ($normalized !== '') {
                    $matches[$prefix] = $normalized;
                }
            }
        }
        uksort($matches, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return (string) (reset($matches) ?: '');
    }

    private function normalizeCarrier(string $value): string
    {
        $value = $this->lower(trim($value));
        $value = str_replace([' ', '_', '-', '　'], '', $value);
        return match (true) {
            $value === 'sagawa' || str_contains($value, '佐川') => 'sagawa',
            $value === 'japanpost' || $value === 'post' || str_contains($value, '日本郵便') || str_contains($value, '日本邮政') || str_contains($value, '郵便') => 'japanpost',
            $value === 'yamato' || $value === 'kuroneko' || str_contains($value, 'ヤマト') || str_contains($value, '黑猫') || str_contains($value, '黒猫') => 'yamato',
            default => '',
        };
    }

    /**
     * @param array<string, array<string, mixed>> $index
     * @return array<int, array<string, mixed>>
     */
    private function duplicateGroups(array $index): array
    {
        $groups = [];
        foreach ($index as $entry) {
            $orders = array_values(array_filter((array) ($entry['orders'] ?? [])));
            if (count($orders) <= 1) {
                continue;
            }

            $groups[] = [
                'number' => (string) ($entry['number'] ?? ''),
                'orders' => $orders,
                'items' => array_values((array) ($entry['items'] ?? [])),
            ];
        }

        return array_slice($groups, 0, 100);
    }

    /**
     * @param array<string, int> $summary
     * @param array<string, mixed> $row
     */
    private function accumulateSummary(array &$summary, array $row): void
    {
        $issues = (array) ($row['issues'] ?? []);
        if (!$issues) {
            $summary['ok']++;
        }
        if ($this->hasIssuePrefix($issues, 'empty_')) {
            $summary['empty']++;
        }
        if ($this->hasIssuePrefix($issues, 'invalid_')) {
            $summary['invalid']++;
        }
        if ($this->hasIssuePrefix($issues, 'duplicate_')) {
            $summary['duplicate']++;
        }
        if ($this->hasIssuePrefix($issues, 'pending_')) {
            $summary['pending']++;
        }
        if (($row['jp_tracking_url'] ?? '') !== '') {
            $summary['jp_jumpable']++;
        } elseif (($row['jp_number'] ?? '') !== '') {
            $summary['jp_missing_mapping']++;
        }
    }

    /** @param array<int, mixed> $issues */
    private function hasIssuePrefix(array $issues, string $prefix): bool
    {
        foreach ($issues as $issue) {
            if (str_starts_with((string) $issue, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $issues */
    private function statusLabel(array $issues, string $severity): string
    {
        if (!$issues) {
            return '正常';
        }
        if ($severity === 'danger') {
            return '异常';
        }
        if (isset($issues['empty_domestic']) || isset($issues['empty_jp'])) {
            return '缺少运单';
        }

        return '待核对';
    }

    /** @param array<int, string> $severities */
    private function rowSeverity(array $severities): string
    {
        if (in_array('danger', $severities, true)) {
            return 'danger';
        }
        if (in_array('warning', $severities, true)) {
            return 'warning';
        }

        return 'ok';
    }

    /** @param array<string, mixed> $row */
    private function rowMatchesStatus(array $row, string $status): bool
    {
        $issues = (array) ($row['issues'] ?? []);
        return match ($status) {
            'all' => true,
            'ok' => !$issues,
            'needs_check' => (bool) $issues,
            'empty' => $this->hasIssuePrefix($issues, 'empty_'),
            'invalid' => $this->hasIssuePrefix($issues, 'invalid_'),
            'duplicate' => $this->hasIssuePrefix($issues, 'duplicate_'),
            'pending' => $this->hasIssuePrefix($issues, 'pending_'),
            default => (bool) $issues,
        };
    }

    private function dateFilter(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $ts = strtotime($value);
        return $ts === false ? '' : date('Y-m-d', $ts);
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /**
     * @return array{ok: bool, number: string, carrier: string, carrier_label: string, url: string, message: string}
     */
    private function trackingUrlResult(bool $ok, string $number, string $carrier, string $url, string $message): array
    {
        return [
            'ok' => $ok,
            'number' => $number,
            'carrier' => $carrier,
            'carrier_label' => $carrier !== '' ? self::JP_CARRIERS[$carrier]['label'] : '',
            'url' => $url,
            'message' => $message,
        ];
    }
}
