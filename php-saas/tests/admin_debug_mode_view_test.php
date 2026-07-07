<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $basePath);
}
require $basePath . '/vendor/autoload.php';
require $basePath . '/app/Core/helpers.php';

$failures = [];
$assert = static function (string $label, bool $ok) use (&$failures): void {
    if (!$ok) {
        $failures[] = $label;
    }
};

/**
 * @param array<string, mixed> $settings
 */
function render_admin_settings_for_debug_mode_test(string $basePath, array $settings): string
{
    $legacyGroups = [[
        'group' => '物流编号对照表',
        'source' => 'old/setting.ini',
        'items' => [['name' => '雅虎物流编号映射', 'value' => '1 条']],
    ]];
    $message = '';
    $saved = '';
    ob_start();
    require $basePath . '/app/Views/admin/settings.php';
    return (string) ob_get_clean();
}

$enabledHtml = render_admin_settings_for_debug_mode_test($basePath, [
    'logistics_mapping' => [],
    'showapi' => [],
    'proxy' => [],
    'debug' => ['enabled' => true],
]);
$disabledHtml = render_admin_settings_for_debug_mode_test($basePath, [
    'logistics_mapping' => [],
    'showapi' => [],
    'proxy' => [],
    'debug' => ['enabled' => false],
]);

$assert('开启调试模式时显示状态', str_contains($enabledHtml, '调试模式') && str_contains($enabledHtml, '开启'));
$assert('开启调试模式时显示迁移参考参数', str_contains($enabledHtml, 'old/setting.ini'));
$assert('关闭调试模式时显示关闭状态', str_contains($disabledHtml, '调试模式') && str_contains($disabledHtml, '关闭'));
$assert('关闭调试模式时隐藏迁移参考参数', !str_contains($disabledHtml, 'old/setting.ini'));

if ($failures !== []) {
    fwrite(STDERR, "Admin debug mode view test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Admin debug mode view test OK\n";
