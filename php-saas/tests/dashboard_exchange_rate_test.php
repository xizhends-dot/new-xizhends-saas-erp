<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $basePath);
}
require $basePath . '/vendor/autoload.php';
require $basePath . '/app/Core/helpers.php';

use Xizhen\Core\View;
use Xizhen\Services\ExchangeRateService;

$failures = [];
$assertSame = static function (string $label, mixed $expected, mixed $actual) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
};
$assertContains = static function (string $label, string $needle, string $haystack) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $label . ': missing ' . var_export($needle, true);
    }
};
$assertNotContains = static function (string $label, string $needle, string $haystack) use (&$failures): void {
    if (str_contains($haystack, $needle)) {
        $failures[] = $label . ': unexpected ' . var_export($needle, true);
    }
};

$rateFile = sys_get_temp_dir() . '/xizhen-fawaz-rate-' . bin2hex(random_bytes(6)) . '.json';
file_put_contents($rateFile, json_encode(['jpy' => ['cny' => 0.04765432]], JSON_UNESCAPED_UNICODE));

try {
    $rate = (new ExchangeRateService($rateFile))->jpyToCny();
    $assertSame('Fawaz source', 'FawazCurrencyAPI', $rate['source']);
    $assertSame('Fawaz success', true, $rate['success']);
    $assertSame('Fawaz rate parsed', 0.04765432, $rate['rate']);

    $fallback = (new ExchangeRateService($rateFile . '.missing'))->jpyToCny();
    $assertSame('fallback source remains Fawaz', 'FawazCurrencyAPI', $fallback['source']);
    $assertSame('fallback success false', false, $fallback['success']);
    $assertSame('fallback default rate', 0.048, $fallback['rate']);

    $view = new View($basePath . '/app/Views');
    ob_start();
    $view->render('tenant/dashboard', [
        'title' => '首页仪表盘',
        'tenantKey' => 'erp',
        'tenant' => ['company_name' => '测试租户'],
        'menu' => [],
        'tenantFeatures' => [],
        'stats' => [
            'pending_orders' => 0,
            'purchase_items' => 0,
            'jp_stock_items' => 0,
            'pending_source_items' => 0,
            'today_amount' => 0,
            'recent_orders' => [],
        ],
        'realtimeRate' => $rate,
        'announcements' => [],
        'tenantNotices' => [],
        'groups' => [],
        'active' => 'dashboard',
        'currentUser' => ['username' => 'admin-erp', 'name' => '管理员'],
    ]);
    $html = (string) ob_get_clean();

    $assertContains('dashboard shows realtime stat label', '实时汇率', $html);
    $assertContains('dashboard shows realtime panel source', 'FawazCurrencyAPI', $html);
    $assertContains('dashboard shows realtime rate', '0.047654', $html);
    $assertContains('dashboard marks realtime card for browser refresh', 'data-realtime-rate-card', $html);
    $assertNotContains('dashboard hides pending source stat card', '待判定货源', $html);
    $assertNotContains('dashboard hides shipping defaults', '默认运费', $html);
    $assertNotContains('dashboard hides deduction defaults', '默认扣点', $html);
    $assertNotContains('dashboard hides profit setting link', '调整利润参数', $html);
    $assertNotContains('dashboard does not show fixed rate as realtime', '0.0999', $html);

    $js = (string) file_get_contents($basePath . '/public/assets/app.js');
    $assertContains('dashboard browser refresh uses Fawaz jsdelivr', 'cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/jpy.json', $js);
    $assertContains('dashboard browser refresh has Cloudflare fallback', 'latest.currency-api.pages.dev/v1/currencies/jpy.json', $js);
} finally {
    @unlink($rateFile);
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Dashboard exchange rate test passed." . PHP_EOL;
