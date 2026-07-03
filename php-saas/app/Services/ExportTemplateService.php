<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

/**
 * 发货单导出模板的 CRUD 与校验。自定义模板存 tenantSettings['export_templates'];
 * 预置模板(builtin_*)是代码内常量,只读。
 */
final class ExportTemplateService
{
    private const MAX_TEMPLATES = 30;
    private const MAX_COLUMNS = 50;
    private const MAX_LABEL_LENGTH = 64;
    private const RAW_PREFIXES = ['order.', 'item.', 'customer.'];
    private const LEGACY_VARIANTS = ['riya', 'sx', 'wd', 'qoo10', 'wowma'];

    public function __construct(private readonly StoreInterface $store)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function builtinTemplates(): array
    {
        return [
            [
                'id' => 'builtin_riya', 'name' => '日亚发货单(预置)', 'format' => 'xlsx', 'builtin' => true,
                'columns' => [
                    ['type' => 'field', 'key' => 'generated.today_md', 'label' => '日期'],
                    ['type' => 'field', 'key' => 'logistics.domestic_full', 'label' => '国内单号'],
                    ['type' => 'field', 'key' => 'item.image', 'label' => '产品图片'],
                    ['type' => 'const', 'label' => '渠道名称（普货/带电）', 'value' => ''],
                    ['type' => 'field', 'key' => 'item.option', 'label' => '备注1'],
                    ['type' => 'field', 'key' => 'item.comment', 'label' => '备注2'],
                    ['type' => 'field', 'key' => 'order.platform_order_id', 'label' => '订单号'],
                    ['type' => 'const', 'label' => '佐川单号', 'value' => ''],
                    ['type' => 'const', 'label' => '发件公司名英文', 'value' => ''],
                    ['type' => 'const', 'label' => '发件公司电话', 'value' => ''],
                    ['type' => 'const', 'label' => '发件公司地址', 'value' => ''],
                    ['type' => 'field', 'key' => 'customer.phone', 'label' => '收件电话'],
                    ['type' => 'field', 'key' => 'customer.name', 'label' => '收件人'],
                    ['type' => 'const', 'label' => '收件人2', 'value' => ''],
                    ['type' => 'field', 'key' => 'customer.zip', 'label' => '收件人邮编'],
                    ['type' => 'field', 'key' => 'customer.address', 'label' => '收件人地址'],
                    ['type' => 'const', 'label' => '重量', 'value' => ''],
                    ['type' => 'const', 'label' => '长（CM）', 'value' => ''],
                    ['type' => 'const', 'label' => '宽（CM）', 'value' => ''],
                    ['type' => 'const', 'label' => '高（CM）', 'value' => ''],
                    ['type' => 'const', 'label' => '申报商品数量', 'value' => ''],
                    ['type' => 'const', 'label' => '申报币种', 'value' => ''],
                    ['type' => 'const', 'label' => '第一品名（英文）', 'value' => ''],
                    ['type' => 'const', 'label' => '材质（英文）', 'value' => ''],
                    ['type' => 'field', 'key' => 'item.quantity', 'label' => '数量'],
                    ['type' => 'field', 'key' => 'money.usd_unit_price', 'label' => '单价（USD）'],
                ],
            ],
            [
                'id' => 'builtin_sx', 'name' => '盛欣发货单(预置)', 'format' => 'xlsx', 'builtin' => true,
                'columns' => $this->sxColumns(),
            ],
            [
                'id' => 'builtin_wd', 'name' => '万达发货单(预置)', 'format' => 'xlsx', 'builtin' => true,
                'columns' => $this->sxColumns(),
            ],
            [
                'id' => 'builtin_qoo10', 'name' => 'Qoo10出荷表(预置)', 'format' => 'csv', 'builtin' => true,
                'columns' => [
                    ['type' => 'field', 'key' => 'item.order_detail_id', 'label' => '订购号码'],
                    ['type' => 'field', 'key' => 'logistics.ship_company', 'label' => '运送公司'],
                    ['type' => 'field', 'key' => 'logistics.intl_tracking', 'label' => '运送单号'],
                    ['type' => 'const', 'label' => '订购国家', 'value' => 'JP'],
                ],
            ],
            [
                'id' => 'builtin_wowma', 'name' => 'Wowma出荷表(预置)', 'format' => 'csv', 'builtin' => true,
                'columns' => [
                    ['type' => 'const', 'label' => 'controlType', 'value' => 'U'],
                    ['type' => 'field', 'key' => 'order.platform_order_id', 'label' => 'orderId'],
                    ['type' => 'const', 'label' => 'orderStatus', 'value' => 'Finish_send'],
                    ['type' => 'const', 'label' => 'printStatus', 'value' => 'Y'],
                    ['type' => 'const', 'label' => 'shipStatus', 'value' => 'Y'],
                    ['type' => 'field', 'key' => 'generated.today_ymd', 'label' => 'shippingDate'],
                    ['type' => 'field', 'key' => 'generated.wowma_carrier_code', 'label' => 'shippingCarrier'],
                    ['type' => 'field', 'key' => 'logistics.intl_tracking', 'label' => 'shippingNumber'],
                    ['type' => 'field', 'key' => 'logistics.intl_status', 'label' => '国际运单状态（需删除）'],
                    ['type' => 'field', 'key' => 'order.store', 'label' => '店铺名（需删除）'],
                    ['type' => 'field', 'key' => 'order.order_date', 'label' => '订单时间'],
                ],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function sxColumns(): array
    {
        return [
            ['type' => 'const', 'label' => '日期', 'value' => ''],
            ['type' => 'field', 'key' => 'order.platform_order_id', 'label' => '订单号'],
            ['type' => 'const', 'label' => '派送单号', 'value' => ''],
            ['type' => 'field', 'key' => 'item.weight', 'label' => '重量'],
            ['type' => 'field', 'key' => 'customer.name', 'label' => '收货人'],
            ['type' => 'field', 'key' => 'customer.phone', 'label' => '收货人电话'],
            ['type' => 'field', 'key' => 'customer.address', 'label' => '收货人地址'],
            ['type' => 'field', 'key' => 'customer.zip', 'label' => '收货人邮编'],
            ['type' => 'field', 'key' => 'logistics.domestic_full', 'label' => '国内快递单号'],
            ['type' => 'field', 'key' => 'item.image', 'label' => '图片'],
            ['type' => 'field', 'key' => 'item.quantity', 'label' => '数量'],
            ['type' => 'field', 'key' => 'item.title', 'label' => '品名'],
            ['type' => 'field', 'key' => 'item.chinese_option', 'label' => '颜色'],
            ['type' => 'field', 'key' => 'item.tranship_comment', 'label' => '备注'],
            ['type' => 'field', 'key' => 'item.comment', 'label' => '西阵电商公司备注'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function templatesForTenant(string $tenantKey): array
    {
        return array_merge($this->builtinTemplates(), $this->customTemplates($tenantKey));
    }

    /** @return array<int, array<string, mixed>> */
    private function customTemplates(string $tenantKey): array
    {
        $settings = $this->store->tenantSettings($tenantKey);
        $templates = $settings['export_templates'] ?? [];

        return array_values(array_filter(is_array($templates) ? $templates : [], 'is_array'));
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantKey, string $id): ?array
    {
        foreach ($this->templatesForTenant($tenantKey) as $template) {
            if ((string) ($template['id'] ?? '') === $id) {
                return $template;
            }
        }

        return null;
    }

    public function fromLegacyVariant(string $variant): ?string
    {
        return in_array($variant, self::LEGACY_VARIANTS, true) ? 'builtin_' . $variant : null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{template: ?array<string, mixed>, errors: array<string, string>}
     */
    public function save(string $tenantKey, array $input): array
    {
        $errors = [];
        $id = trim((string) ($input['id'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $format = strtolower(trim((string) ($input['format'] ?? 'xlsx')));
        $columns = is_array($input['columns'] ?? null) ? array_values($input['columns']) : [];

        if ($name === '' || self::textLength($name) > self::MAX_LABEL_LENGTH) {
            $errors['name'] = '模板名称必填且不超过 ' . self::MAX_LABEL_LENGTH . ' 个字符。';
        }
        if (!in_array($format, ['csv', 'xlsx'], true)) {
            $errors['format'] = '导出格式只支持 csv 或 xlsx。';
        }
        if (str_starts_with($id, 'builtin_')) {
            $errors['id'] = '系统预置模板不可修改,请先复制为自定义模板。';
        }
        foreach ($this->validateColumns($columns) as $index => $message) {
            $errors['columns_' . $index] = $message;
        }
        if ($errors !== []) {
            return ['template' => null, 'errors' => $errors];
        }

        $custom = $this->customTemplates($tenantKey);
        $now = time();
        if ($id === '') {
            if (count($custom) >= self::MAX_TEMPLATES) {
                return ['template' => null, 'errors' => ['name' => '自定义模板数量已达上限(' . self::MAX_TEMPLATES . ')。']];
            }
            $template = [
                'id' => 'tpl_' . bin2hex(random_bytes(6)),
                'name' => $name,
                'format' => $format,
                'columns' => $this->normalizeColumns($columns),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $custom[] = $template;
        } else {
            $found = false;
            foreach ($custom as $i => $existing) {
                if ((string) ($existing['id'] ?? '') === $id) {
                    $custom[$i] = array_replace($existing, [
                        'name' => $name,
                        'format' => $format,
                        'columns' => $this->normalizeColumns($columns),
                        'updated_at' => $now,
                    ]);
                    $template = $custom[$i];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return ['template' => null, 'errors' => ['id' => '要更新的模板不存在。']];
            }
        }

        $this->store->saveTenantSettings($tenantKey, ['export_templates' => array_values($custom)]);

        return ['template' => $template, 'errors' => []];
    }

    public function delete(string $tenantKey, string $id): bool
    {
        if (str_starts_with($id, 'builtin_')) {
            return false;
        }

        $custom = $this->customTemplates($tenantKey);
        $next = array_values(array_filter($custom, static fn (array $t): bool => (string) ($t['id'] ?? '') !== $id));
        if (count($next) === count($custom)) {
            return false;
        }

        $this->store->saveTenantSettings($tenantKey, ['export_templates' => $next]);

        return true;
    }

    /**
     * @param array<int, mixed> $columns
     * @return array<int, string>
     */
    public function validateColumns(array $columns): array
    {
        $errors = [];
        if ($columns === []) {
            return ['模板至少需要一列。'];
        }
        if (count($columns) > self::MAX_COLUMNS) {
            return ['列数不能超过 ' . self::MAX_COLUMNS . '。'];
        }

        foreach (array_values($columns) as $index => $column) {
            $label = '第 ' . ($index + 1) . ' 列';
            if (!is_array($column)) {
                $errors[] = $label . ':格式非法。';
                continue;
            }
            $type = (string) ($column['type'] ?? '');
            $display = trim((string) ($column['label'] ?? ''));
            if ($display === '' || self::textLength($display) > self::MAX_LABEL_LENGTH) {
                $errors[] = $label . ':显示名必填且不超过 ' . self::MAX_LABEL_LENGTH . ' 个字符。';
            }
            if ($type === 'field') {
                if (!ExportFieldRegistry::has((string) ($column['key'] ?? ''))) {
                    $errors[] = $label . ':未知字段 ' . (string) ($column['key'] ?? '');
                }
            } elseif ($type === 'const') {
                if (!array_key_exists('value', $column) || !is_scalar($column['value'])) {
                    $errors[] = $label . ':固定值列缺少 value。';
                }
            } elseif ($type === 'raw') {
                $path = (string) ($column['path'] ?? '');
                $allowed = false;
                foreach (self::RAW_PREFIXES as $prefix) {
                    if (str_starts_with($path, $prefix) && strlen($path) > strlen($prefix)) {
                        $allowed = true;
                        break;
                    }
                }
                if (!$allowed) {
                    $errors[] = $label . ':原始字段路径必须以 order./item./customer. 开头。';
                }
            } else {
                $errors[] = $label . ':未知列类型 ' . $type;
            }
        }

        return $errors;
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     * @return array<int, array<string, mixed>>
     */
    private function normalizeColumns(array $columns): array
    {
        return array_values(array_map(static function (array $column): array {
            $type = (string) ($column['type'] ?? 'field');
            $normalized = ['type' => $type, 'label' => trim((string) ($column['label'] ?? ''))];
            if ($type === 'field') {
                $normalized['key'] = (string) ($column['key'] ?? '');
            } elseif ($type === 'const') {
                $normalized['value'] = (string) ($column['value'] ?? '');
            } elseif ($type === 'raw') {
                $normalized['path'] = trim((string) ($column['path'] ?? ''));
            }

            return $normalized;
        }, $columns));
    }

    private static function textLength(string $value): int
    {
        return \function_exists('mb_strlen') ? \mb_strlen($value) : strlen($value);
    }
}
