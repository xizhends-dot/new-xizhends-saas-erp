<div class="page-head">
    <div>
        <h1>采购统计 <span class="sub">参考 caigou_status / caigou_stats</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn primary" href="/orders?tenant=<?= e($tenantKey) ?>&view=purchase">进入采购订单</a>
    </div>
</div>

<section class="grid two-col">
    <div class="panel">
        <div class="panel-head"><span>采购人统计</span><span class="sub">共 <?= e($stats['total']) ?> 件</span></div>
        <div class="panel-body">
            <table class="table">
                <thead><tr><th>采购人</th><th>子商品数</th><th>占比</th></tr></thead>
                <tbody>
                <?php foreach ($stats['buyers'] as $buyer => $count): ?>
                    <tr><td><?= e($buyer) ?></td><td><?= e($count) ?></td><td><?= e($stats['total'] ? round($count / $stats['total'] * 100, 1) : 0) ?>%</td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="panel">
        <div class="panel-head"><span>采购状态分布</span><span class="sub">按 order_items.purchase_status</span></div>
        <div class="panel-body">
            <table class="table">
                <thead><tr><th>状态</th><th>数量</th></tr></thead>
                <tbody>
                <?php foreach ($stats['statuses'] as $status => $count): ?>
                    <tr><td><span class="tag gray"><?= e($status) ?></span></td><td><?= e($count) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
