<?php

declare(strict_types=1);

namespace Xizhen\Services;

/**
 * 发货单导出的可选字段唯一清单:key → 显示名/分组/类型 + 取值逻辑。
 * 老 outexcel 模板的全部特殊转换(电话补0、USD 折算等)收敛在这里。
 */
final class ExportFieldRegistry
{
    private const GROUP_ORDER = '订单';
    private const GROUP_CUSTOMER = '收件人';
    private const GROUP_ITEM = '商品';
    private const GROUP_LOGISTICS = '物流';
    private const GROUP_MONEY = '金额';
    private const GROUP_IMAGE = '图片';
    private const GROUP_GENERATED = '生成值';

    /** @return array<string, array{label: string, group: string, type: string}> */
    public static function fields(): array
    {
        return [
            'order.platform_order_id' => ['label' => '订单号', 'group' => self::GROUP_ORDER, 'type' => 'text'],
            'order.store' => ['label' => '店铺名', 'group' => self::GROUP_ORDER, 'type' => 'text'],
            'order.order_date' => ['label' => '订单时间', 'group' => self::GROUP_ORDER, 'type' => 'text'],
            'order.platform' => ['label' => '平台代码', 'group' => self::GROUP_ORDER, 'type' => 'text'],
            'item.order_detail_id' => ['label' => '订单明细ID', 'group' => self::GROUP_ORDER, 'type' => 'text'],
            'customer.name' => ['label' => '收件人姓名', 'group' => self::GROUP_CUSTOMER, 'type' => 'text'],
            'customer.phone' => ['label' => '收件电话', 'group' => self::GROUP_CUSTOMER, 'type' => 'text'],
            'customer.zip' => ['label' => '收件邮编', 'group' => self::GROUP_CUSTOMER, 'type' => 'text'],
            'customer.address' => ['label' => '收件地址', 'group' => self::GROUP_CUSTOMER, 'type' => 'text'],
            'customer.prefecture' => ['label' => '都道府县', 'group' => self::GROUP_CUSTOMER, 'type' => 'text'],
            'customer.city' => ['label' => '市区町村', 'group' => self::GROUP_CUSTOMER, 'type' => 'text'],
            'customer.address1' => ['label' => '地址1', 'group' => self::GROUP_CUSTOMER, 'type' => 'text'],
            'customer.address2' => ['label' => '地址2', 'group' => self::GROUP_CUSTOMER, 'type' => 'text'],
            'item.title' => ['label' => '商品标题', 'group' => self::GROUP_ITEM, 'type' => 'text'],
            'item.option' => ['label' => '规格', 'group' => self::GROUP_ITEM, 'type' => 'text'],
            'item.chinese_option' => ['label' => '中文规格', 'group' => self::GROUP_ITEM, 'type' => 'text'],
            'item.quantity' => ['label' => '数量', 'group' => self::GROUP_ITEM, 'type' => 'text'],
            'item.weight' => ['label' => '重量', 'group' => self::GROUP_ITEM, 'type' => 'text'],
            'item.material' => ['label' => '材质', 'group' => self::GROUP_ITEM, 'type' => 'text'],
            'item.comment' => ['label' => '备注', 'group' => self::GROUP_ITEM, 'type' => 'text'],
            'item.tranship_comment' => ['label' => '转运备注', 'group' => self::GROUP_ITEM, 'type' => 'text'],
            'logistics.ship_company' => ['label' => '国内快递公司', 'group' => self::GROUP_LOGISTICS, 'type' => 'text'],
            'logistics.ship_number' => ['label' => '国内运单号', 'group' => self::GROUP_LOGISTICS, 'type' => 'text'],
            'logistics.domestic_full' => ['label' => '国内单号(公司+单号)', 'group' => self::GROUP_LOGISTICS, 'type' => 'text'],
            'logistics.intl_tracking' => ['label' => '国际运单号', 'group' => self::GROUP_LOGISTICS, 'type' => 'text'],
            'logistics.intl_status' => ['label' => '国际运单状态', 'group' => self::GROUP_LOGISTICS, 'type' => 'text'],
            'logistics.trace' => ['label' => '物流轨迹/签收地', 'group' => self::GROUP_LOGISTICS, 'type' => 'text'],
            'money.unit_price' => ['label' => '单价', 'group' => self::GROUP_MONEY, 'type' => 'text'],
            'money.usd_unit_price' => ['label' => 'USD折算单价(÷2÷145)', 'group' => self::GROUP_MONEY, 'type' => 'text'],
            'money.amount' => ['label' => '采购金额', 'group' => self::GROUP_MONEY, 'type' => 'text'],
            'money.cn_amount' => ['label' => '国内运费', 'group' => self::GROUP_MONEY, 'type' => 'text'],
            'money.com_amount' => ['label' => '佣金额', 'group' => self::GROUP_MONEY, 'type' => 'text'],
            'item.image' => ['label' => '商品图片', 'group' => self::GROUP_IMAGE, 'type' => 'image'],
            'generated.today_md' => ['label' => '今天(m-d)', 'group' => self::GROUP_GENERATED, 'type' => 'date'],
            'generated.today_ymd' => ['label' => '今天(Y/m/d)', 'group' => self::GROUP_GENERATED, 'type' => 'date'],
            'generated.wowma_carrier_code' => ['label' => 'Wowma运送公司代码', 'group' => self::GROUP_GENERATED, 'type' => 'text'],
        ];
    }

    /** @return array<string, array<int, array{key: string, label: string, type: string}>> */
    public static function groups(): array
    {
        $groups = [];
        foreach (self::fields() as $key => $meta) {
            $groups[$meta['group']][] = ['key' => $key, 'label' => $meta['label'], 'type' => $meta['type']];
        }

        return $groups;
    }

