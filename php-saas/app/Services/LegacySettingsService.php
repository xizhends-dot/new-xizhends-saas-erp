<?php

declare(strict_types=1);

namespace Xizhen\Services;

final class LegacySettingsService
{
    /** @var array<string, array<string, string>>|null */
    private ?array $settings = null;

    public function __construct(private readonly string $file)
    {
    }

    /** @return array<string, array<string, string>> */
    public function all(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        if (!is_file($this->file)) {
            $this->settings = [];
            return $this->settings;
        }

        $parsed = parse_ini_file($this->file, true, INI_SCANNER_RAW);
        $this->settings = is_array($parsed) ? $this->normalize($parsed) : [];
        return $this->settings;
    }

    /** @return array<int, array<string, mixed>> */
    public function groupsForUi(): array
    {
        $settings = $this->all();
        $global = $this->globalSettingsDefaults();

        return [
            [
                'group' => '常规设置',
                'source' => 'old/setting.ini',
                'items' => [
                    $this->item('公司名称', $settings['常规设置']['公司名称'] ?? ''),
                    $this->item('售价预警指数', $settings['订单设置']['售价预警指数'] ?? ''),
                    $this->item('国内快递签收地', $settings['国内快递设置']['国内快递签收地'] ?? ''),
                ],
            ],
            [
                'group' => '性能设置',
                'source' => 'old/setting.ini',
                'items' => [
                    $this->item('默认每页显示', $settings['performance_settings']['default_per_page'] ?? ($settings['性能设置']['默认每页显示'] ?? '')),
                    $this->item('默认查询天数限制', $settings['performance_settings']['default_days_limit'] ?? ($settings['性能设置']['默认查询天数限制'] ?? '')),
                    $this->item('启用查询缓存', $settings['performance_settings']['enable_query_cache'] ?? ($settings['性能设置']['启用查询缓存'] ?? '')),
                    $this->item('缓存过期时间秒', $settings['performance_settings']['cache_expire_seconds'] ?? ($settings['性能设置']['缓存过期时间秒'] ?? '')),
                    $this->item('大数据量警告阈值', $settings['performance_settings']['large_data_warning_threshold'] ?? ($settings['性能设置']['大数据量警告阈值'] ?? '')),
                ],
            ],
            [
                'group' => '利润计算设置',
                'source' => 'old/setting.ini',
                'items' => [
                    $this->item('汇率', $settings['利润计算设置']['汇率'] ?? ''),
                    $this->item('汇率模式', $settings['利润计算设置']['汇率模式'] ?? ''),
                    $this->item('固定汇率', $settings['利润计算设置']['固定汇率'] ?? ''),
                    $this->item('Yahoo平台扣点', $settings['利润计算设置']['Yahoo平台扣点'] ?? ''),
                    $this->item('Rakuten平台扣点', $settings['利润计算设置']['Rakuten平台扣点'] ?? ''),
                    $this->item('Wowma平台扣点', $settings['利润计算设置']['Wowma平台扣点'] ?? ''),
                    $this->item('Mercari平台扣点', $settings['利润计算设置']['Mercari平台扣点'] ?? ''),
                ],
            ],
            [
                'group' => '物流设置',
                'source' => 'old/setting.ini',
                'items' => [
                    $this->item('雅虎物流编号映射', $this->countLines($global['logistics_mapping']['yahoo'] ?? '') . ' 条'),
                    $this->item('乐天物流编号映射', $this->countLines($global['logistics_mapping']['rakuten'] ?? '') . ' 条'),
                    $this->item('Wowma物流编号映射', $this->countLines($global['logistics_mapping']['wowma'] ?? '') . ' 条'),
                    $this->item('日本快递公司映射', $this->countLines($global['logistics_mapping']['jp_carrier'] ?? '') . ' 条'),
                    $this->item('物流状态查询映射', $this->countLines($global['logistics_mapping']['tracking_query'] ?? '') . ' 条'),
                ],
            ],
            [
                'group' => '隐藏店铺',
                'source' => 'old/setting.ini',
                'items' => [
                    $this->item('隐藏店铺数量', (string) count($settings['隐藏店铺设置'] ?? [])),
                    $this->item('迁移策略', '进入 stores.is_hidden 与员工店铺范围'),
                ],
            ],
            [
                'group' => '接口与安全',
                'source' => 'old/setting.ini',
                'items' => [
                    $this->item('两步验证密码', $this->masked($settings['安全设置']['两步验证密码'] ?? '')),
                    $this->item('ShowAPI AppID', $global['showapi']['app_id'] ?? ''),
                    $this->item('ShowAPI Sign', $this->masked((string) ($global['showapi']['sign'] ?? ''))),
                    $this->item('1688配置文件', $settings['1688接口配置']['配置文件'] ?? ''),
                    $this->item('轮循代理', $this->masked((string) ($global['proxy']['rotation_proxy'] ?? ''))),
                ],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function globalGroupsForUi(): array
    {
        $global = $this->globalSettingsDefaults();

        return [
            [
                'group' => '物流编号对照表',
                'source' => 'old/setting.ini',
                'items' => [
                    $this->item('雅虎物流编号映射', $this->countLines($global['logistics_mapping']['yahoo'] ?? '') . ' 条'),
                    $this->item('乐天物流编号映射', $this->countLines($global['logistics_mapping']['rakuten'] ?? '') . ' 条'),
                    $this->item('Wowma物流编号映射', $this->countLines($global['logistics_mapping']['wowma'] ?? '') . ' 条'),
                    $this->item('日本快递公司映射', $this->countLines($global['logistics_mapping']['jp_carrier'] ?? '') . ' 条'),
                    $this->item('物流状态查询映射', $this->countLines($global['logistics_mapping']['tracking_query'] ?? '') . ' 条'),
                ],
            ],
            [
                'group' => 'ShowAPI 配置',
                'source' => 'old/setting.ini',
                'items' => [
                    $this->item('AppID', $global['showapi']['app_id'] ?? ''),
                    $this->item('Sign', $this->masked((string) ($global['showapi']['sign'] ?? ''))),
                ],
            ],
            [
                'group' => '轮循代理',
                'source' => 'old/setting.ini',
                'items' => [
                    $this->item('代理地址', $this->masked((string) ($global['proxy']['rotation_proxy'] ?? ''))),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function globalSettingsDefaults(): array
    {
        $settings = $this->all();
        $logistics = $this->quotedSectionValues('物流编号对照表', [
            '雅虎',
            '乐天',
            'Wowma',
            '日本快递公司',
            '物流状态查询',
        ]);
        return [
            'logistics_mapping' => [
                'yahoo' => $logistics['雅虎'] ?? '',
                'rakuten' => $logistics['乐天'] ?? '',
                'wowma' => $logistics['Wowma'] ?? '',
                'jp_carrier' => $logistics['日本快递公司'] ?? '',
                'tracking_query' => $logistics['物流状态查询'] ?? '',
            ],
            'showapi' => [
                'app_id' => '',
                'sign' => '',
                'enabled' => false,
            ],
            'proxy' => [
                'rotation_proxy' => '',
                'enabled' => false,
            ],
            'updated_at' => '',
        ];
    }

    /** @return array<string, mixed> */
    public function tenantSettingsDefaults(): array
    {
        return [
            'orders' => [
                'price_warning_index' => 0,
                'default_page_size' => 200,
                'default_query_days' => 30,
            ],
            'profit' => [
                'exchange_rate' => 0.046,
                'exchange_rate_mode' => 'fixed',
                'fixed_exchange_rate' => 0.046,
                'default_intl_fee' => 820,
                'platform_deductions' => [
                    'y' => 70,
                    'r' => 70,
                    'w' => 70,
                    'm' => 70,
                    'q' => 70,
                    'yp' => 70,
                ],
            ],
            'logistics' => [
                'domestic_receive_places' => '',
            ],
            'api_1688' => [
                'config_file' => '',
                'config_content' => '',
                'enabled' => false,
            ],
        ];
    }

    public function legacyFileContent(string $relativePath): string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath));
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return '';
        }

        $base = dirname($this->file);
        $absolutePath = $base . '/' . ltrim($relativePath, '/');
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return '';
        }

        return trim((string) file_get_contents($absolutePath));
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $settings = $this->all();
        $global = $this->globalSettingsDefaults();

        return [
            'company_name' => $settings['常规设置']['公司名称'] ?? '',
            'hidden_store_count' => count($settings['隐藏店铺设置'] ?? []),
            'domestic_receive_places' => array_filter(array_map('trim', explode(',', $settings['国内快递设置']['国内快递签收地'] ?? ''))),
            'profit_exchange_rate' => $settings['利润计算设置']['汇率'] ?? '',
            'default_per_page' => $settings['performance_settings']['default_per_page'] ?? ($settings['性能设置']['默认每页显示'] ?? ''),
            'has_showapi' => ($global['showapi']['app_id'] ?? '') !== '',
            'has_1688_config' => ($settings['1688接口配置']['配置文件'] ?? '') !== '',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function migrationPlan(): array
    {
        return [
            [
                'group' => '可直接迁入',
                'source' => 'old/setting.ini',
                'items' => [
                    $this->item('基础资料', '公司名称、售价预警指数、国内快递签收地'),
                    $this->item('性能参数', '默认分页、查询天数、缓存开关、缓存 TTL、大数据阈值'),
                    $this->item('利润参数', '汇率、汇率模式、固定汇率、默认运费、平台扣点默认值'),
                    $this->item('物流规则', '平台运单编号映射、承运商映射、物流查询映射'),
                    $this->item('店铺范围', '隐藏店铺列表迁入 stores.is_hidden 与员工店铺范围'),
                ],
            ],
            [
                'group' => '必须加密或只占位',
                'source' => 'old/config.php / plugins',
                'items' => [
                    $this->item('数据库连接', '只走环境变量或密钥管理，不写入 app.json'),
                    $this->item('两步验证密码', '不迁明文；上线前重置或迁为哈希'),
                    $this->item('ShowAPI Sign', '设置页只显示脱敏摘要，保存时加密'),
                    $this->item('1688 / OB / 平台 API', 'AppKey、Secret、Token 全部加密保存'),
                    $this->item('轮循代理', '只显示协议和主机摘要，密码不展示'),
                ],
            ],
            [
                'group' => '新系统落点',
                'source' => 'php-saas',
                'items' => [
                    $this->item('租户级配置', 'tenant_settings / JSON settings.tenant.{key}'),
                    $this->item('店铺级配置', 'stores 与平台授权表保存店铺范围、API 状态'),
                    $this->item('订单配置', '订单状态字典、分页、查询范围、归档策略'),
                    $this->item('任务配置', 'jobs 调度中心保存频率、参数、执行日志'),
                    $this->item('审计日志', '所有保存、批量、导入、API 调用写 order_logs'),
                ],
            ],
        ];
    }

    /** @return array<int, array<string, string>> */
    public function hiddenStoresPreview(int $limit = 18): array
    {
        $stores = $this->all()['隐藏店铺设置'] ?? [];
        $rows = [];
        foreach ($stores as $store => $note) {
            $rows[] = ['store' => (string) $store, 'note' => (string) $note];
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $parsed
     * @return array<string, array<string, string>>
     */
    private function normalize(array $parsed): array
    {
        $normalized = [];
        foreach ($parsed as $section => $values) {
            if (!is_array($values)) {
                continue;
            }
            $normalized[(string) $section] = [];
            foreach ($values as $key => $value) {
                $normalized[(string) $section][(string) $key] = trim((string) $value, "\" \t\n\r\0\x0B");
            }
        }

        return $normalized;
    }

    /** @return array<string, string> */
    private function item(string $name, string $value): array
    {
        return ['name' => $name, 'value' => $value !== '' ? $value : '未配置'];
    }

    private function masked(string $value): string
    {
        if ($value === '') {
            return '未配置';
        }

        return substr($value, 0, 2) . str_repeat('*', max(4, min(10, strlen($value) - 2)));
    }

    private function countLines(string $value): int
    {
        $lines = array_filter(array_map('trim', preg_split('/\R/', $value) ?: []));
        return count($lines);
    }

    private function floatValue(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * @param array<int, string> $keys
     * @return array<string, string>
     */
    private function quotedSectionValues(string $section, array $keys): array
    {
        $values = array_fill_keys($keys, '');
        if (!is_file($this->file)) {
            return $values;
        }

        $lines = file($this->file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return $values;
        }

        $wanted = array_fill_keys($keys, true);
        $inSection = false;
        $activeKey = null;
        $buffer = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($activeKey === null && preg_match('/^\[(.+)]$/u', $trimmed, $match) === 1) {
                $inSection = $match[1] === $section;
                continue;
            }
            if (!$inSection) {
                continue;
            }

            if ($activeKey !== null) {
                $piece = $line;
                if ($this->endsQuotedValue($piece)) {
                    $piece = preg_replace('/"\s*$/u', '', $piece) ?? $piece;
                    $buffer[] = $piece;
                    $values[$activeKey] = trim(implode("\n", $buffer));
                    $activeKey = null;
                    $buffer = [];
                } else {
                    $buffer[] = $piece;
                }
                continue;
            }

            if ($trimmed === '' || str_starts_with($trimmed, ';') || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (preg_match('/^([^=]+?)\s*=\s*(.*)$/u', $line, $match) !== 1) {
                continue;
            }

            $key = trim($match[1]);
            if (!isset($wanted[$key])) {
                continue;
            }

            $value = trim($match[2]);
            if (str_starts_with($value, '"')) {
                $value = substr($value, 1);
                if ($this->endsQuotedValue($value)) {
                    $value = preg_replace('/"\s*$/u', '', $value) ?? $value;
                    $values[$key] = trim($value);
                } else {
                    $activeKey = $key;
                    $buffer = [$value];
                }
                continue;
            }

            $values[$key] = trim($value, "\" \t\n\r\0\x0B");
        }

        if ($activeKey !== null && $buffer !== []) {
            $values[$activeKey] = trim(implode("\n", $buffer));
        }

        return $values;
    }

    private function endsQuotedValue(string $value): bool
    {
        return preg_match('/(?<!\\\\)"\s*$/u', $value) === 1;
    }
}
