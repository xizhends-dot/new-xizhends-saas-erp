# 发货单自定义导出模板 实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把 5 个写死的平台发货单导出模板重构为"字段注册表 + 租户自定义模板"统一引擎,支持自选列/排序/固定值列/CSV·XLSX 嵌图导出。

**Architecture:** 新增 `ExportFieldRegistry`(字段字典)与 `ExportTemplateService`(模板 CRUD,存 `tenantSettings`),`PlatformExportService` 重写为统一 `render(template, orders)` 引擎,XLSX 输出复用 `SpreadsheetExportService::embedImage` 既有嵌图逻辑。5 个老模板转为预置模板数据,旧 `variant` URL 参数兼容映射。

**Tech Stack:** PHP 8.4 零框架,PhpSpreadsheet(已有依赖),纯 PHP 视图 + 原生 JS,JsonStore/MysqlStore 双驱动。

**规格文档:** `php-saas/docs/specs/2026-07-03-custom-export-templates-design.md`(本计划的唯一需求来源,冲突时以规格为准)

## Global Constraints

- 所有代码在 `php-saas/` 内;**严禁修改 `old/` 下任何文件**(只读参考)。
- 命名空间 `Xizhen\`,`declare(strict_types=1)`,final class,遵循现有 Services 平铺目录结构。
- 视图所有输出必须经全局 `e()` 转义。
- `JsonStore` 与 `MysqlStore` 行为必须一致——改一个通常要同步改另一个。
- 测试是 `php-saas/tests/*.php` 独立 PHP 脚本(非 PHPUnit),运行方式 `php tests/<file>.php`,失败 `exit(1)`,成功输出 OK;缺扩展时输出 skipped 并 `exit(0)`(参照 `tests/purchase_xlsx_workflow_test.php`)。
- 校验上限(规格定死):模板数 ≤30(不含预置)、每模板列数 1–50、模板名/列显示名 ≤64 字符且非空、`format` 仅 `csv`|`xlsx`、`raw.path` 必须以 `order.`/`item.`/`customer.` 开头。
- 提交信息用约定式提交(feat/fix/test/docs),中文描述,不加归属尾注。
- 每个任务完成后 `git commit`;工作目录有其他人的未提交改动,**只 `git add` 本任务明确列出的文件,严禁 `git add -A`**。

---

### Task 1: ExportFieldRegistry 字段注册表

**Files:**
- Create: `php-saas/app/Services/ExportFieldRegistry.php`
- Test: `php-saas/tests/export_field_registry_test.php`

**Interfaces:**
- Produces(后续任务依赖的精确签名):
  - `ExportFieldRegistry::fields(): array<string, array{label: string, group: string, type: string}>` — key 为字段标识(如 `order.platform_order_id`),type 为 `text|image|date`
  - `ExportFieldRegistry::groups(): array<string, array<int, array{key: string, label: string, type: string}>>` — 按分组名聚合,供编辑器左侧渲染
  - `ExportFieldRegistry::has(string $key): bool`
  - `ExportFieldRegistry::resolve(string $key, array $order, array $item): mixed` — 未知 key 返回 `''`

- [x] **Step 1: 写失败测试**

创建 `php-saas/tests/export_field_registry_test.php`:

```php
<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
require $basePath . '/app/Services/ExportFieldRegistry.php';

use Xizhen\Services\ExportFieldRegistry;

$failures = [];
$check = static function (string $name, mixed $actual, mixed $expected) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = sprintf('%s: expected %s, got %s', $name, var_export($expected, true), var_export($actual, true));
    }
};

$order = [
    'platform_order_id' => 'W-1001',
    'store' => '一号店',
    'order_date' => '2026-07-01 10:00:00',
    'platform' => 'w',
    'ship_method' => 'ヤマト',
    'customer' => [
        'name' => '山田太郎',
        'phone' => '9012345678',
        'zip' => '1500001',
        'prefecture' => '東京都',
        'city' => '渋谷区',
        'address1' => '神南1-2-3',
        'address2' => '',
    ],
];
$item = [
    'order_detail_id' => 'D-01',
    'title' => '茶碗',
    'option' => 'Red',
    'chinese_option' => '红色',
    'quantity' => 3,
    'weight' => '1.2',
    'material' => '陶器',
    'comment' => '备注A',
    'tranship_comment' => '转运B',
    'ship_company' => '申通',
    'ship_number' => '77300012345',
    'intl_number' => '3680001112223',
    'intl_status' => '已签收',
    'logistics' => '在途',
    'logistic_trace' => '东京营业所',
    'unit_price' => '2900',
    'amount' => '15.5',
    'cn_amount' => '8',
    'com_amount' => '2',
    'sku_image' => 'storage/tenants/erp/img/a.jpg',
];

$check('订单号', ExportFieldRegistry::resolve('order.platform_order_id', $order, $item), 'W-1001');
$check('店铺名', ExportFieldRegistry::resolve('order.store', $order, $item), '一号店');
$check('明细ID', ExportFieldRegistry::resolve('item.order_detail_id', $order, $item), 'D-01');
$check('明细ID回退订单号', ExportFieldRegistry::resolve('item.order_detail_id', $order, ['order_detail_id' => ''] + $item), 'W-1001');
$check('电话补0', ExportFieldRegistry::resolve('customer.phone', $order, $item), '09012345678');
$check('电话已有0不重复补', ExportFieldRegistry::resolve('customer.phone', ['customer' => ['phone' => '090-1234']] + $order, $item), '090-1234');
$check('邮编7位不动', ExportFieldRegistry::resolve('customer.zip', $order, $item), '1500001');
$check('邮编不足补0', ExportFieldRegistry::resolve('customer.zip', ['customer' => ['zip' => '54321']] + $order, $item), '0054321');
$check('地址拼接', ExportFieldRegistry::resolve('customer.address', $order, $item), '東京都渋谷区神南1-2-3');
$check('地址回退address', ExportFieldRegistry::resolve('customer.address', ['customer' => ['address' => '整体地址']] + $order, $item), '整体地址');
$check('中文规格', ExportFieldRegistry::resolve('item.chinese_option', $order, $item), '红色');
$check('中文规格回退option', ExportFieldRegistry::resolve('item.chinese_option', $order, ['chinese_option' => ''] + $item), 'Red');
$check('数量int', ExportFieldRegistry::resolve('item.quantity', $order, $item), 3);
$check('数量最小1', ExportFieldRegistry::resolve('item.quantity', $order, ['quantity' => 0] + $item), 1);
$check('国内快递公司', ExportFieldRegistry::resolve('logistics.ship_company', $order, $item), '申通');
$check('国内快递公司回退ship_method', ExportFieldRegistry::resolve('logistics.ship_company', $order, ['ship_company' => ''] + $item), 'ヤマト');
$check('国内单号拼接', ExportFieldRegistry::resolve('logistics.domestic_full', $order, $item), '申通 77300012345');
$check('国际单号', ExportFieldRegistry::resolve('logistics.intl_tracking', $order, $item), '3680001112223');
$check('国际单号回退国内', ExportFieldRegistry::resolve('logistics.intl_tracking', $order, ['intl_number' => ''] + $item), '77300012345');
$check('国际状态回退logistics', ExportFieldRegistry::resolve('logistics.intl_status', $order, ['intl_status' => ''] + $item), '在途');
$check('USD折算', ExportFieldRegistry::resolve('money.usd_unit_price', $order, $item), '10');
$check('USD为0输出空', ExportFieldRegistry::resolve('money.usd_unit_price', $order, ['unit_price' => '0'] + $item), '');
$check('图片回退链', ExportFieldRegistry::resolve('item.image', $order, ['sku_image' => '', 'main_image' => 'm.jpg'] + $item), 'm.jpg');
$check('wowma代码368', ExportFieldRegistry::resolve('generated.wowma_carrier_code', $order, $item), '1');
$check('wowma代码361', ExportFieldRegistry::resolve('generated.wowma_carrier_code', $order, ['intl_number' => '3610009'] + $item), '2');
$check('wowma无匹配回退公司', ExportFieldRegistry::resolve('generated.wowma_carrier_code', $order, ['intl_number' => '999', 'ship_company' => 'EMS'] + $item), 'EMS');
$check('今天md', ExportFieldRegistry::resolve('generated.today_md', $order, $item), date('m-d'));
$check('今天ymd', ExportFieldRegistry::resolve('generated.today_ymd', $order, $item), date('Y/m/d'));
$check('未知key返回空', ExportFieldRegistry::resolve('no.such_key', $order, $item), '');
$check('has已知', ExportFieldRegistry::has('order.platform_order_id'), true);
$check('has未知', ExportFieldRegistry::has('no.such_key'), false);
$check('image类型标记', ExportFieldRegistry::fields()['item.image']['type'], 'image');

$groups = ExportFieldRegistry::groups();
if (!isset($groups['订单'], $groups['收件人'], $groups['商品'], $groups['物流'], $groups['金额'], $groups['图片'], $groups['生成值'])) {
    $failures[] = 'groups() 缺少预期分组: ' . implode(',', array_keys($groups));
}

if ($failures !== []) {
    echo "ExportFieldRegistry test FAILED:\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}
echo "ExportFieldRegistry test OK (" . count(ExportFieldRegistry::fields()) . " fields)\n";
```

- [x] **Step 2: 运行测试确认失败**

```bash
cd php-saas && php tests/export_field_registry_test.php
```
预期:FAIL(文件不存在的 require 报错)。

- [x] **Step 3: 实现 ExportFieldRegistry**

创建 `php-saas/app/Services/ExportFieldRegistry.php`(完整实现):

```php
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
```

- [x] **Step 4: 运行测试确认通过**

```bash
cd php-saas && php tests/export_field_registry_test.php
```
预期:`ExportFieldRegistry test OK (36 fields)`。

- [x] **Step 5: 提交**

```bash
git add php-saas/app/Services/ExportFieldRegistry.php php-saas/tests/export_field_registry_test.php
git commit -m "feat: 发货单导出字段注册表 ExportFieldRegistry"
```

---

### Task 2: 模板存储语义修正 + ExportTemplateService

**Files:**
- Modify: `php-saas/app/Core/JsonStore.php`(`saveTenantSettings`,约 1690 行)
- Modify: `php-saas/app/Core/MysqlStore.php`(`saveTenantSettings`,约 1811 行)
- Create: `php-saas/app/Services/ExportTemplateService.php`
- Test: `php-saas/tests/export_template_service_test.php`

**Interfaces:**
- Consumes: `ExportFieldRegistry::has()`(Task 1)、`StoreInterface::tenantSettings()/saveTenantSettings()`(现有)
- Produces:
  - `new ExportTemplateService(StoreInterface $store)`
  - `builtinTemplates(): array<int, array<string, mixed>>` — 5 个预置模板,id 为 `builtin_riya|builtin_sx|builtin_wd|builtin_qoo10|builtin_wowma`
  - `templatesForTenant(string $tenantKey): array<int, array<string, mixed>>` — 预置在前 + 自定义
  - `find(string $tenantKey, string $id): ?array<string, mixed>`
  - `save(string $tenantKey, array $input): array{template: ?array<string, mixed>, errors: array<string, string>}`
  - `delete(string $tenantKey, string $id): bool` — 预置返回 false
  - `validateColumns(array $columns): array<int, string>` — 错误消息列表,空数组为合法
  - `fromLegacyVariant(string $variant): ?string` — `'riya'→'builtin_riya'` 等,未知返回 null

**背景(为什么要改 Store):** 两个 Store 的 `saveTenantSettings` 都用 `array_replace_recursive` 合并——对列表(数字索引数组)会按索引合并,删除模板后旧列表尾部元素会残留。因此 `export_templates` 键必须**整体替换**。另外 `MysqlStore::saveTenantSettings` 只持久化固定 section 白名单 `['company','orders','profit','logistics','api_1688','notices']`,不加白名单该键在 MySQL 驱动下会**静默丢失**。

- [x] **Step 1: 修改 JsonStore::saveTenantSettings(整体替换语义)**

在 `php-saas/app/Core/JsonStore.php` 的 `saveTenantSettings` 中,`$settings = array_replace_recursive($current, $data);` 之后紧接着加:

```php
        if (array_key_exists('export_templates', $data)) {
            $settings['export_templates'] = $data['export_templates'];
        }
```

- [x] **Step 2: 修改 MysqlStore::saveTenantSettings(同语义 + section 白名单)**

在 `php-saas/app/Core/MysqlStore.php` 的 `saveTenantSettings` 中:
1. `$settings = array_replace_recursive(...)` 后加与 Step 1 完全相同的 3 行;
2. section 白名单数组 `['company', 'orders', 'profit', 'logistics', 'api_1688', 'notices']` 追加 `'export_templates'`。

注意:该方法内 section 值以 `json_encode($settings[$section] ?? [])` 写入 `tenant_settings` 表(key-value 结构),`export_templates` 是数组,无需建表迁移。

- [x] **Step 3: 写失败测试**

创建 `php-saas/tests/export_template_service_test.php`:

```php
<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
require $basePath . '/app/Core/StoreInterface.php';
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
```

- [x] **Step 4: 运行测试确认失败**

```bash
cd php-saas && php tests/export_template_service_test.php
```
预期:FAIL(ExportTemplateService 不存在)。

- [x] **Step 5: 实现 ExportTemplateService**

创建 `php-saas/app/Services/ExportTemplateService.php`:

```php
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

        if ($name === '' || mb_strlen($name) > self::MAX_LABEL_LENGTH) {
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
            if ($display === '' || mb_strlen($display) > self::MAX_LABEL_LENGTH) {
                $errors[] = $label . ':显示名必填且不超过 ' . self::MAX_LABEL_LENGTH . ' 个字符。';
            }
            if ($type === 'field') {
                if (!ExportFieldRegistry::has((string) ($column['key'] ?? ''))) {
                    $errors[] = $label . ':未知字段 ' . (string) ($column['key'] ?? '');
                }
            } elseif ($type === 'const') {
                if (!array_key_exists('value', $column) || !is_scalar($column['value'] ?? null) && ($column['value'] ?? null) !== '') {
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
}
```

注意 `validateColumns` 中 const 的 value 判断:意图是"必须存在 value 键且为标量(空字符串允许)"。实现为 `!array_key_exists('value', $column) || !is_scalar($column['value'])` 时报错——上面代码里的复合条件按此意图写,若测试跑不过按此意图修正优先级括号。

- [x] **Step 6: 运行测试确认通过**

```bash
cd php-saas && php tests/export_template_service_test.php
php tests/export_field_registry_test.php
```
预期:两个测试都 OK。若 JsonStore 对 fixture 结构有额外要求(如缺 `users` 键报 warning),按报错补齐 fixture 顶层键,断言不变。

- [x] **Step 7: 提交**

```bash
git add php-saas/app/Services/ExportTemplateService.php php-saas/tests/export_template_service_test.php php-saas/app/Core/JsonStore.php php-saas/app/Core/MysqlStore.php
git commit -m "feat: 发货单导出模板服务与预置模板,tenantSettings 支持 export_templates 整体替换"
```

---

### Task 3: PlatformExportService 重写为统一渲染引擎(含回归对齐)

**Files:**
- Rewrite: `php-saas/app/Services/PlatformExportService.php`
- Test: `php-saas/tests/platform_export_render_test.php`

**Interfaces:**
- Consumes: `ExportFieldRegistry::resolve()/fields()`(Task 1)、模板结构(Task 2)
- Produces:
  - `PlatformExportService::render(array $template, array $orders): array{name: string, filename: string, format: string, headers: array<int, string>, rows: array<int, array<int, mixed>>, imageColumns: array<int, int>}`
  - **过渡兼容**(Task 5 切换调用方后删除):保留旧签名 `exportDataset(string $tenantKey, string $variant, array $orders, array $options = []): array` 与 `variants()`,内部转调 `render()`,保证本任务提交后系统仍可运行。

行为规格:
- 行粒度:每订单每商品项一行(`$order['items']` 中 `is_array` 的项);
- 列取值:`field` → `ExportFieldRegistry::resolve($key, $order, $item)`;`const` → 原样 `value` 字符串;`raw` → 按 path 前缀取 `$order[...]` / `$item[...]` / `$order['customer'][...]`,仅标量输出(`(string)` 转换),非标量/缺失输出 `''`;
- `imageColumns`:模板中 `field` 类型且注册表 `type === 'image'` 的列的 0-based 下标;
- `filename`:`'shipping-' . $tenantKey ... ` 不带 tenantKey——**修正**:render 不接收 tenantKey,filename 为 `'shipping-' . date('Ymd-His') . '.' . ($format === 'xlsx' ? 'xlsx' : 'csv')`;`name` 取模板 `name`;
- CSV 防注入:所有 string 单元格过 `safeCell`(以 `= + - @` 开头前置 `'`),沿用旧实现。

- [x] **Step 1: 写失败测试(回归对齐 + 新能力)**

创建 `php-saas/tests/platform_export_render_test.php`。样例订单构造 + 两类断言:(a) 5 个预置模板对样例数据的 headers 与首行输出,与改造前老代码行为逐列一致(期望值按老 variant 方法逻辑手工推导写死);(b) const/raw 列与 imageColumns 行为。

```php
<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
require $basePath . '/app/Core/StoreInterface.php';
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
```

- [x] **Step 2: 运行测试确认失败**

```bash
cd php-saas && php tests/platform_export_render_test.php
```
预期:FAIL(`render()` 不存在)。

- [x] **Step 3: 重写 PlatformExportService**

完整替换 `php-saas/app/Services/PlatformExportService.php` 内容:

```php
<?php

declare(strict_types=1);

namespace Xizhen\Services;

/**
 * 发货单导出统一渲染引擎:模板(预置或租户自定义) + 订单集 → headers/rows。
 * 字段取值逻辑全部在 ExportFieldRegistry;本类只做列遍历、raw 路径、CSV 防注入。
 */
