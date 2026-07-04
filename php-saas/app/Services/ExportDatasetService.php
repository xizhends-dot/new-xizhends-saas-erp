<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class ExportDatasetService
{

    public function __construct(
        private readonly StoreInterface $store,
        private readonly OrderFilterService $orderFilterService,
        private readonly ProfitService $profitService,
    ) {
    }


    /**
     * @param array<string, mixed>|null $user
     * @return array{name: string, filename: string, headers: array<int, string>, rows: array<int, array<int, mixed>>}
     */
    public function exportDataset(string $tenantKey, string $type, ?array $user, array $criteria = []): array
    {
        $orders = $this->ordersForExport($tenantKey, $user, $criteria);
        $platformNames = $this->platformNames();
        $platformCode = (string) ($criteria['platform'] ?? '');
        $today = date('Ymd-His');

        if ($type === 'finance') {
            $summary = $this->profitSummaryForOrders($tenantKey, $orders);
            return [
                'name' => '财务利润表',
                'filename' => "finance-{$tenantKey}-{$today}.csv",
                'headers' => ['订单号', '店铺', '平台', '数量合计', '销售额', '日本邮费', '采购成本', '国际运费', '运费口径', '扣点来源', '扣点', '平台费', '扣点后折算收入', '毛利', '毛利率', '汇率', '折算销售额'],
                'rows' => array_map(fn (array $row): array => [
                    $row['order_no'],
                    $row['store'],
                    $platformNames[(string) ($row['platform'] ?? '')] ?? ($row['platform'] ?? ''),
                    $row['quantity'],
                    $row['sales'],
                    $row['japan_postage'],
                    $row['purchase_cost'],
                    $row['intl_fee'],
                    $row['intl_fee_source'],
                    $row['deduction_source'],
                    $row['deduction'],
                    $row['platform_fee'],
                    $row['sales_after_deduction_converted'],
                    $row['profit'],
                    $row['margin'] . '%',
                    $row['exchange_rate'],
                    $row['sales_converted'],
                ], $summary['rows']),
            ];
        }

        if ($type === 'platform') {
            $name = $platformCode !== ''
                ? (($platformNames[$platformCode] ?? $platformCode) . '订单表')
                : '平台订单表';

            return [
                'name' => $name,
                'filename' => "platform-{$tenantKey}-{$today}.csv",
                'headers' => ['店铺番号', '平台', '订单编号', '订单日期', '产品ID', '产品信息', '变量1', '变量2', '数量', '送付先 氏名', '郵便番号', '住所', '箱号', '信封大小', '配送方式', '客人邮箱', '电话号码'],
                'rows' => $this->platformExportRows($orders, $platformNames),
            ];
        }

        if ($type === 'delivery_notice') {
            return [
                'name' => '发货通知表',
                'filename' => "delivery-notice-{$tenantKey}-{$today}.csv",
                'headers' => ['邮件地址', '姓名', '性别', '生日', '手机', '地区', '变量1', '变量2', '变量3', '变量4', '变量5', '变量6', '变量7', '变量8', '变量9', '变量10'],
                'rows' => $this->deliveryNoticeRows($orders),
            ];
        }

        if ($type === 'customers') {
            return [
                'name' => '客户资料',
                'filename' => "customers-{$tenantKey}-{$today}.csv",
                'headers' => ['平台', '店铺', '订单号', '姓名', '电话', '邮编', '地址', '邮箱', '下单时间', '订单金额'],
                'rows' => array_map(fn (array $order): array => [
                    $platformNames[(string) ($order['platform'] ?? '')] ?? ($order['platform'] ?? ''),
                    $order['store'] ?? '',
                    $order['platform_order_id'] ?? '',
                    $order['customer']['name'] ?? '',
                    $order['customer']['phone'] ?? '',
                    $order['customer']['zip'] ?? '',
                    $order['customer']['address'] ?? '',
                    $order['customer']['mail'] ?? '',
                    $order['order_date'] ?? '',
                    $order['total'] ?? 0,
                ], $orders),
            ];
        }

        if ($type === 'shipment' || $type === 'logistics') {
            $rows = [];
            foreach ($orders as $order) {
                foreach ($order['items'] ?? [] as $item) {
                    $rows[] = [
                        $platformNames[(string) ($order['platform'] ?? '')] ?? ($order['platform'] ?? ''),
                        $order['store'] ?? '',
                        $order['platform_order_id'] ?? '',
                        $item['item_code'] ?? '',
                        $item['title'] ?? '',
                        $item['source_type'] ?? '',
                        $item['ship_company'] ?? '',
                        $item['ship_number'] ?? '',
                        $item['tabaono'] ?? '',
                        $item['logistics'] ?? '',
                        $item['out_status'] ?? '',
                    ];
                }
            }

            return [
                'name' => $type === 'shipment' ? '发货表' : '物流表',
                'filename' => "{$type}-{$tenantKey}-{$today}.csv",
                'headers' => ['平台', '店铺', '订单号', 'ItemId', '商品名', '货源地', '物流公司', '国内运单号', '1688订单号', '物流状态', '出库状态'],
                'rows' => $rows,
            ];
        }

        $rows = [];
        foreach ($orders as $order) {
            foreach ($order['items'] ?? [] as $item) {
                if ($type === 'purchase' && ($item['source_type'] ?? '') !== 'cn_purchase') {
                    continue;
                }

                $rows[] = [
                    $platformNames[(string) ($order['platform'] ?? '')] ?? ($order['platform'] ?? ''),
                    $order['store'] ?? '',
                    $order['platform_order_id'] ?? '',
                    $order['order_date'] ?? '',
                    $item['item_code'] ?? '',
                    $item['title'] ?? '',
                    $item['option'] ?? '',
                    $item['quantity'] ?? 0,
                    $item['source_type'] ?? '',
                    $item['purchase_status'] ?? '',
                    $item['buyer'] ?? '',
                    $item['purchase_time'] ?? '',
                    $item['purchase_link'] ?? '',
                    $item['amount'] ?? 0,
                    $item['tabaono'] ?? '',
                    $item['ship_company'] ?? '',
                    $item['ship_number'] ?? '',
                ];
            }
        }

        return [
            'name' => '采购表',
            'filename' => "purchase-{$tenantKey}-{$today}.csv",
            'headers' => ['平台', '店铺', '订单号', '订单时间', 'ItemId', '商品名', '规格', '数量', '货源地', '采购状态', '采购人', '采购时间', '采购链接', '采购金额', '1688订单号', '物流公司', '国内运单号'],
            'rows' => $rows,
        ];
    }


    /**
     * @param array<int, array<string, mixed>> $orders
     * @param array<string, string> $platformNames
     * @return array<int, array<int, mixed>>
     */
    private function platformExportRows(array $orders, array $platformNames): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
            foreach ($order['items'] ?? [] as $item) {
                $rows[] = [
                    $order['store'] ?? '',
                    $platformNames[(string) ($order['platform'] ?? '')] ?? ($order['platform'] ?? ''),
                    $order['platform_order_id'] ?? '',
                    $order['order_date'] ?? '',
                    ($item['item_code'] ?? '') ?: ($item['lot_number'] ?? ''),
                    $item['title'] ?? '',
                    $item['option'] ?? '',
                    $item['chinese_option'] ?? '',
                    $item['quantity'] ?? 0,
                    $customer['name'] ?? '',
                    $customer['zip'] ?? '',
                    $customer['address'] ?? '',
                    '',
                    '',
                    $order['ship_method'] ?? '',
                    $customer['mail'] ?? '',
                    $customer['phone'] ?? '',
                ];
            }
        }

        return $rows;
    }


    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array<int, mixed>>
     */
    private function deliveryNoticeRows(array $orders): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
            $shipNumbers = array_values(array_filter(array_map(
                static fn (array $item): string => trim((string) (($item['intl_number'] ?? '') ?: ($item['ship_number'] ?? ''))),
                $order['items'] ?? []
            )));
            $rows[] = [
                $customer['mail'] ?? '',
                '',
                '',
                '',
                '',
                '',
                $customer['name'] ?? '',
                $order['platform_order_id'] ?? '',
                implode(' / ', array_unique($shipNumbers)),
                $order['store'] ?? '',
                '',
                '',
                '',
                '',
                '',
                '',
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed>|null $user @param array<string, mixed> $criteria @return array<int, array<string, mixed>> */
    private function ordersForExport(string $tenantKey, ?array $user, array $criteria): array
    {
        return $this->orderFilterService->ordersForExport($tenantKey, $user, $criteria);
    }

    /** @return array<string, string> */
    private function platformNames(): array
    {
        $names = [];
        foreach ($this->store->platforms() as $platform) {
            $names[$platform['code']] = $platform['name'];
        }
        return $names;
    }

    /** @param array<int, array<string, mixed>> $orders @return array<string, mixed> */
    private function profitSummaryForOrders(string $tenantKey, array $orders): array
    {
        return $this->profitService->profitSummaryForOrders($tenantKey, $orders);
    }
}
