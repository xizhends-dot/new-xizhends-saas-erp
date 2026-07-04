<div class="page-head">
    <div>
        <h1>首页仪表盘 <span class="sub"><?= e($tenant['company_name']) ?></span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/features?tenant=<?= e($tenantKey) ?>">功能工作台</a>
        <a class="btn" href="/orders?tenant=<?= e($tenantKey) ?>&view=platform">平台订单</a>
        <a class="btn primary" href="/orders?tenant=<?= e($tenantKey) ?>&view=purchase">采购队列</a>
    </div>
</div>

<?php
$priceDefaults = is_array($priceDefaults ?? null) ? $priceDefaults : [];
$formatNumber = static fn (mixed $value, int $decimals = 2): string => number_format((float) $value, $decimals, '.', '');
?>

<section class="grid stats">
    <div class="stat"><div class="stat-label">待处理订单</div><div class="stat-value"><?= e($stats['pending_orders']) ?></div><div class="stat-sub">平台订单入口</div></div>
    <div class="stat"><div class="stat-label">国内采购子商品</div><div class="stat-value"><?= e($stats['purchase_items']) ?></div><div class="stat-sub">进入采购订单</div></div>
    <div class="stat"><div class="stat-label">日本仓发货</div><div class="stat-value"><?= e($stats['jp_stock_items']) ?></div><div class="stat-sub">进入日本仓队列</div></div>
    <div class="stat"><div class="stat-label">待判定货源</div><div class="stat-value"><?= e($stats['pending_source_items']) ?></div><div class="stat-sub">需要客服改判</div></div>
</section>

<section class="panel dashboard-rate-panel" style="margin-top:14px;">
    <div class="panel-head"><span>实时汇率</span><span class="sub">订单核价默认口径</span></div>
    <div class="dashboard-rate-grid">
        <div class="dashboard-rate-main">
            <span>当前汇率</span>
            <strong><?= e($formatNumber($priceDefaults['exchange_rate'] ?? 0.048, 4)) ?></strong>
            <em><?= e((string) ($priceDefaults['exchange_rate_source'] ?? '固定汇率')) ?></em>
        </div>
        <div class="dashboard-rate-meta"><span>默认运费</span><strong>￥<?= e($formatNumber($priceDefaults['shipping'] ?? 40, 2)) ?></strong></div>
        <div class="dashboard-rate-meta"><span>默认扣点</span><strong><?= e($formatNumber($priceDefaults['deduction'] ?? 70, 0)) ?>%</strong></div>
        <a class="btn" href="/settings?tenant=<?= e($tenantKey) ?>#profit">调整利润参数</a>
    </div>
</section>

<section class="panel feature-section" style="margin-top:14px;">
    <div class="panel-head"><span>常用功能</span><span class="sub">来自旧系统的重构入口</span></div>
    <div class="feature-grid">
        <?php foreach (array_slice(array_merge(...array_values($groups)), 0, 8) as $feature): ?>
            <a class="feature-card" href="<?= e($feature['href']) ?>">
                <div class="feature-title">
                    <span><?= e($feature['title']) ?></span>
                    <span class="tag <?= $feature['status'] === '已可用' ? 'green' : 'blue' ?>"><?= e($feature['status']) ?></span>
                </div>
                <p><?= e($feature['desc']) ?></p>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="grid two-col">
    <div class="panel">
        <div class="panel-head"><span>最近订单</span><span class="sub">今日金额 ￥<?= e(number_format((float) $stats['today_amount'], 0)) ?></span></div>
        <div class="panel-body">
            <table class="table">
                <thead><tr><th>订单号</th><th>店铺</th><th>状态</th><th>金额</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($stats['recent_orders'] as $order): ?>
                    <tr>
                        <td class="order-id"><?= e($order['platform_order_id']) ?></td>
                        <td><?= e($order['store']) ?></td>
                        <td><span class="tag gray"><?= e($order['status']) ?></span></td>
                        <td>￥<?= e(number_format((float) $order['total'], 0)) ?></td>
                        <td><a class="btn" href="/orders?tenant=<?= e($tenantKey) ?>&view=platform&q=<?= e($order['platform_order_id']) ?>">查看</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head"><span>公告</span><span class="sub">系统 + 公司</span></div>
        <div class="panel-body">
            <?php foreach (($tenantNotices ?? []) as $notice): ?>
                <div class="announce">
                    <div class="announce-title"><span class="tag green">公司公告</span> <?= e($notice['title'] ?? '') ?></div>
                    <div class="announce-meta"><?= e($notice['published_at'] ?? '') ?> · <?= e($notice['author_name'] ?? '') ?></div>
                    <div><?= e($notice['body'] ?? '') ?></div>
                </div>
            <?php endforeach; ?>
            <?php foreach (array_slice($announcements, 0, 3) as $announcement): ?>
                <div class="announce">
                    <div class="announce-title"><span class="tag blue"><?= e($announcement['kind']) ?></span> <?= e($announcement['title']) ?></div>
                    <div class="announce-meta"><?= e($announcement['scope']) ?> · <?= e($announcement['date']) ?></div>
                    <div><?= e($announcement['body']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
