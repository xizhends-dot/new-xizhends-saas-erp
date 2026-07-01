<?php
$report = is_array($report ?? null) ? $report : [];
$summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
$filters = is_array($report['filters'] ?? null) ? $report['filters'] : [];
$rows = is_array($report['rows'] ?? null) ? $report['rows'] : [];
$duplicates = is_array($report['duplicates'] ?? null) ? $report['duplicates'] : ['domestic' => [], 'jp' => []];
$platformNames = is_array($platformNames ?? null) ? $platformNames : [];
$stores = is_array($stores ?? null) ? $stores : [];
$statusOptions = [
    'needs_check' => '仅异常/待核对',
    'all' => '全部',
    'empty' => '空运单',
    'invalid' => '无效运单',
    'duplicate' => '重复运单',
    'pending' => '待核对',
    'ok' => '正常',
];
$scopeOptions = [
    'all' => '国内 + 国际',
    'domestic' => '仅国内',
    'jp' => '仅日本国际',
];
$selected = static fn (string $key, string $value): string => (string) ($filters[$key] ?? '') === $value ? 'selected' : '';
$inputValue = static fn (string $key): string => (string) ($filters[$key] ?? '');
$storeNames = array_values(array_unique(array_filter(array_map(
    static fn (array $store): string => (string) (($store['name'] ?? '') ?: ($store['short'] ?? '')),
    $stores
), static fn (string $name): bool => $name !== '')));
?>

<div class="page-head">
    <div>
        <h1>运单核对 <span class="sub">国内运单 / 日本快递跳转</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/logistics/jp?tenant=<?= e($tenantKey) ?>">日本物流</a>
    </div>
</div>

<form class="order-filter" method="get" action="/logistics/waybill-check">
    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
    <label>
        <span>开始日期</span>
        <input type="date" name="from" value="<?= e($inputValue('from')) ?>">
    </label>
    <label>
        <span>结束日期</span>
        <input type="date" name="to" value="<?= e($inputValue('to')) ?>">
    </label>
    <label>
        <span>平台</span>
        <select name="platform">
            <option value="">全部平台</option>
            <?php foreach ($platformNames as $code => $name): ?>
                <option value="<?= e($code) ?>" <?= $selected('platform', (string) $code) ?>><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span>店铺</span>
        <select name="store">
            <option value="">全部店铺</option>
            <?php foreach ($storeNames as $storeName): ?>
                <option value="<?= e($storeName) ?>" <?= $selected('store', $storeName) ?>><?= e($storeName) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span>范围</span>
        <select name="scope">
            <?php foreach ($scopeOptions as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $selected('scope', $value) ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span>状态</span>
        <select name="status">
            <?php foreach ($statusOptions as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $selected('status', $value) ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span>关键词</span>
        <input name="keyword" value="<?= e($inputValue('keyword')) ?>" placeholder="订单号 / 商品 / 运单">
    </label>
    <label>
        <span>显示上限</span>
        <input type="number" min="0" max="2000" name="limit" value="<?= e($inputValue('limit')) ?>">
    </label>
    <button class="btn primary" type="submit">核对</button>
</form>

<div class="grid stats">
    <div class="stat"><div class="stat-label">扫描</div><div class="stat-value"><?= e($summary['scanned'] ?? 0) ?></div></div>
    <div class="stat"><div class="stat-label">展示</div><div class="stat-value"><?= e($summary['shown'] ?? 0) ?></div></div>
    <div class="stat"><div class="stat-label">空运单</div><div class="stat-value"><?= e($summary['empty'] ?? 0) ?></div></div>
    <div class="stat"><div class="stat-label">无效</div><div class="stat-value"><?= e($summary['invalid'] ?? 0) ?></div></div>
    <div class="stat"><div class="stat-label">重复</div><div class="stat-value"><?= e($summary['duplicate'] ?? 0) ?></div></div>
    <div class="stat"><div class="stat-label">待核对</div><div class="stat-value"><?= e($summary['pending'] ?? 0) ?></div></div>
    <div class="stat"><div class="stat-label">日本跳转</div><div class="stat-value"><?= e($summary['jp_jumpable'] ?? 0) ?></div></div>
    <div class="stat"><div class="stat-label">正常</div><div class="stat-value"><?= e($summary['ok'] ?? 0) ?></div></div>
</div>

<?php if (!empty($duplicates['domestic']) || !empty($duplicates['jp'])): ?>
    <div class="notice slim">
        国内重复组 <?= e(count($duplicates['domestic'] ?? [])) ?> 个，日本国际重复组 <?= e(count($duplicates['jp'] ?? [])) ?> 个；同一订单内多商品共用运单不会计为跨订单重复。
    </div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head"><span>核对结果</span><span class="sub"><?= e(count($rows)) ?> 条</span></div>
    <div class="panel-body">
        <table class="table">
            <thead>
            <tr>
                <th>状态</th>
                <th>订单</th>
                <th>商品</th>
                <th>国内运单</th>
                <th>国际运单</th>
                <th>物流状态</th>
                <th>问题</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="muted">没有符合条件的运单记录。</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><span class="tag <?= e(($row['severity'] ?? '') === 'danger' ? 'red' : (($row['severity'] ?? '') === 'warning' ? 'gray' : 'blue')) ?>"><?= e($row['status_label'] ?? '') ?></span></td>
                    <td>
                        <div class="order-id"><?= e($row['platform_order_id'] ?? '') ?></div>
                        <div class="sub"><?= e(($row['store'] ?? '') . ' · ' . ($row['order_date'] ?? '')) ?></div>
                    </td>
                    <td>
                        <div><?= e($row['item_code'] ?? '') ?></div>
                        <div class="sub"><?= e($row['title'] ?? '') ?></div>
                    </td>
                    <td>
                        <div><?= e($row['domestic_number'] !== '' ? $row['domestic_number'] : '-') ?></div>
                        <div class="sub"><?= e($row['domestic_company'] ?? '') ?></div>
                    </td>
                    <td>
                        <?php if (($row['jp_tracking_url'] ?? '') !== ''): ?>
                            <a class="order-id" href="<?= e($row['jp_tracking_url']) ?>" target="_blank" rel="noopener"><?= e($row['jp_number']) ?></a>
                        <?php else: ?>
                            <span><?= e(($row['jp_number'] ?? '') !== '' ? $row['jp_number'] : '-') ?></span>
                        <?php endif; ?>
                        <div class="sub"><?= e($row['jp_carrier'] ?? '') ?></div>
                    </td>
                    <td><?= e(($row['logistics'] ?? '') !== '' ? $row['logistics'] : '-') ?></td>
                    <td><?= e(implode(' / ', (array) ($row['issue_labels'] ?? [])) ?: '正常') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