    public static function has(string $key): bool
    {
        return isset(self::fields()[$key]);
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     */
    public static function resolve(string $key, array $order, array $item): mixed
    {
        $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];

        return match ($key) {
            'order.platform_order_id' => (string) ($order['platform_order_id'] ?? ''),
            'order.store' => (string) ($order['store'] ?? ''),
            'order.order_date' => (string) ($order['order_date'] ?? ''),
            'order.platform' => (string) ($order['platform'] ?? ''),
            'item.order_detail_id' => (string) ((($item['order_detail_id'] ?? '') !== '' ? $item['order_detail_id'] : null) ?? ($order['platform_order_id'] ?? '')),
            'customer.name' => (string) ($customer['name'] ?? ''),
            'customer.phone' => self::phone((string) ($customer['phone'] ?? '')),
            'customer.zip' => self::zip((string) ($customer['zip'] ?? '')),
            'customer.address' => self::address($customer),
            'customer.prefecture' => (string) ($customer['prefecture'] ?? ''),
            'customer.city' => (string) ($customer['city'] ?? ''),
            'customer.address1' => (string) ($customer['address1'] ?? ''),
            'customer.address2' => (string) ($customer['address2'] ?? ''),
            'item.title' => (string) ($item['title'] ?? ''),
            'item.option' => (string) ($item['option'] ?? ''),
            'item.chinese_option' => (string) (($item['chinese_option'] ?? '') ?: ($item['option'] ?? '')),
            'item.quantity' => max(1, (int) ($item['quantity'] ?? 1)),
            'item.weight' => (string) ($item['weight'] ?? ''),
            'item.material' => (string) ($item['material'] ?? ''),
            'item.comment' => (string) ($item['comment'] ?? ''),
            'item.tranship_comment' => (string) ($item['tranship_comment'] ?? ''),
            'logistics.ship_company' => (string) (($item['ship_company'] ?? '') ?: ($order['ship_method'] ?? '')),
            'logistics.ship_number' => (string) ($item['ship_number'] ?? ''),
            'logistics.domestic_full' => self::domesticFull($item),
            'logistics.intl_tracking' => trim((string) (($item['intl_number'] ?? '') ?: ($item['ship_number'] ?? ''))),
            'logistics.intl_status' => (string) (($item['intl_status'] ?? '') ?: ($item['logistics'] ?? '')),
            'logistics.trace' => (string) ($item['logistic_trace'] ?? ''),
            'money.unit_price' => (string) ($item['unit_price'] ?? ''),
            'money.usd_unit_price' => self::usdUnitPrice($item),
            'money.amount' => (string) ($item['amount'] ?? ''),
            'money.cn_amount' => (string) ($item['cn_amount'] ?? ''),
            'money.com_amount' => (string) ($item['com_amount'] ?? ''),
            'item.image' => (string) (($item['sku_image'] ?? '') ?: (($item['main_image'] ?? '') ?: ($item['image'] ?? ''))),
            'generated.today_md' => date('m-d'),
            'generated.today_ymd' => date('Y/m/d'),
            'generated.wowma_carrier_code' => self::wowmaCarrierCode($item),
            default => '',
        };
    }

    private static function phone(string $value): string
    {
        $value = trim($value);
        if ($value !== '' && !str_contains($value, '-') && !str_starts_with($value, '0')) {
            return '0' . $value;
        }

        return $value;
    }

    private static function zip(string $value): string
    {
        $value = trim($value);
        if ($value !== '' && !str_contains($value, '-') && strlen($value) !== 7) {
            return str_pad($value, 7, '0', STR_PAD_LEFT);
        }

        return $value;
    }

    /** @param array<string, mixed> $customer */
    private static function address(array $customer): string
    {
        $parts = array_filter([
            (string) ($customer['prefecture'] ?? ''),
            (string) ($customer['city'] ?? ''),
            (string) ($customer['address1'] ?? ''),
            (string) ($customer['address2'] ?? ''),
        ], static fn (string $value): bool => trim($value) !== '');

        return $parts ? implode('', $parts) : (string) ($customer['address'] ?? '');
    }

    /** @param array<string, mixed> $item */
    private static function domesticFull(array $item): string
    {
        return trim(implode(' ', array_filter([
            (string) ($item['ship_company'] ?? ''),
            (string) ($item['ship_number'] ?? ''),
        ], static fn (string $value): bool => trim($value) !== '')));
    }

    /** @param array<string, mixed> $item */
    private static function usdUnitPrice(array $item): string
    {
        $raw = str_replace([',', '¥', '￥', '円', ' '], '', trim((string) ($item['unit_price'] ?? '0')));
        $price = is_numeric($raw) ? (float) $raw : 0.0;
        if ($price <= 0) {
            return '';
        }

        return (string) round($price / 2 / 145, 2);
    }

    /** @param array<string, mixed> $item */
    private static function wowmaCarrierCode(array $item): string
    {
        $tracking = trim((string) (($item['intl_number'] ?? '') ?: ($item['ship_number'] ?? '')));
        $fallback = (string) ($item['ship_company'] ?? '');
        if ($tracking === '') {
            return $fallback;
        }

        $prefixMap = [
            '368' => '1', '28' => '1', '654' => '1', '597' => '1', '763' => '1', '766' => '1',
            '281' => '1', '44' => '1', '47' => '1', '361' => '2', '35' => '2', '01' => '2',
            '51' => '2', '56' => '2', '32' => '6', '42' => '6', '52' => '6', '82' => '6',
            '48' => '1', '37' => '1', '36' => '2', '39' => '1',
        ];
        foreach ($prefixMap as $prefix => $code) {
            if (str_starts_with($tracking, (string) $prefix)) {
                return $code;
            }
        }

        return $fallback;
    }
}
