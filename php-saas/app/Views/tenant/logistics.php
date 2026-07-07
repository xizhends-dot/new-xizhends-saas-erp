<?php
$logisticsMeta = match ($type) {
    '1688' => [
        'title' => '1688物流查询日志',
        'return_path' => '/logistics/1688',
        'notice' => '用于查询 1688 采购订单的国内物流轨迹，记录订单号、1688 单号、处理状态与返回说明。',
        'columns' => [
            ['key' => 'date', 'label' => '日期'],
            ['key' => 'user_name', 'label' => '用户'],
            ['key' => 'platform_name', 'label' => '平台'],
            ['key' => 'sys_orderid', 'label' => '订单号'],
            ['key' => 'orderid', 'label' => '1688单号'],
            ['key' => 'message', 'label' => '说明'],
            ['key' => 'status_label', 'label' => '状态'],
        ],
    ],
    'express' => [
        'title' => 'Showapi物流查询日志',
        'return_path' => '/logistics/express',
        'notice' => '用于查询淘宝、拼多多等国内快递轨迹，记录运单号、处理状态、接口说明与相关链接。',
        'columns' => [
            ['key' => 'date', 'label' => '日期'],
            ['key' => 'user_name', 'label' => '用户'],
            ['key' => 'orderid', 'label' => '单号'],
            ['key' => 'message', 'label' => '说明'],
            ['key' => 'related_url', 'label' => '相关链接'],
            ['key' => 'status_label', 'label' => '状态'],
        ],
    ],
    default => [
        'title' => '国际物流查询日志',
        'return_path' => '/logistics/jp',
        'notice' => '用于查询国际运单和日本配送轨迹，记录平台订单、国际运单号、处理状态与查询结果。',
        'columns' => [
            ['key' => 'date', 'label' => '日期'],
            ['key' => 'platform_name', 'label' => '平台'],
            ['key' => 'user_name', 'label' => '用户'],
            ['key' => 'real_orderid', 'label' => '订单号'],
            ['key' => 'orderid', 'label' => '国际运单号'],
            ['key' => 'message', 'label' => '说明'],
            ['key' => 'related_url', 'label' => '相关链接'],
            ['key' => 'status_label', 'label' => '状态'],
        ],
    ],
};
$columns = $logisticsMeta['columns'];
$filters = [
    'order_num' => trim((string) ($_GET['order_num'] ?? '')),
    'date' => trim((string) ($_GET['date'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'keyword' => trim((string) ($_GET['keyword'] ?? '')),
];
if ($filters['date'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date']) !== 1) {
    $filters['date'] = '';
}
if (!in_array($filters['status'], ['', '0', '1'], true)) {
    $filters['status'] = '';
}
$textContains = static function (array $row, string $needle, array $keys): bool {
    if ($needle === '') {
        return true;
    }
    foreach ($keys as $key) {
        if (stripos((string) ($row[$key] ?? ''), $needle) !== false) {
            return true;
        }
    }

    return false;
};
$rows = array_values(array_filter($rows, static function (array $row) use ($type, $filters, $textContains): bool {
    if ($filters['date'] !== '' && substr((string) ($row['date'] ?? ''), 0, 10) !== $filters['date']) {
        return false;
    }
    if ($filters['status'] !== '' && (string) ((int) ($row['status_code'] ?? 0)) !== $filters['status']) {
        return false;
    }
    if ($type === '1688' && !$textContains($row, $filters['order_num'], ['sys_orderid', 'real_orderid', 'orderid'])) {
        return false;
    }
    if ($type === 'jp' && !$textContains($row, $filters['keyword'], ['real_orderid', 'sys_orderid', 'orderid'])) {
        return false;
    }

    return true;
}));
$successCount = count(array_filter($rows, static fn (array $row): bool => ((int) ($row['status_code'] ?? 0)) === 1));
$failureCount = count($rows) - $successCount;
?>
<style>
.logistics-filter {
    grid-template-columns: repeat(3, minmax(150px, 1fr)) repeat(2, minmax(82px, 100px));
    align-items: start;
}
.logistics-filter .fg {
    min-width: 0;
}
.logistics-filter .btn {
    width: 100%;
}
.logistics-filter .logistics-filter-action {
    grid-column: 4;
}
.logistics-filter .logistics-filter-reset {
    grid-column: 5;
}
@media (max-width: 720px) {
    .logistics-filter {
        grid-template-columns: 1fr;
    }
    .logistics-filter .logistics-filter-action,
    .logistics-filter .logistics-filter-reset {
        grid-column: auto;
    }
}
</style>
<div class="page-head">
    <div>
        <h1><?= e($logisticsMeta['title']) ?> <span class="sub">物流接口查询记录</span></h1>
    </div>
    <div class="head-actions">
        <form method="post" action="/orders/logistics/update">
                <?= csrf_field() ?>
            <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <input type="hidden" name="return" value="<?= e($logisticsMeta['return_path'] . '?tenant=' . rawurlencode((string) $tenantKey)) ?>">
            <button class="btn primary" type="submit">立即同步</button>
        </form>
        <a class="btn" href="/jobs?tenant=<?= e($tenantKey) ?>">查看任务</a>
    </div>
</div>

<?php if (trim((string) ($_GET['message'] ?? '')) !== ''): ?>
    <div class="notice slim"><?= e($_GET['message']) ?></div>
<?php endif; ?>

<div class="notice">
    <?= e($logisticsMeta['notice']) ?>
</div>

<form class="filter logistics-filter" method="get" action="<?= e($logisticsMeta['return_path']) ?>">
    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
    <?php if ($type === '1688'): ?>
        <label class="fg">
            <span>订单号</span>
            <input name="order_num" value="<?= e($filters['order_num']) ?>" placeholder="订单号 / 1688单号">
        </label>
    <?php endif; ?>
    <?php if ($type === 'jp'): ?>
        <label class="fg">
            <span>运单号/订单号</span>
            <input name="keyword" value="<?= e($filters['keyword']) ?>" placeholder="输入运单号或订单号">
        </label>
    <?php endif; ?>
    <label class="fg">
        <span>日期</span>
        <input type="date" name="date" value="<?= e($filters['date']) ?>">
    </label>
    <label class="fg">
        <span>状态</span>
        <select name="status">
            <option value="" <?= $filters['status'] === '' ? 'selected' : '' ?>>--所有--</option>
            <option value="0" <?= $filters['status'] === '0' ? 'selected' : '' ?>>失败</option>
            <option value="1" <?= $filters['status'] === '1' ? 'selected' : '' ?>>成功</option>
        </select>
    </label>
    <div class="fg logistics-filter-action">
        <span>操作</span>
        <button class="btn primary" type="submit">查询</button>
    </div>
    <div class="fg logistics-filter-reset">
        <span>重置</span>
        <a class="btn" href="<?= e($logisticsMeta['return_path'] . '?tenant=' . rawurlencode((string) $tenantKey)) ?>">重置</a>
    </div>
</form>

<div class="panel">
    <div class="panel-head"><span>查询日志</span><span class="sub">处理结果：成功 <?= e($successCount) ?>　失败 <?= e($failureCount) ?>　当前筛选 <?= e(count($rows)) ?> 条</span></div>
    <div class="panel-body">
        <table class="table">
            <thead>
                <tr>
                    <?php foreach ($columns as $column): ?>
                        <th><?= e($column['label']) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach ($columns as $column): ?>
                        <?php $key = (string) $column['key']; ?>
                        <?php if ($key === 'status_label'): ?>
                            <td><span class="tag <?= ((int) ($row['status_code'] ?? 0)) === 1 ? 'green' : 'red' ?>"><?= e($row[$key] ?? '') ?></span></td>
                        <?php elseif ($key === 'related_url'): ?>
                            <?php $url = trim((string) ($row[$key] ?? '')); ?>
                            <td>
                                <?php if ($url !== ''): ?>
                                    <a class="order-id" href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e($url) ?></a>
                                <?php endif; ?>
                            </td>
                        <?php elseif (in_array($key, ['sys_orderid', 'real_orderid', 'orderid'], true)): ?>
                            <td class="order-id"><?= e($row[$key] ?? '') ?></td>
                        <?php else: ?>
                            <td><?= e($row[$key] ?? '') ?></td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="<?= e(count($columns)) ?>" class="sub">暂无查询日志</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
