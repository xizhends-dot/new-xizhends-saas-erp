<?php
/** @var array<string, mixed> $summary */
$summary = $summary ?? ['filters' => [], 'platforms' => [], 'totals' => [], 'chart' => []];
$filters = (array) ($summary['filters'] ?? []);
$totals = (array) ($summary['totals'] ?? []);
$platforms = (array) ($summary['platforms'] ?? []);
$chart = (array) ($summary['chart'] ?? []);
$chartAmounts = array_map('floatval', (array) ($chart['amounts'] ?? []));
$chartMaxAmount = $chartAmounts !== [] ? max($chartAmounts) : 1.0;
$money = static fn (mixed $value): string => '￥' . number_format((float) $value, 0);
$percent = static fn (float $part, float $whole): string => $whole > 0 ? number_format($part / $whole * 100, 1) . '%' : '0%';
?>
<div class="page-head">
    <div>
        <h1>业绩汇总 <span class="sub">按店铺 / 平台</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/performance?tenant=<?= e($tenantKey) ?>">业绩面板</a>
        <a class="btn primary" href="/orders?tenant=<?= e($tenantKey) ?>&view=platform">平台订单</a>
    </div>
</div>

<form class="panel" method="get" action="/performance/summary">
    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
    <div class="panel-body filters">
        <label>开始日期 <input type="date" name="start_date" value="<?= e($filters['start_date'] ?? '') ?>"></label>
        <label>结束日期 <input type="date" name="end_date" value="<?= e($filters['end_date'] ?? '') ?>"></label>
        <button class="btn primary" type="submit">查询</button>
    </div>
</form>

<section class="grid stats">
    <div class="stat"><div class="stat-label">总订单数</div><div class="stat-value"><?= e($totals['order_count'] ?? 0) ?></div><div class="stat-sub"><?= e($totals['platform_count'] ?? 0) ?> 个平台</div></div>
    <div class="stat"><div class="stat-label">总销售额</div><div class="stat-value"><?= e($money($totals['total_amount'] ?? 0)) ?></div><div class="stat-sub"><?= e($totals['store_count'] ?? 0) ?> 个店铺</div></div>
    <div class="stat"><div class="stat-label">店均订单</div><div class="stat-value"><?= e(($totals['store_count'] ?? 0) > 0 ? round((int) ($totals['order_count'] ?? 0) / (int) $totals['store_count'], 1) : 0) ?></div><div class="stat-sub">按当前筛选</div></div>
    <div class="stat"><div class="stat-label">客单价</div><div class="stat-value"><?= e(($totals['order_count'] ?? 0) > 0 ? $money((float) ($totals['total_amount'] ?? 0) / (int) $totals['order_count']) : $money(0)) ?></div><div class="stat-sub">销售额 / 订单数</div></div>
</section>

<section class="grid two-col">
    <?php foreach ($platforms as $platform): ?>
        <?php $platformTotal = (float) ($platform['total_amount'] ?? 0); ?>
        <div class="panel">
            <div class="panel-head">
                <span><?= e($platform['name'] ?? $platform['code'] ?? '') ?></span>
                <span class="sub"><?= e($platform['order_count'] ?? 0) ?> 单 / <?= e($money($platformTotal)) ?></span>
            </div>
            <div class="panel-body">
                <table class="table">
                    <thead><tr><th>店铺</th><th>订单数</th><th>销售额</th><th>销售占比</th></tr></thead>
                    <tbody>
                    <?php foreach ((array) ($platform['stores'] ?? []) as $store): ?>
                        <tr>
                            <td><?= e($store['store'] ?? '') ?></td>
                            <td><?= e($store['order_count'] ?? 0) ?></td>
                            <td><?= e($money($store['total_amount'] ?? 0)) ?></td>
                            <td><?= e($percent((float) ($store['total_amount'] ?? 0), (float) ($totals['total_amount'] ?? 0))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<div class="panel">
    <div class="panel-head"><span>平台汇总</span><span class="sub">图表数据可供前端 AJAX 复用</span></div>
    <div class="panel-body">
        <table class="table">
            <thead><tr><th>平台</th><th>订单数</th><th>销售额</th><th>销售占比</th></tr></thead>
            <tbody>
            <?php foreach ($platforms as $platform): ?>
                <tr>
                    <td><?= e($platform['name'] ?? '') ?></td>
                    <td><?= e($platform['order_count'] ?? 0) ?></td>
                    <td><?= e($money($platform['total_amount'] ?? 0)) ?></td>
                    <td><?= e($percent((float) ($platform['total_amount'] ?? 0), (float) ($totals['total_amount'] ?? 0))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="mini-chart" style="display:grid;gap:8px;margin-top:12px;">
            <?php foreach ((array) ($chart['labels'] ?? []) as $index => $label): ?>
                <?php
                $amount = (float) (($chart['amounts'] ?? [])[$index] ?? 0);
                $width = $chartMaxAmount > 0 ? min(100, round($amount / $chartMaxAmount * 100, 1)) : 0;
                ?>
                <div style="display:grid;grid-template-columns:120px 1fr 110px;gap:10px;align-items:center;">
                    <span><?= e($label) ?></span>
                    <span style="display:block;height:10px;background:#e5e7eb;"><span style="display:block;height:10px;width:<?= e($width) ?>%;background:#2563eb;"></span></span>
                    <span><?= e($money($amount)) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
