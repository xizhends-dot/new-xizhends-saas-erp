<?php
$logisticsMeta = match ($type) {
    '1688' => [
        'title' => '1688物流查询日志',
        'return_path' => '/logistics/1688',
        'notice' => '已接入 old/plugins/1688api 与 cron/update_1688_logistics.php 的 1688 物流查询规则。',
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
        'notice' => '已接入 old/plugins/express-showapi 的国内快递查询规则；ShowAPI 失败或无轨迹时，可由超管开启百度物流备用查询。',
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
        'notice' => '已接入 old/plugins/jpshipinfo、sagawa-shipinfo 与 cron/update_jpship_logistics.php 的日本物流查询规则。',
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
?>
<div class="page-head">
    <div>
        <h1><?= e($logisticsMeta['title']) ?> <span class="sub">旧系统物流任务迁移入口</span></h1>
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

<div class="panel">
    <div class="panel-head"><span>查询日志</span><span class="sub"><?= e(count($rows)) ?> 条</span></div>
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