final class PlatformExportService
{
    /**
     * @param array<string, mixed> $template
     * @param array<int, array<string, mixed>> $orders
     * @return array{name: string, filename: string, format: string, headers: array<int, string>, rows: array<int, array<int, mixed>>, imageColumns: array<int, int>}
     */
    public function render(array $template, array $orders): array
    {
        $columns = array_values(array_filter((array) ($template['columns'] ?? []), 'is_array'));
        $format = strtolower((string) ($template['format'] ?? 'csv')) === 'xlsx' ? 'xlsx' : 'csv';
        $fields = ExportFieldRegistry::fields();

        $headers = [];
        $imageColumns = [];
        foreach ($columns as $index => $column) {
            $headers[] = (string) ($column['label'] ?? '');
            if (($column['type'] ?? '') === 'field' && ($fields[(string) ($column['key'] ?? '')]['type'] ?? '') === 'image') {
                $imageColumns[] = $index;
            }
        }

        $rows = [];
        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }
            foreach (array_filter((array) ($order['items'] ?? []), 'is_array') as $item) {
                $row = [];
                foreach ($columns as $column) {
                    $row[] = $this->cellValue($column, $order, $item);
                }
                $rows[] = $row;
            }
        }

        return [
            'name' => (string) ($template['name'] ?? '发货单导出'),
            'filename' => 'shipping-' . date('Ymd-His') . '.' . $format,
            'format' => $format,
            'headers' => $headers,
            'rows' => $this->safeRows($rows),
            'imageColumns' => $imageColumns,
        ];
    }

    /**
     * @param array<string, mixed> $column
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     */
    private function cellValue(array $column, array $order, array $item): mixed
    {
        return match ((string) ($column['type'] ?? '')) {
            'field' => ExportFieldRegistry::resolve((string) ($column['key'] ?? ''), $order, $item),
            'const' => (string) ($column['value'] ?? ''),
            'raw' => $this->rawValue((string) ($column['path'] ?? ''), $order, $item),
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     */
    private function rawValue(string $path, array $order, array $item): string
    {
        $value = null;
        if (str_starts_with($path, 'order.')) {
            $value = $order[substr($path, 6)] ?? null;
        } elseif (str_starts_with($path, 'item.')) {
            $value = $item[substr($path, 5)] ?? null;
        } elseif (str_starts_with($path, 'customer.')) {
            $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
            $value = $customer[substr($path, 9)] ?? null;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /** @param array<int, array<int, mixed>> $rows @return array<int, array<int, mixed>> */
    private function safeRows(array $rows): array
    {
        return array_map(
            fn (array $row): array => array_map(fn (mixed $cell): mixed => $this->safeCell($cell), $row),
            $rows
        );
    }

    private function safeCell(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : $value;
    }
}
```

**过渡兼容:** 当前 `TenantController::importExportNonExcel()`(约 1359 行)调用 `variants()` 和 `exportDataset()`、`exportPlatformSpecial()`(约 1390 行)调用 `exportDataset()`。为使本任务提交后系统可运行,再追加两个过渡方法(Task 5 删除):

```php
    /** @deprecated Task 5 切换到模板机制后删除。 @return array<string, array{name: string, source: string, platform: string, note: string}> */
    public function variants(): array
    {
        $meta = [];
        foreach ((new ExportTemplateService(\Xizhen\Core\StoreFactory::create()))->builtinTemplates() as $template) {
            $key = substr((string) $template['id'], strlen('builtin_'));
            $meta[$key] = ['name' => (string) $template['name'], 'source' => 'builtin', 'platform' => '', 'note' => '预置模板(过渡兼容)'];
        }

        return $meta;
    }

    /** @deprecated Task 5 切换到模板机制后删除。 */
    public function exportDataset(string $tenantKey, string $variant, array $orders, array $options = []): array
    {
        $service = new ExportTemplateService(\Xizhen\Core\StoreFactory::create());
        $template = $service->find($tenantKey, $service->fromLegacyVariant(strtolower(trim($variant))) ?? 'builtin_riya')
            ?? $service->builtinTemplates()[0];
        $template['format'] = 'csv'; // 旧调用方走 sendCsvDataset,保持 CSV
        $dataset = $this->render($template, $orders);
        $dataset['source'] = 'builtin';
        $dataset['note'] = '预置模板(过渡兼容)';

        return $dataset;
    }
```

若 `StoreFactory::create()` 的实际静态方法名不同(用 Grep 查 `class StoreFactory` 确认),按实际名调用;过渡方法在 Task 5 即删,不追求优雅。

- [x] **Step 4: 运行测试确认通过**

```bash
cd php-saas && php tests/platform_export_render_test.php && php tests/export_template_service_test.php && php tests/export_field_registry_test.php
```
预期:全部 OK。

- [x] **Step 5: 手工冒烟(过渡兼容不破坏现有页面)**

```bash
cd php-saas && php -S 127.0.0.1:8090 -t public &
curl -s "http://127.0.0.1:8090/import-export/non-excel?tenant=erp" -o /dev/null -w "%{http_code}\n"
```
预期:`200`(或登录跳转 302,总之非 500)。完成后停掉服务器进程。

- [x] **Step 6: 提交**

```bash
git add php-saas/app/Services/PlatformExportService.php php-saas/tests/platform_export_render_test.php
git commit -m "feat: PlatformExportService 重写为字段注册表驱动的统一渲染引擎"
```

---

### Task 4: SpreadsheetExportService::shippingWorkbook(XLSX 嵌图输出)

**Files:**
- Modify: `php-saas/app/Services/SpreadsheetExportService.php`(新增公共方法,放在 `purchaseWorkbook` 之后)
- Test: `php-saas/tests/shipping_xlsx_workflow_test.php`

**Interfaces:**
- Consumes: Task 3 `render()` 的返回结构(headers/rows/imageColumns/name);本类现有私有方法 `embedImage(object $sheet, string $coordinate, string $source, int $maxWidth, int $maxHeight)`(约 668 行,已处理远程 URL 下载/路径穿越/大小上限/失败静默跳过)、`assertRuntime()`、`cell(int $column, int $row)`(签名以文件内实际为准,用 Grep 确认)。
- Produces: `shippingWorkbook(string $tenantKey, array $dataset, string $operator = ''): array{path: string, filename: string, name: string, rows: int}` — 返回结构与 `purchaseWorkbook` 一致,供 `TenantController::sendXlsxFile()` 直接消费。

行为规格:
- 第 1 行写 headers(加粗、浅灰底,样式参照 `purchaseWorkbook` 现有表头写法);
- 数据行从第 2 行起;`imageColumns` 列:该单元格调用 `embedImage(..., 90, 90)` 嵌图,行高设 72,列宽设 14;**同时保留单元格文本为图片 URL/路径**(嵌图失败时用户仍能看到来源,满足规格"失败降级为 URL 文本");
- 非图片列直接 `setCellValueExplicit(..., STRING)` 或按现有 workbook 方法的写值方式;
- 文件写到临时目录(参照现有 workbook 方法的 `tempnam`/输出路径写法),filename 用 `$dataset['filename']` 把 `.csv` 后缀替换为 `.xlsx`(若已是 `.xlsx` 不动)。

- [x] **Step 1: 写失败测试**

创建 `php-saas/tests/shipping_xlsx_workflow_test.php`(参照 `tests/purchase_xlsx_workflow_test.php` 的扩展检测与读回断言模式):

```php
<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';
require $basePath . '/app/Services/ExportFieldRegistry.php';
require $basePath . '/app/Services/SpreadsheetExportService.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use Xizhen\Services\SpreadsheetExportService;

$missingExtensions = array_values(array_filter(
    ['zip', 'xml', 'xmlwriter', 'mbstring', 'gd'],
    static fn (string $extension): bool => !extension_loaded($extension)
));
if ($missingExtensions !== []) {
    echo 'Shipping XLSX workflow test skipped: missing PHP extension(s): ' . implode(', ', $missingExtensions) . ".\n";
    exit(0);
}

$dataset = [
    'name' => '测试发货单',
    'filename' => 'shipping-20260703-000000.xlsx',
    'format' => 'xlsx',
    'headers' => ['订单号', '图片', '数量'],
    'rows' => [
        ['Y-2001', 'img/no_such_image.jpg', 2],
        ['Y-2002', '', 1],
    ],
    'imageColumns' => [1],
];

$service = new SpreadsheetExportService();
$file = $service->shippingWorkbook('erp', $dataset, '测试员');

$failures = [];
if (!is_file((string) ($file['path'] ?? ''))) {
    $failures[] = 'shippingWorkbook 未生成文件: ' . var_export($file, true);
} else {
    $sheet = IOFactory::load($file['path'])->getActiveSheet();
    if ((string) $sheet->getCell('A1')->getValue() !== '订单号') {
        $failures[] = 'A1 表头不对: ' . $sheet->getCell('A1')->getValue();
    }
    if ((string) $sheet->getCell('A2')->getValue() !== 'Y-2001') {
        $failures[] = 'A2 数据不对: ' . $sheet->getCell('A2')->getValue();
    }
    if ((string) $sheet->getCell('B2')->getValue() !== 'img/no_such_image.jpg') {
        $failures[] = 'B2 应保留图片路径文本(嵌图失败降级): ' . $sheet->getCell('B2')->getValue();
    }
    if ((int) ($file['rows'] ?? 0) !== 2) {
        $failures[] = 'rows 计数应为 2';
    }
    @unlink($file['path']);
}

if ($failures !== []) {
    echo "Shipping XLSX workflow test FAILED:\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}
echo "Shipping XLSX workflow test OK\n";
```

注意:`SpreadsheetExportService` 构造函数签名先 Grep 确认(`function __construct` in that file);若需要 basePath 参数,测试按 `purchaseWorkbook` 测试里的实例化方式照抄。

- [x] **Step 2: 运行测试确认失败**

```bash
cd php-saas && php tests/shipping_xlsx_workflow_test.php
```
预期:FAIL(方法不存在)或 skipped(缺扩展——缺扩展时本任务其余步骤仍继续,靠 Task 3 单测与人工验收覆盖)。

- [x] **Step 3: 实现 shippingWorkbook**

在 `php-saas/app/Services/SpreadsheetExportService.php` 中 `purchaseWorkbook` 方法之后新增(样板代码——Spreadsheet 初始化、临时文件写出、返回结构照抄同文件 `purchaseWorkbook`/`customerWorkbook` 的既有写法,保持一致):

```php
    /**
     * 发货单自定义模板 XLSX 导出:表头样式 + 图片列嵌图(失败降级为文本)。
     * @param array{name: string, filename: string, headers: array<int, string>, rows: array<int, array<int, mixed>>, imageColumns: array<int, int>} $dataset
     * @return array{path: string, filename: string, name: string, rows: int}
     */
    public function shippingWorkbook(string $tenantKey, array $dataset, string $operator = ''): array
    {
        $this->assertRuntime();
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator($operator !== '' ? $operator : 'Xizhen SaaS')
            ->setTitle((string) ($dataset['name'] ?? '发货单导出'));
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('发货单');

        $headers = array_values((array) ($dataset['headers'] ?? []));
        $imageColumns = array_map('intval', (array) ($dataset['imageColumns'] ?? []));
        foreach ($headers as $index => $header) {
            $cell = $this->cell($index + 1, 1);
            $sheet->setCellValue($cell, (string) $header);
            $sheet->getStyle($cell)->getFont()->setBold(true);
        }
        foreach ($imageColumns as $imageColumn) {
            $sheet->getColumnDimensionByColumn($imageColumn + 1)->setWidth(14);
        }

        $rowNumber = 2;
        foreach ((array) ($dataset['rows'] ?? []) as $row) {
            $hasImage = false;
            foreach (array_values((array) $row) as $index => $value) {
                $cell = $this->cell($index + 1, $rowNumber);
                $sheet->setCellValueExplicit($cell, (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                if (in_array($index, $imageColumns, true) && trim((string) $value) !== '') {
                    $this->embedImage($sheet, $cell, (string) $value, 90, 90);
                    $hasImage = true;
                }
            }
            if ($hasImage) {
                $sheet->getRowDimension($rowNumber)->setRowHeight(72);
            }
            $rowNumber++;
        }

        $filename = (string) ($dataset['filename'] ?? 'shipping.xlsx');
        if (!str_ends_with($filename, '.xlsx')) {
            $filename = preg_replace('/\.csv$/', '', $filename) . '.xlsx';
        }

        return $this->writeWorkbookFile($spreadsheet, $filename, (string) ($dataset['name'] ?? '发货单导出'), count((array) ($dataset['rows'] ?? [])));
    }
```

`writeWorkbookFile`/`cell`/临时文件写出:同文件已有 workbook 方法末尾的"写临时文件并返回 `{path,filename,name,rows}`"逻辑——若已有可复用的私有方法直接用;没有就提取一个(把 `purchaseWorkbook` 末尾写文件的几行提为 `writeWorkbookFile(Spreadsheet $spreadsheet, string $filename, string $name, int $rows): array` 并让 `purchaseWorkbook` 一起改用,消除重复)。`cell()` 若不存在,用 `\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row`。

- [x] **Step 4: 运行测试确认通过**

```bash
cd php-saas && php tests/shipping_xlsx_workflow_test.php && php tests/purchase_xlsx_workflow_test.php
```
预期:新测试 OK,且 `purchase_xlsx_workflow_test.php` 仍 OK(确认提取 `writeWorkbookFile` 未破坏采购导出)。

- [x] **Step 5: 提交**

```bash
git add php-saas/app/Services/SpreadsheetExportService.php php-saas/tests/shipping_xlsx_workflow_test.php
git commit -m "feat: 发货单模板 XLSX 导出 shippingWorkbook(图片列嵌图,失败降级文本)"
```

---

### Task 5: TenantController 路由与方法(模板 CRUD/预览/导出切换)

**Files:**
- Modify: `php-saas/app/Controllers/TenantController.php`
- Modify: `php-saas/public/index.php`(路由表,约 110-125 行区域)
- Modify: `php-saas/app/Services/PlatformExportService.php`(删除 Task 3 的两个 `@deprecated` 过渡方法)

**Interfaces:**
- Consumes: Task 1-4 全部公共方法;现有 `$this->forbid()`、`renderTenant()`、`sendCsvDataset()`、`sendXlsxFile()`、`ordersForExport()`、`exportCriteriaFrom()`、`requireTenantFeature()`、`auth->requireTenantPermission()`。
- Produces(Task 6 视图依赖):`importExportNonExcel()` 传给视图的新变量 `exportTemplates`(数组)与 `stores`;新路由 4 条(见下)。

- [ ] **Step 1: 构造器注入 ExportTemplateService**

`TenantController` 属性区加 `private ExportTemplateService $exportTemplateService;`,构造函数中(参照 `platformExportService` 的初始化位置)加 `$this->exportTemplateService = new ExportTemplateService($this->store);`,顶部 `use Xizhen\Services\ExportTemplateService;`。若构造器中 `$this->store` 尚未赋值,放在赋值之后。

- [ ] **Step 2: 注册路由**

`php-saas/public/index.php` 在 `$router->get('/import-export/platform-special/export', ...)` 附近追加:

```php
$router->get('/import-export/export-templates/edit', [$tenant, 'exportTemplateEdit']);
$router->post('/import-export/export-templates/save', [$tenant, 'saveExportTemplate']);
$router->post('/import-export/export-templates/delete', [$tenant, 'deleteExportTemplate']);
$router->post('/import-export/export-templates/preview', [$tenant, 'previewExportTemplate']);
```

- [ ] **Step 3: 新增控制器方法**

在 `exportPlatformSpecial()` 附近新增 4 个方法:

```php
    public function exportTemplateEdit(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->auth->requireTenantPermission($tenantKey, '公司设置');
        $id = trim((string) ($_GET['id'] ?? ''));
        $template = null;
        if ($id !== '') {
            $template = $this->exportTemplateService->find($tenantKey, $id);
            if ($template === null) {
                $this->forbid('导出模板不存在。');
            }
            if (str_starts_with((string) ($template['id'] ?? ''), 'builtin_')) {
                // 预置模板只读:进入编辑器即变为"另存为副本"草稿
                $template['id'] = '';
                $template['name'] = (string) $template['name'] . '（副本）';
            }
        }

        $this->renderTenant('tenant/export_template_edit', $tenantKey, [
            'title' => $template === null ? '新建导出模板' : '编辑导出模板',
            'active' => 'import_export',
            'template' => $template,
            'fieldGroups' => \Xizhen\Services\ExportFieldRegistry::groups(),
            'errors' => [],
        ]);
    }

    public function saveExportTemplate(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->auth->requireTenantPermission($tenantKey, '公司设置');
        $columns = json_decode((string) ($_POST['columns_json'] ?? '[]'), true);
        $input = [
            'id' => trim((string) ($_POST['id'] ?? '')),
            'name' => trim((string) ($_POST['name'] ?? '')),
            'format' => (string) ($_POST['format'] ?? 'xlsx'),
            'columns' => is_array($columns) ? $columns : [],
        ];
        $result = $this->exportTemplateService->save($tenantKey, $input);
        if ($result['errors'] !== []) {
            $this->renderTenant('tenant/export_template_edit', $tenantKey, [
                'title' => '编辑导出模板',
                'active' => 'import_export',
                'template' => $input + ['id' => $input['id']],
                'fieldGroups' => \Xizhen\Services\ExportFieldRegistry::groups(),
                'errors' => $result['errors'],
            ]);
            return;
        }

        header('Location: /import-export/non-excel?tenant=' . urlencode($tenantKey) . '&message=' . urlencode('模板已保存。'));
        exit;
    }

    public function deleteExportTemplate(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->auth->requireTenantPermission($tenantKey, '公司设置');
        $id = trim((string) ($_POST['id'] ?? ''));
        $message = $this->exportTemplateService->delete($tenantKey, $id) ? '模板已删除。' : '模板不存在或为系统预置,无法删除。';
        header('Location: /import-export/non-excel?tenant=' . urlencode($tenantKey) . '&message=' . urlencode($message));
        exit;
    }

    public function previewExportTemplate(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->auth->requireTenantPermission($tenantKey, '公司设置');
        header('Content-Type: application/json; charset=UTF-8');
        $columns = json_decode((string) ($_POST['columns_json'] ?? '[]'), true);
        $errors = $this->exportTemplateService->validateColumns(is_array($columns) ? $columns : []);
        if ($errors !== []) {
            echo json_encode(['ok' => false, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $orders = array_slice(
            $this->service->ordersForExport($tenantKey, $this->auth->currentTenantUser($tenantKey), $this->exportCriteriaFrom($_POST)),
            0,
            3
        );
        $dataset = $this->platformExportService->render(
            ['id' => 'preview', 'name' => '预览', 'format' => 'csv', 'columns' => $columns],
            $orders
        );
        echo json_encode(['ok' => true, 'headers' => $dataset['headers'], 'rows' => $dataset['rows']], JSON_UNESCAPED_UNICODE);
        exit;
    }
```

- [ ] **Step 4: 改造 exportPlatformSpecial 与 importExportNonExcel**

`exportPlatformSpecial()` 整体替换为:

```php
    public function exportPlatformSpecial(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->requireTenantFeature($tenantKey, 'import_export.platform_special');
        $this->auth->requireTenantPermission($tenantKey, '导入导出');
        $templateId = trim((string) ($_GET['template_id'] ?? ''));
        if ($templateId === '') {
            $legacy = preg_replace('/[^a-z0-9_]/', '', (string) ($_GET['variant'] ?? 'riya')) ?: 'riya';
            $templateId = $this->exportTemplateService->fromLegacyVariant($legacy) ?? 'builtin_riya';
        }
        $template = $this->exportTemplateService->find($tenantKey, $templateId);
        if ($template === null) {
            $this->forbid('导出模板不存在。');
        }

        $orders = $this->service->ordersForExport($tenantKey, $this->auth->currentTenantUser($tenantKey), $this->exportCriteriaFrom($_GET));
        $dataset = $this->platformExportService->render($template, $orders);
        if ($dataset['format'] === 'xlsx') {
            $this->sendXlsxFile($tenantKey, $this->spreadsheetExportService->shippingWorkbook($tenantKey, $dataset, $this->currentUserName($tenantKey)));
        }

        $this->sendCsvDataset($tenantKey, $dataset);
    }
```

`importExportNonExcel()` 中删除旧的 `platformVariants`/`previewDatasets` 组装(约 1358-1363 行的 foreach),改为:

```php
        $this->renderTenant('tenant/import_export_non_excel', $tenantKey, [
            'title' => '非 Excel 导入导出',
            'active' => 'import_export',
            'exportTemplates' => $this->exportTemplateService->templatesForTenant($tenantKey),
            'canManageTemplates' => $this->auth->tenantCan($tenantKey, '公司设置'),
            'message' => (string) ($_GET['message'] ?? ''),
            'importPreviews' => [],
            'stores' => $this->accessibleStoresForCurrentUser($tenantKey),
            'excelRequirements' => array_merge(
                $this->financeExportRequirementService->excelRequirements(),
                array_map(
                    static fn (string $item): array => ['item' => $item, 'reason' => '已通过 PhpSpreadsheet 生成样式 XLSX。', 'old_source' => 'old/*/custinfo_export.php'],
                    $this->customerExportService->excelRequirements()
                )
            ),
        ]);
```

(`tenantCan` 的实际方法名在 `AuthService` 里 Grep `function tenantCan` 确认;若叫别的名字按实际改。)

- [ ] **Step 5: 删除 PlatformExportService 的过渡方法**

删掉 Task 3 加的 `@deprecated variants()` 和 `exportDataset()`。全库 Grep `platformExportService->exportDataset\|platformExportService->variants` 确认再无调用方。

- [ ] **Step 6: 语法检查 + 全量测试**

```bash
cd php-saas && php -l app/Controllers/TenantController.php && php -l public/index.php && php -l app/Services/PlatformExportService.php
php tests/platform_export_render_test.php && php tests/export_template_service_test.php && php tests/export_field_registry_test.php && php tests/shipping_xlsx_workflow_test.php
```
预期:语法 OK,测试全过。此时 `/import-export/non-excel` 页面会因视图缺 `exportTemplates` 渲染逻辑而不完整——Task 6 立即补,属预期中间态(视图有 `?? []` 兜底不会 500)。

- [ ] **Step 7: 提交**

```bash
git add php-saas/app/Controllers/TenantController.php php-saas/public/index.php php-saas/app/Services/PlatformExportService.php
git commit -m "feat: 导出模板管理路由与控制器,发货单导出切换到模板机制"
```

---

### Task 6: 视图(模板列表区块 + 编辑器)

**Files:**
- Modify: `php-saas/app/Views/tenant/import_export_non_excel.php`
- Create: `php-saas/app/Views/tenant/export_template_edit.php`

**Interfaces:**
- Consumes: Task 5 传入的 `exportTemplates`/`canManageTemplates`/`message`/`stores`;`ExportFieldRegistry::groups()` 的 `fieldGroups`;`template`(可为 null)/`errors`。
- 样式沿用现有类:`panel`/`panel-head`/`panel-body`/`table`/`btn`/`btn primary`/`sub`/`setting-muted`/`head-actions`/`empty slim`。

- [ ] **Step 1: 改造 import_export_non_excel.php**

删除文件头部 `$platformVariants`/`$previewDatasets` 两个变量声明与对应的两个区块(第 2-3 行、15-68 行),替换为(文件其余部分不动):

```php
<?php
$exportTemplates = is_array($exportTemplates ?? null) ? $exportTemplates : [];
$canManageTemplates = (bool) ($canManageTemplates ?? false);
$message = (string) ($message ?? '');
?>
<?php if ($message !== ''): ?>
    <div class="panel"><div class="panel-body"><?= e($message) ?></div></div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head">
        <span>发货单导出模板</span>
        <span class="sub">自定义列/排序/固定值列;预置模板只读,可复制后修改</span>
    </div>
    <div class="panel-body">
        <?php if ($canManageTemplates): ?>
            <div class="head-actions" style="justify-content:flex-start; margin-bottom:8px;">
                <a class="btn primary" href="/import-export/export-templates/edit?tenant=<?= e($tenantKey) ?>">新建模板</a>
            </div>
        <?php endif; ?>
        <table class="table">
            <thead><tr><th>名称</th><th>格式</th><th>列数</th><th>类型</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($exportTemplates as $template): ?>
                <?php $isBuiltin = !empty($template['builtin']); ?>
                <tr>
                    <td><?= e($template['name'] ?? '') ?></td>
                    <td><?= e(strtoupper((string) ($template['format'] ?? 'csv'))) ?></td>
                    <td><?= e(count((array) ($template['columns'] ?? []))) ?></td>
                    <td><?= $isBuiltin ? '系统预置' : '自定义' ?></td>
                    <td>
                        <div class="head-actions" style="justify-content:flex-start;">
                            <a class="btn" href="/import-export/platform-special/export?tenant=<?= e($tenantKey) ?>&template_id=<?= e($template['id'] ?? '') ?>">导出</a>
                            <?php if ($canManageTemplates): ?>
                                <a class="btn" href="/import-export/export-templates/edit?tenant=<?= e($tenantKey) ?>&id=<?= e($template['id'] ?? '') ?>"><?= $isBuiltin ? '复制' : '编辑' ?></a>
                                <?php if (!$isBuiltin): ?>
                                    <form method="post" action="/import-export/export-templates/delete" onsubmit="return confirm('确定删除模板「<?= e($template['name'] ?? '') ?>」?');" style="display:inline;">
                                        <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                                        <input type="hidden" name="id" value="<?= e($template['id'] ?? '') ?>">
                                        <button class="btn" type="submit">删除</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$exportTemplates): ?>
                <tr><td colspan="5" class="sub">暂无导出模板。</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <div class="setting-muted">导出使用当前登录账号可见的订单;可在链接上追加平台/店铺/日期等筛选参数(与旧导出参数一致)。</div>
    </div>
</div>
```

- [ ] **Step 2: 创建编辑器视图 export_template_edit.php**

创建 `php-saas/app/Views/tenant/export_template_edit.php`(完整文件):

```php
<?php
$template = is_array($template ?? null) ? $template : null;
$fieldGroups = is_array($fieldGroups ?? null) ? $fieldGroups : [];
$errors = is_array($errors ?? null) ? $errors : [];
$columns = array_values((array) ($template['columns'] ?? []));
// JSON 内嵌 <script>:必须 HEX_TAG 转义,防止列显示名里的 </script> 造成存储型 XSS
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$columnsJson = json_encode($columns, $jsonFlags) ?: '[]';
?>
<div class="page-head">
    <div><h1><?= e($template === null || ($template['id'] ?? '') === '' ? '新建导出模板' : '编辑导出模板') ?> <span class="sub">勾选字段 → 调整顺序 → 保存</span></h1></div>
</div>

<?php if ($errors): ?>
    <div class="panel"><div class="panel-body">
        <?php foreach ($errors as $error): ?><div class="setting-muted" style="color:#c0392b;"><?= e($error) ?></div><?php endforeach; ?>
    </div></div>
<?php endif; ?>

<form method="post" action="/import-export/export-templates/save" id="tpl-form">
    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
    <input type="hidden" name="id" value="<?= e($template['id'] ?? '') ?>">
    <input type="hidden" name="columns_json" id="columns-json" value="">
    <div class="panel">
        <div class="panel-head"><span>基本信息</span></div>
        <div class="panel-body">
            <label><span>模板名称</span><input type="text" name="name" maxlength="64" required value="<?= e($template['name'] ?? '') ?>"></label>
            <label><span>导出格式</span>
                <select name="format">
                    <option value="xlsx" <?= ($template['format'] ?? 'xlsx') === 'xlsx' ? 'selected' : '' ?>>XLSX(图片嵌入)</option>
                    <option value="csv" <?= ($template['format'] ?? '') === 'csv' ? 'selected' : '' ?>>CSV</option>
                </select>
            </label>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head"><span>列配置</span><span class="sub">左侧勾选字段加入;右侧调序/改显示名/删除</span></div>
        <div class="panel-body" style="display:flex; gap:16px; align-items:flex-start;">
            <div style="flex:0 0 260px;">
                <?php foreach ($fieldGroups as $group => $fields): ?>
                    <details open>
                        <summary><strong><?= e($group) ?></strong></summary>
                        <?php foreach ($fields as $field): ?>
                            <div><label style="font-weight:normal;">
                                <input type="checkbox" class="field-toggle" value="<?= e($field['key']) ?>" data-label="<?= e($field['label']) ?>">
                                <?= e($field['label']) ?>
                            </label></div>
                        <?php endforeach; ?>
                    </details>
                <?php endforeach; ?>
                <div class="head-actions" style="justify-content:flex-start; margin-top:8px;">
                    <button class="btn" type="button" id="add-const">+ 固定值列</button>
                    <button class="btn" type="button" id="add-raw">+ 原始字段列</button>
                </div>
            </div>
            <div style="flex:1;">
                <table class="table" id="columns-table">
                    <thead><tr><th style="width:36px;">#</th><th>来源</th><th>显示名</th><th style="width:150px;">操作</th></tr></thead>
                    <tbody></tbody>
                </table>
                <div class="head-actions" style="justify-content:flex-start;">
                    <button class="btn primary" type="submit">保存模板</button>
                    <button class="btn" type="button" id="preview-btn">导出预览(前3行)</button>
                    <a class="btn" href="/import-export/non-excel?tenant=<?= e($tenantKey) ?>">返回</a>
                </div>
                <div id="preview-area"></div>
            </div>
        </div>
    </div>
</form>

<script>
(function () {
    'use strict';
    var columns = <?= $columnsJson ?>;
    var tenant = <?= json_encode((string) $tenantKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var tbody = document.querySelector('#columns-table tbody');
    var form = document.getElementById('tpl-form');

    function sourceText(col) {
        if (col.type === 'field') { return '字段:' + (col.key || ''); }
        if (col.type === 'const') { return '固定值:' + (col.value || '(空)'); }
        return '原始:' + (col.path || '');
    }

    function esc(value) {
        var div = document.createElement('div');
        div.textContent = String(value == null ? '' : value);
        return div.innerHTML;
    }

    function renderRows() {
        tbody.innerHTML = '';
        columns.forEach(function (col, i) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<td>' + (i + 1) + '</td>'
                + '<td>' + esc(sourceText(col)) + '</td>'
                + '<td><input type="text" maxlength="64" value="' + esc(col.label || '') + '" data-i="' + i + '" class="col-label"></td>'
                + '<td><button type="button" class="btn mv" data-i="' + i + '" data-d="-1">↑</button> '
                + '<button type="button" class="btn mv" data-i="' + i + '" data-d="1">↓</button> '
                + '<button type="button" class="btn rm" data-i="' + i + '">✕</button></td>';
            tbody.appendChild(tr);
        });
        document.querySelectorAll('.field-toggle').forEach(function (box) {
            box.checked = columns.some(function (col) { return col.type === 'field' && col.key === box.value; });
        });
    }

    tbody.addEventListener('click', function (event) {
        var target = event.target;
        var i = parseInt(target.getAttribute('data-i') || '-1', 10);
        if (target.classList.contains('rm') && i >= 0) {
            columns.splice(i, 1);
            renderRows();
        } else if (target.classList.contains('mv') && i >= 0) {
            var j = i + parseInt(target.getAttribute('data-d'), 10);
            if (j >= 0 && j < columns.length) {
                var tmp = columns[i]; columns[i] = columns[j]; columns[j] = tmp;
                renderRows();
            }
        }
    });

    tbody.addEventListener('input', function (event) {
        if (event.target.classList.contains('col-label')) {
            var i = parseInt(event.target.getAttribute('data-i'), 10);
            if (columns[i]) { columns[i].label = event.target.value; }
        }
    });

    document.querySelectorAll('.field-toggle').forEach(function (box) {
        box.addEventListener('change', function () {
            if (box.checked) {
                columns.push({ type: 'field', key: box.value, label: box.getAttribute('data-label') || box.value });
            } else {
                columns = columns.filter(function (col) { return !(col.type === 'field' && col.key === box.value); });
            }
            renderRows();
        });
    });

    document.getElementById('add-const').addEventListener('click', function () {
        var label = window.prompt('固定值列的表头名:');
        if (!label) { return; }
        var value = window.prompt('该列每行输出的固定内容(可留空):') || '';
        columns.push({ type: 'const', label: label.slice(0, 64), value: value });
        renderRows();
    });

    document.getElementById('add-raw').addEventListener('click', function () {
        var path = window.prompt('原始字段路径(order./item./customer. 开头,如 item.tabaono):');
        if (!path || !/^(order|item|customer)\..+/.test(path)) {
            if (path) { window.alert('路径必须以 order./item./customer. 开头。'); }
            return;
        }
        var label = window.prompt('该列表头名:') || path;
        columns.push({ type: 'raw', path: path, label: label.slice(0, 64) });
        renderRows();
    });

    form.addEventListener('submit', function () {
        document.getElementById('columns-json').value = JSON.stringify(columns);
    });

    document.getElementById('preview-btn').addEventListener('click', function () {
        var body = new URLSearchParams();
        body.set('tenant', tenant);
        body.set('columns_json', JSON.stringify(columns));
        fetch('/import-export/export-templates/preview?tenant=' + encodeURIComponent(tenant), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (response) { return response.json(); }).then(function (data) {
            var area = document.getElementById('preview-area');
            if (!data.ok) {
                area.innerHTML = '<div class="setting-muted" style="color:#c0392b;">' + esc((data.errors || []).join(';')) + '</div>';
                return;
            }
            var html = '<table class="table"><thead><tr>';
            (data.headers || []).forEach(function (header) { html += '<th>' + esc(header) + '</th>'; });
            html += '</tr></thead><tbody>';
            (data.rows || []).forEach(function (row) {
                html += '<tr>';
                row.forEach(function (cell) { html += '<td>' + esc(cell) + '</td>'; });
                html += '</tr>';
            });
            html += (data.rows && data.rows.length ? '' : '<tr><td class="sub">当前筛选没有数据。</td></tr>') + '</tbody></table>';
            area.innerHTML = html;
        }).catch(function () {
            document.getElementById('preview-area').innerHTML = '<div class="setting-muted" style="color:#c0392b;">预览请求失败。</div>';
        });
    });

    renderRows();
})();
</script>
```

- [ ] **Step 3: 语法检查 + 手工冒烟**

```bash
cd php-saas && php -l app/Views/tenant/export_template_edit.php && php -l app/Views/tenant/import_export_non_excel.php
php -S 127.0.0.1:8090 -t public &
```
浏览器(或 curl 看 200/302)验证:
1. `/import-export/non-excel?tenant=erp` — 模板列表显示 5 个预置;
2. `/import-export/export-templates/edit?tenant=erp` — 编辑器可勾字段/加固定列/调序/预览;
3. 保存一个自定义模板 → 列表出现 → 导出按钮下载文件(xlsx 与 csv 各试一次);
4. `/import-export/platform-special/export?tenant=erp&variant=qoo10` — 旧参数仍能导出 CSV;
5. 删除自定义模板 → 列表消失。
完成后停掉服务器进程。

- [ ] **Step 4: 提交**

```bash
git add php-saas/app/Views/tenant/import_export_non_excel.php php-saas/app/Views/tenant/export_template_edit.php
git commit -m "feat: 发货单导出模板管理界面与列编辑器"
```

---

### Task 7: 文档同步

**Files:**
- Modify: `php-saas/docs/audit-2026-07-02-fix-tasks.md`(第 7 项)
- Modify: `php-saas/docs/specs/2026-07-03-custom-export-templates-design.md`(状态行)

- [ ] **Step 1: 更新审计任务清单第 7 项**

把第 7 项标题的 ☐ 改为 ✅,并在正文追加一段:

```markdown
**方案变更(2026-07-03,业务方决定)**:不再逐个复刻老 outexcel 变体,改为"字段注册表 + 租户自定义导出模板"统一机制(自选列/排序/固定值列/CSV·XLSX 嵌图),5 个已迁移模板转为预置模板。设计见 `docs/specs/2026-07-03-custom-export-templates-design.md`。老系统剩余变体(EDM/佐川 EDI 等)由各公司管理员用该机制自行配置,配不出来的再评估。

完成提交:`<实施完成后的 commit hash>`
```

- [ ] **Step 2: 更新设计文档状态**

`docs/specs/2026-07-03-custom-export-templates-design.md` 开头"状态"行改为:`状态:已实施(2026-07-0X,commit <hash>)`。

- [ ] **Step 3: 全量回归 + 提交**

```bash
cd php-saas && php tests/export_field_registry_test.php && php tests/export_template_service_test.php && php tests/platform_export_render_test.php && php tests/shipping_xlsx_workflow_test.php && php tests/purchase_xlsx_workflow_test.php && php tests/rakuten_order_mapping_test.php
git add php-saas/docs/audit-2026-07-02-fix-tasks.md php-saas/docs/specs/2026-07-03-custom-export-templates-design.md
git commit -m "docs: 审计清单第7项按自定义导出模板方案完成"
```
预期:6 个测试全部 OK/skipped(缺扩展时)。
