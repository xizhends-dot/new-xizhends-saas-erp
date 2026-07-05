<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\Permission;
use Xizhen\Core\StoreInterface;

final class OrderPageConfigRegistry
{
    private const DISPLAY_GROUPS = ['primary', 'more', 'hidden'];

    public function __construct(private readonly ?StoreInterface $store = null, private readonly string $tenantKey = '')
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function filterFieldsFor(string $platform): array
    {
        $platform = $this->normalizePlatform($platform);
        $fields = [
            ['key' => 'order_no', 'label' => '订单号', 'type' => 'text', 'section' => 'basic', 'views' => ['platform', 'purchase', 'jp']],
            ['key' => 'tabaono', 'label' => '1688订单号', 'type' => 'text', 'section' => 'basic', 'views' => ['platform', 'purchase']],
            ['key' => 'customer_name', 'label' => '收件人姓名', 'type' => 'text', 'section' => 'basic', 'views' => ['platform']],
            ['key' => 'phone', 'label' => '客人电话', 'type' => 'text', 'section' => 'basic', 'views' => ['platform']],
            ['key' => 'mail', 'label' => '客人邮箱', 'type' => 'text', 'section' => 'basic', 'views' => ['platform']],
            ['key' => 'cn_ship_no', 'label' => '国内发货单号', 'type' => 'text', 'section' => 'basic', 'views' => ['platform', 'purchase']],
            ['key' => 'intl_ship_no', 'label' => '国际发货单号', 'type' => 'text', 'section' => 'basic', 'views' => ['platform', 'purchase', 'jp']],
            ['key' => 'status', 'label' => '采购状态', 'type' => 'select', 'optionsKey' => 'statusOptions', 'section' => 'basic', 'views' => ['platform', 'purchase', 'jp']],
            ['key' => 'store', 'label' => '店铺', 'type' => 'select', 'optionsKey' => 'storeNames', 'section' => 'basic', 'views' => ['platform', 'jp']],
            ['key' => 'source', 'label' => '货源地', 'type' => 'select', 'section' => 'basic', 'views' => ['platform'], 'options' => [
                ['value' => 'all', 'label' => '全部货源地'],
                ['value' => 'jp_stock', 'label' => '日本仓'],
                ['value' => 'cn_purchase', 'label' => '国内采购'],
                ['value' => 'pending', 'label' => '待定'],
            ]],
            ['key' => 'receipt_city', 'label' => '国内签收地', 'type' => 'text', 'section' => 'basic', 'views' => ['platform', 'purchase']],
            ['key' => 'page_size', 'label' => '每页显示', 'type' => 'select', 'section' => 'basic', 'views' => ['platform', 'purchase', 'jp'], 'options' => [
                ['value' => '100', 'label' => '100'],
                ['value' => '200', 'label' => '200'],
                ['value' => '500', 'label' => '500'],
                ['value' => '1000', 'label' => '1000'],
            ]],
            ['key' => 'item_id', 'label' => 'ItemId / 商品名', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform', 'purchase', 'jp']],
            ['key' => 'item_management_id', 'label' => '商品管理ID', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform', 'purchase']],
            ['key' => 'order_detail_id', 'label' => '订单明细ID', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform']],
            ['key' => 'lot_number', 'label' => 'lotNumber', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform', 'purchase']],
            ['key' => 'lot_number_empty', 'label' => 'lotNumber为空', 'type' => 'checkbox', 'section' => 'advanced', 'views' => ['platform']],
            ['key' => 'kana', 'label' => '片假名', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform']],
            ['key' => 'product_name', 'label' => '商品标题', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform', 'purchase']],
            ['key' => 'pay_method', 'label' => '支付方式', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform']],
            ['key' => 'ship_method', 'label' => '运送方式', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform']],
            ['key' => 'material', 'label' => '材质', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform']],
            ['key' => 'location', 'label' => '仓位', 'type' => 'text', 'section' => 'advanced', 'views' => ['jp']],
            ['key' => 'carrier', 'label' => '物流公司', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform', 'purchase', 'jp']],
            ['key' => 'buyer', 'label' => '采购人 / 发货员', 'type' => 'select', 'section' => 'advanced', 'views' => ['purchase', 'jp'], 'options' => [
                ['value' => '王五', 'label' => '王五'],
                ['value' => '李四', 'label' => '李四'],
                ['value' => '赵六', 'label' => '赵六'],
            ]],
            ['key' => 'purchase_link', 'label' => '采购链接', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform', 'purchase']],
            ['key' => 'comment', 'label' => '订单备注', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform', 'purchase']],
            ['key' => 'purchase_comment', 'label' => '采购备注', 'type' => 'text', 'section' => 'advanced', 'views' => ['platform']],
            ['key' => 'intl_ship_empty', 'label' => '国际运单状态', 'type' => 'select', 'section' => 'advanced', 'views' => ['platform'], 'options' => [
                ['value' => 'no', 'label' => '未出国际单号'],
                ['value' => 'yes', 'label' => '已有国际单号'],
            ]],
            ['key' => 'frb_push', 'label' => '飞兔推送', 'type' => 'select', 'section' => 'advanced', 'views' => ['platform'], 'options' => [
                ['value' => 'no', 'label' => '未推送'],
                ['value' => 'yes', 'label' => '已推送'],
            ]],
            ['key' => 'date_range', 'label' => '日期范围', 'type' => 'date_range', 'from' => 'date_from', 'to' => 'date_to', 'section' => 'advanced', 'views' => ['platform', 'purchase', 'jp']],
            ['key' => 'review_invited', 'label' => '邀评状态', 'type' => 'select', 'section' => 'advanced', 'views' => ['platform'], 'options' => [
                ['value' => '1', 'label' => '已邀评'],
                ['value' => '0', 'label' => '未邀评'],
            ]],
            ['key' => 'reviewed', 'label' => '评价状态', 'type' => 'select', 'section' => 'advanced', 'views' => ['platform'], 'options' => [
                ['value' => '1', 'label' => '已评价'],
                ['value' => '0', 'label' => '未评价'],
            ]],
            ['key' => 'late_ship', 'label' => '超时发货', 'type' => 'checkbox', 'section' => 'flags', 'views' => ['platform', 'purchase']],
            ['key' => 'in_delivery', 'label' => '配達中', 'type' => 'checkbox', 'section' => 'flags', 'views' => ['platform', 'jp']],
            ['key' => 'delivered', 'label' => '配達完了', 'type' => 'checkbox', 'section' => 'flags', 'views' => ['platform', 'jp']],
        ];

        $fields = array_values(array_filter($fields, fn (array $field): bool => $this->fieldVisibleForPlatform((string) $field['key'], $platform)));
        foreach ($fields as &$field) {
            $key = (string) ($field['key'] ?? '');
            $field['name'] = $this->fieldNameFor($key, $platform);
            if ($key === 'date_range') {
                $field['from'] = $this->dateFromNameFor($platform);
                $field['to'] = $this->dateToNameFor($platform);
            }
            if ($key === 'ship_method' && $platform === 'y') {
                $field['label'] = '运送方式';
                $field['name'] = 'PayStatus';
            }
        }
        unset($field);

        return $fields;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function normalizeFilterInput(string $platform, array $input): array
    {
        $platform = $this->normalizePlatform($platform);
        $result = $input;
        foreach ($this->filterFieldsFor($platform) as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '' || $key === 'date_range') {
                continue;
            }
            $name = (string) ($field['name'] ?? $key);
            if ($name !== $key && array_key_exists($name, $input) && !array_key_exists($key, $result)) {
                $result[$key] = $input[$name];
            }
        }

        return $result;
    }

    public function fieldNameFor(string $key, string $platform): string
    {
        $platform = $this->normalizePlatform($platform);
        return match ($key) {
            'order_no' => in_array($platform, ['r', 'y'], true) ? 'orderId' : 'ziid',
            'item_id' => match ($platform) {
                'r', 'y' => 'ItemId',
                'w', 'm', 'q' => 'itemManagementId',
                default => 'item_id',
            },
            'ship_method' => match ($platform) {
                'r' => 'yunshu',
                'y' => 'PayStatus',
                'w', 'm', 'q' => 'deliveryName',
                default => 'ship_method',
            },
            'kana' => $platform === 'r' ? 'pianjiaming' : ($platform === 'yp' ? 'kana' : 'senderKana'),
            'purchase_link' => $platform === 'y' ? 'caigoulink' : 'purchase_link',
            'product_name' => $platform === 'yp' ? 'product_title' : 'product_name',
            'pay_method' => $platform === 'w' ? 'settlementName' : 'pay_method',
            'lot_number' => 'lotnumber',
            'lot_number_empty' => 'lot_number_empty',
            'intl_ship_empty' => $platform === 'q' ? 'intl_ship_empty' : 'kong',
            'frb_push' => 'frb_push',
            'review_invited' => 'invite_review',
            'in_delivery' => 'haitatsuchuu',
            'delivered' => 'haitatsukanryo',
            default => $key,
        };
    }

    public function orderDateFieldFor(string $platform): string
    {
        $platform = $this->normalizePlatform($platform);
        return in_array($platform, ['r', 'y'], true) ? 'OrderTime' : 'orderDate';
    }

    private function dateFromNameFor(string $platform): string
    {
        $field = $this->orderDateFieldFor($platform);

        return $field === 'OrderTime' ? 'OrderTime' : 'orderDate';
    }

    private function dateToNameFor(string $platform): string
    {
        $field = $this->orderDateFieldFor($platform);

        return $field === 'OrderTime' ? 'OrderTime2' : 'orderDate2';
    }

    private function normalizePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));

        return $platform !== '' ? $platform : 'r';
    }

    private function fieldVisibleForPlatform(string $key, string $platform): bool
    {
        return match ($key) {
            'lot_number' => in_array($platform, ['m', 'yp'], true),
            'lot_number_empty' => $platform === 'yp',
            'review_invited', 'reviewed' => in_array($platform, ['r', 'y'], true),
            'purchase_link' => $platform === 'y',
            'product_name' => $platform === 'yp',
            'pay_method' => $platform === 'w',
            'in_delivery', 'delivered' => $platform === 'r',
            'intl_ship_empty', 'frb_push' => $platform !== 'q',
            'item_id', 'ship_method', 'kana' => $platform !== 'yp',
            default => true,
        };
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function exportToolsFor(string $platform, array $user): array
    {
        $platform = $this->normalizePlatform($platform);
        $tools = $this->applyConfiguredDisplay($this->builtinToolsFor($platform, $user));
        foreach ($this->templateToolsFor($platform, $user) as $tool) {
            $tools[] = $tool;
        }

        return $tools;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function builtinToolsForConfig(): array
    {
        return array_values(array_filter(
            $this->builtinToolsFor('yp', ['role' => '公司管理员', 'is_company_admin' => true]),
            static fn (array $tool): bool => !in_array((string) ($tool['key'] ?? ''), ['sync_orders', 'mercari_new_import_todo'], true)
        ));
    }

    /**
     * @return array<string, string>
     */
    public function displayConfig(): array
    {
        if ($this->store === null || $this->tenantKey === '') {
            return [];
        }

        $settings = $this->store->tenantSettings($this->tenantKey);
        $raw = $settings['order_export_tools'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $config = [];
        foreach ($raw as $key => $value) {
            $key = trim((string) $key);
            $value = trim((string) $value);
            if ($key !== '' && in_array($value, self::DISPLAY_GROUPS, true)) {
                $config[$key] = $value;
            }
        }

        return $config;
    }

    public function configuredDisplayFor(string $key, string $default): string
    {
        $config = $this->displayConfig();
        $default = in_array($default, self::DISPLAY_GROUPS, true) ? $default : 'hidden';
        $value = (string) ($config[$key] ?? $default);

        return in_array($value, self::DISPLAY_GROUPS, true) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<int, string> $allowedKeys
     * @return array<string, string>
     */
    public static function normalizeDisplayConfig(array $input, array $allowedKeys): array
    {
        $allowed = array_flip(array_values(array_filter(array_map('strval', $allowedKeys), static fn (string $key): bool => $key !== '')));
        $config = [];
        foreach ($input as $key => $value) {
            $key = trim((string) $key);
            $value = trim((string) $value);
            if (isset($allowed[$key]) && in_array($value, self::DISPLAY_GROUPS, true)) {
                $config[$key] = $value;
            }
        }

        return $config;
    }

    public static function templateToolKey(string $templateId): string
    {
        return 'tpl_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $templateId);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    private function builtinToolsFor(string $platform, array $user): array
    {
        $platform = $this->normalizePlatform($platform);
        $role = Permission::normalizeRole($user['role'] ?? '');
        $username = strtolower(trim((string) ($user['username'] ?? '')));
        $isCompanyAdmin = (bool) ($user['is_company_admin'] ?? false) || $role === '公司管理员';
        $canSyncOrders = Permission::hasAny($user, ['导入导出', '订单编辑']);
        $canImportExport = Permission::has($user, '导入导出');
        $canPlatformImportExport = $canImportExport;
        $canPurchaseImport = $canImportExport && $platform === 'r' && Permission::has($user, '采购导入导出');
        $isFinanceIdentity = in_array($username, ['caiwu', 'xizhends'], true);
        $canFinanceExport = $canImportExport && (
            $isCompanyAdmin
            || Permission::has($user, '财务导出')
            || $isFinanceIdentity
        );
        $canCustomersExport = $canImportExport && ($isCompanyAdmin || ($isFinanceIdentity && Permission::has($user, '客户资料')));
        $canTemplate = $canImportExport && ($isCompanyAdmin || Permission::has($user, '公司设置'));

        return [
            [
                'key' => 'sync_orders',
                'label' => '同步订单',
                'action' => '/orders/platform/sync',
                'method' => 'post',
                'group' => 'sync',
                'needsDateRange' => false,
                'visibleWhen' => $canSyncOrders,
            ],
            [
                'key' => 'platform_orders_import',
                'label' => '平台订单导入',
                'action' => '/import-export',
                'method' => 'get',
                'job' => 'platform_orders_import',
                'group' => 'primary',
                'needsDateRange' => false,
                'visibleWhen' => $canPlatformImportExport,
            ],
            [
                'key' => 'purchase_import',
                'label' => '采购单导入',
                'action' => '/import-export',
                'method' => 'get',
                'job' => 'purchase_import',
                'group' => 'primary',
                'needsDateRange' => false,
                'visibleWhen' => $canPurchaseImport,
            ],
            [
                'key' => 'shipping_import',
                'label' => '国际运单导入',
                'action' => '/import-export',
                'method' => 'get',
                'job' => 'shipping_import',
                'group' => 'primary',
                'needsDateRange' => false,
                'visibleWhen' => $canPlatformImportExport,
            ],
            [
                'key' => 'shipment_export',
                'label' => '发货表导出',
                'action' => '/orders/export',
                'method' => 'post',
                'type' => 'shipment',
                'group' => 'primary',
                'needsDateRange' => true,
                'visibleWhen' => $canPlatformImportExport,
            ],
            [
                'key' => 'platform_export',
                'label' => '平台订单表导出',
                'action' => '/orders/export',
                'method' => 'post',
                'type' => 'platform',
                'group' => 'primary',
                'needsDateRange' => true,
                'visibleWhen' => $canPlatformImportExport,
            ],
            [
                'key' => 'yahoo_auction_qoo10_shipment_export',
                'label' => '雅拍出荷处理表',
                'action' => '/import-export/platform-special/export',
                'method' => 'get',
                'group' => 'more',
                'needsDateRange' => true,
                'params' => ['template_id' => 'builtin_qoo10'],
                'visibleWhen' => $platform === 'yp' && $canPlatformImportExport,
            ],
            [
                'key' => 'finance_export',
                'label' => '财务表导出',
                'action' => '/import-export/finance-placeholder/export',
                'method' => 'get',
                'group' => 'more',
                'needsDateRange' => true,
                'visibleWhen' => $canFinanceExport,
            ],
            [
                'key' => 'customers_export',
                'label' => '客户资料导出',
                'action' => '/import-export/customers/export',
                'method' => 'get',
                'group' => 'more',
                'needsDateRange' => true,
                'visibleWhen' => $canCustomersExport,
            ],
            [
                'key' => 'delivery_notice_export',
                'label' => '发货通知表导出',
                'action' => '/orders/export',
                'method' => 'post',
                'type' => 'delivery_notice',
                'group' => 'more',
                'needsDateRange' => true,
                'visibleWhen' => $canPlatformImportExport,
            ],
            [
                'key' => 'xizhen_delivery_export',
                'label' => '西阵发货表导出',
                'action' => '/orders/xizhen-delivery/export',
                'method' => 'post',
                'group' => 'more',
                'needsDateRange' => true,
                'visibleWhen' => $canPlatformImportExport,
            ],
            [
                'key' => 'export_template',
                'label' => '发货单导出模板',
                'action' => '/import-export/export-templates/edit',
                'method' => 'get',
                'group' => 'primary',
                'needsDateRange' => false,
                'visibleWhen' => $canTemplate,
            ],
            [
                'key' => 'mercari_new_import_todo',
                'label' => 'Mercari新版导入',
                'action' => '',
                'method' => 'todo',
                'group' => 'primary',
                'needsDateRange' => false,
                'visibleWhen' => false,
                'todo' => $platform === 'm',
                'note' => '新系统暂无 orderinsert_new 对应动作，未生成入口。',
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $tools
     * @return array<int, array<string, mixed>>
     */
    private function applyConfiguredDisplay(array $tools): array
    {
        $result = [];
        foreach ($tools as $tool) {
            $key = (string) ($tool['key'] ?? '');
            if ($key === '' || $key === 'sync_orders') {
                $result[] = $tool;
                continue;
            }

            $display = $this->configuredDisplayFor($key, (string) ($tool['group'] ?? 'primary'));
            if ($display === 'hidden') {
                continue;
            }
            $tool['group'] = $display;
            $result[] = $tool;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    private function templateToolsFor(string $platform, array $user): array
    {
        if ($this->store === null || $this->tenantKey === '' || !Permission::has($user, '导入导出')) {
            return [];
        }

        $platform = $this->normalizePlatform($platform);
        $service = new ExportTemplateService($this->store);
        $tools = [];
        foreach ($service->templatesForTenant($this->tenantKey) as $template) {
            $templateId = trim((string) ($template['id'] ?? ''));
            if ($templateId === '') {
                continue;
            }
            $platforms = array_values(array_filter(array_map('strval', is_array($template['platforms'] ?? null) ? $template['platforms'] : [])));
            if ($platforms !== [] && !in_array($platform, $platforms, true)) {
                continue;
            }
            $key = self::templateToolKey($templateId);
            $display = $this->configuredDisplayFor($key, 'hidden');
            if (!in_array($display, ['primary', 'more'], true)) {
                continue;
            }
            $tools[] = [
                'key' => $key,
                'label' => (string) (($template['name'] ?? '') ?: $templateId),
                'action' => '/import-export/platform-special/export',
                'method' => 'get',
                'group' => $display,
                'needsDateRange' => true,
                'params' => ['template_id' => $templateId],
                'visibleWhen' => true,
                'template_id' => $templateId,
            ];
        }

        return $tools;
    }
}
