<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
require $basePath . '/app/Core/StoreInterface.php';
require $basePath . '/app/Core/Permission.php';
require $basePath . '/app/Core/TenantFeature.php';
require $basePath . '/app/Core/JsonStore.php';
require $basePath . '/app/Services/ExportFieldRegistry.php';
require $basePath . '/app/Services/ExportTemplateService.php';
require $basePath . '/app/Services/PlatformExportService.php';

use Xizhen\Core\JsonStore;
use Xizhen\Services\ExportTemplateService;
use Xizhen\Services\PlatformExportService;

$dataFile = sys_get_temp_dir() . '/export_render_test_' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($dataFile, json_encode(['tenants' => [['key' => 't1', 'name' => 'T1', 'status' => 'active']], 'settings' => ['tenant' => []]], JSON_UNESCAPED_UNICODE));
$templates = new ExportTemplateService(new JsonStore($dataFile));
$engine = new PlatformExportService();

$orders = [[
    'platform' => 'y',
    'platform_order_id' => 'Y-2001',
    'store' => '京都店',
    'order_date' => '2026-07-01 09:30:00',
    'ship_method' => 'ヤマト',
    'customer' => ['name' => '铃木', 'phone' => '9011112222', 'zip' => '6008216', 'prefecture' => '京都府', 'city' => '京都市', 'address1' => '下京区1-1', 'address2' => ''],
    'items' => [[
        'order_detail_id' => 'D-11', 'title' => '和服腰带', 'option' => 'Blue', 'chinese_option' => '蓝色',
        'quantity' => 2, 'weight' => '0.8', 'comment' => '易碎', 'tranship_comment' => '加固',
        'ship_company' => '中通', 'ship_number' => '75500098', 'intl_number' => '3611234',
        'intl_status' => '', 'logistics' => '清关中', 'unit_price' => '5800', 'sku_image' => 'img/belt.jpg',
    ]],
]];

$failures = [];
$assert = static function (string $name, mixed $actual, mixed $expected) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = sprintf("%s:\n    expected %s\n    got      %s", $name, var_export($expected, true), var_export($actual, true));
    }
};

// —— 回归对齐:builtin_sx 与老 sxRows() 行为逐列一致 ——
$sx = $engine->render($templates->find('t1', 'builtin_sx'), $orders);
$assert('sx headers', $sx['headers'], ['日期', '订单号', '派送单号', '重量', '收货人', '收货人电话', '收货人地址', '收货人邮编', '国内快递单号', '图片', '数量', '品名', '颜色', '备注', '西阵电商公司备注']);
$assert('sx row', $sx['rows'][0], ['', 'Y-2001', '', '0.8', '铃木', '09011112222', '京都府京都市下京区1-1', '6008216', '中通 75500098', 'img/belt.jpg', 2, '和服腰带', '蓝色', '加固', '易碎']);
$assert('sx imageColumns', $sx['imageColumns'], [9]);
$assert('sx format', $sx['format'], 'xlsx');

// —— 回归对齐:builtin_qoo10 与老 qoo10Rows() 一致 ——
$q = $engine->render($templates->find('t1', 'builtin_qoo10'), $orders);
$assert('qoo10 headers', $q['headers'], ['订购号码', '运送公司', '运送单号', '订购国家']);
$assert('qoo10 row', $q['rows'][0], ['D-11', '中通', '3611234', 'JP']);
$assert('qoo10 format', $q['format'], 'csv');

// —— 回归对齐:builtin_wowma 与老 wowmaRows() 一致 ——
$w = $engine->render($templates->find('t1', 'builtin_wowma'), $orders);
$assert('wowma headers', $w['headers'], ['controlType', 'orderId', 'orderStatus', 'printStatus', 'shipStatus', 'shippingDate', 'shippingCarrier', 'shippingNumber', '国际运单状态（需删除）', '店铺名（需删除）', '订单时间']);
$assert('wowma row', $w['rows'][0], ['U', 'Y-2001', 'Finish_send', 'Y', 'Y', date('Y/m/d'), '2', '3611234', '清关中', '京都店', '2026-07-01 09:30:00']);

// —— 回归对齐:builtin_riya 首行关键列(第1日期/2国内单号/7订单号/12收件电话/25数量/26USD) ——
$r = $engine->render($templates->find('t1', 'builtin_riya'), $orders);
$assert('riya 列数', count($r['headers']), 26);
$assert('riya 日期', $r['rows'][0][0], date('m-d'));
$assert('riya 国内单号', $r['rows'][0][1], '中通 75500098');
$assert('riya 订单号', $r['rows'][0][6], 'Y-2001');
$assert('riya 收件电话', $r['rows'][0][11], '09011112222');
$assert('riya 数量', $r['rows'][0][24], 2);
$assert('riya USD', $r['rows'][0][25], '20');

// —— 新能力:const/raw/safeCell ——
$custom = ['id' => 'tpl_x', 'name' => '自定义', 'format' => 'csv', 'columns' => [
    ['type' => 'const', 'label' => '国家', 'value' => 'JP'],
    ['type' => 'raw', 'path' => 'item.ship_number', 'label' => '原始单号'],
    ['type' => 'raw', 'path' => 'customer.name', 'label' => '原始姓名'],
    ['type' => 'raw', 'path' => 'item.no_such', 'label' => '缺失'],
    ['type' => 'const', 'label' => '注入', 'value' => '=CMD()'],
]];
$c = $engine->render($custom, $orders);
$assert('const列', $c['rows'][0][0], 'JP');
$assert('raw item', $c['rows'][0][1], '75500098');
$assert('raw customer', $c['rows'][0][2], '铃木');
$assert('raw 缺失为空', $c['rows'][0][3], '');
$assert('safeCell 防注入', $c['rows'][0][4], "'=CMD()");
$assert('filename 后缀', str_ends_with($c['filename'], '.csv'), true);
$assert('无图片列', $c['imageColumns'], []);

@unlink($dataFile);
if ($failures !== []) {
    echo "PlatformExportService render test FAILED:\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}
echo "PlatformExportService render test OK\n";
