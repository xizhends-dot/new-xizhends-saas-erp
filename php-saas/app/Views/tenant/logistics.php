<div class="page-head">
    <div>
        <h1><?= e($type === '1688' ? '1688 物流' : '日本物流') ?> <span class="sub">旧系统物流任务迁移入口</span></h1>
    </div>
    <div class="head-actions">
        <form method="post" action="/orders/logistics/update">
            <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <input type="hidden" name="return" value="<?= e('/logistics/' . ($type === '1688' ? '1688' : 'jp') . '?tenant=' . rawurlencode((string) $tenantKey)) ?>">
            <button class="btn primary" type="submit">立即同步</button>
        </form>
        <a class="btn" href="/jobs?tenant=<?= e($tenantKey) ?>">查看任务</a>
    </div>
</div>

<?php if (trim((string) ($_GET['message'] ?? '')) !== ''): ?>
    <div class="notice slim"><?= e($_GET['message']) ?></div>
<?php endif; ?>

<div class="notice">
    <?= e($type === '1688'
        ? '已接入 old/plugins/1688api 与 cron/update_1688_logistics.php 的 1688 物流查询规则。'
        : '已接入 old/plugins/jpshipinfo、sagawa-shipinfo 与 cron/update_jpship_logistics.php 的日本物流查询规则。') ?>
</div>

<div class="panel">
    <div class="panel-head"><span>物流明细</span><span class="sub"><?= e(count($rows)) ?> 条</span></div>
    <div class="panel-body">
        <table class="table">
            <thead><tr><th>订单号</th><th>商品</th><th>运单 / 1688号</th><th>承运商</th><th>状态</th><th>更新时间</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="order-id"><?= e($row['order_no']) ?></td>
                    <td><?= e($row['item']) ?></td>
                    <td><?= e($row['tracking_no']) ?></td>
                    <td><?= e($row['carrier']) ?></td>
                    <td><span class="tag blue"><?= e($row['status']) ?></span></td>
                    <td><?= e($row['updated_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
