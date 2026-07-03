<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
require $basePath . '/app/Core/StoreInterface.php';
require $basePath . '/app/Core/Permission.php';
require $basePath . '/app/Core/TenantFeature.php';
require $basePath . '/app/Core/JsonStore.php';
require $basePath . '/app/Services/ExportFieldRegistry.php';
require $basePath . '/app/Services/ExportTemplateService.php';

use Xizhen\Core\JsonStore;
use Xizhen\Services\ExportTemplateService;

$dataFile = sys_get_temp_dir() . '/export_tpl_test_' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($dataFile, json_encode([
    'tenants' => [['key' => 't1', 'name' => '测试租户', 'status' => 'active']],
    'settings' => ['tenant' => []],
], JSON_UNESCAPED_UNICODE));
// 若 JsonStore 默认结构要求更多顶层键,按运行报错补齐 fixture,但断言不得放宽。

$store = new JsonStore($dataFile);
$service = new ExportTemplateService($store);
$failures = [];
$assert = static function (string $name, bool $ok) use (&$failures): void {
    if (!$ok) {
        $failures[] = $name;
    }
};

// 预置模板
$builtin = $service->builtinTemplates();
$assert('预置模板5个', count($builtin) === 5);
$assert('builtin_qoo10为csv', $service->find('t1', 'builtin_qoo10')['format'] === 'csv');
$assert('builtin_wowma为csv', $service->find('t1', 'builtin_wowma')['format'] === 'csv');
$assert('builtin_riya为xlsx', $service->find('t1', 'builtin_riya')['format'] === 'xlsx');
$assert('variant映射', $service->fromLegacyVariant('wd') === 'builtin_wd');
$assert('未知variant', $service->fromLegacyVariant('nope') === null);

// 校验
$assert('空列拒绝', $service->validateColumns([]) !== []);
$assert('非法field key拒绝', $service->validateColumns([['type' => 'field', 'key' => 'no.such', 'label' => 'x']]) !== []);
$assert('非法raw前缀拒绝', $service->validateColumns([['type' => 'raw', 'path' => 'evil.path', 'label' => 'x']]) !== []);
$assert('合法raw通过', $service->validateColumns([['type' => 'raw', 'path' => 'item.tabaono', 'label' => '淘宝单号']]) === []);
$assert('const缺value拒绝', $service->validateColumns([['type' => 'const', 'label' => 'x']]) !== []);
$assert('超64字label拒绝', $service->validateColumns([['type' => 'const', 'label' => str_repeat('长', 65), 'value' => '']]) !== []);
$assert('51列拒绝', $service->validateColumns(array_fill(0, 51, ['type' => 'const', 'label' => 'c', 'value' => ''])) !== []);

// 保存/查找/更新
$input = ['name' => '货代A模板', 'format' => 'xlsx', 'columns' => [
    ['type' => 'field', 'key' => 'order.platform_order_id', 'label' => '订单号'],
    ['type' => 'const', 'label' => '国家', 'value' => 'JP'],
]];
$result = $service->save('t1', $input);
$assert('保存成功无错误', $result['errors'] === [] && $result['template'] !== null);
$id = (string) $result['template']['id'];
$assert('生成tpl_前缀id', str_starts_with($id, 'tpl_'));
$assert('可find', $service->find('t1', $id)['name'] === '货代A模板');
$assert('保存后列表=5预置+1', count($service->templatesForTenant('t1')) === 6);

$update = $service->save('t1', ['id' => $id, 'name' => '货代A改', 'format' => 'csv', 'columns' => $input['columns']]);
$assert('更新不新增', count($service->templatesForTenant('t1')) === 6 && $update['errors'] === []);
$assert('更新生效', $service->find('t1', $id)['name'] === '货代A改');

// 格式/名称校验
$assert('非法format拒绝', $service->save('t1', ['name' => 'x', 'format' => 'pdf', 'columns' => $input['columns']])['errors'] !== []);
$assert('空名拒绝', $service->save('t1', ['name' => ' ', 'format' => 'csv', 'columns' => $input['columns']])['errors'] !== []);

// 删除 + 不残留(整体替换语义)
$r2 = $service->save('t1', ['name' => '货代B', 'format' => 'csv', 'columns' => $input['columns']]);
$id2 = (string) $r2['template']['id'];
$assert('删除自定义', $service->delete('t1', $id) === true);
$assert('删除预置被拒', $service->delete('t1', 'builtin_riya') === false);
$fresh = new ExportTemplateService(new JsonStore($dataFile));
$assert('删除后无残留', $fresh->find('t1', $id) === null && $fresh->find('t1', $id2) !== null);

// 上限
for ($i = 0; $i < 30; $i++) {
    $service->save('t1', ['name' => "T{$i}", 'format' => 'csv', 'columns' => $input['columns']]);
}
$assert('30上限拒绝', $service->save('t1', ['name' => '超限', 'format' => 'csv', 'columns' => $input['columns']])['errors'] !== []);

@unlink($dataFile);
if ($failures !== []) {
    echo "ExportTemplateService test FAILED:\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}
echo "ExportTemplateService test OK\n";
