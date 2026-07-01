<?php
/** @var array<string, mixed> $dashboard */
$dashboard = $dashboard ?? ['filters' => [], 'today' => [], 'yesterday' => [], 'month' => [], 'range' => [], 'daily' => [], 'platforms' => []];
$filters = (array) ($dashboard['filters'] ?? []);
$daily = (array) (($dashboard['daily'] ?? [])['rows'] ?? []);
$dailySummary = (array) (($dashboard['daily'] ?? [])['summary'] ?? []);
$platformOptions = (array) (($dashboard['daily'] ?? [])['platforms'] ?? []);
$money = static fn (mixed $value): string => '￥' . number_format((float) $value, 0);
?>
<div class="page-head">
    <div>
        <h1>业绩面板 <span class="sub">日统计后端数据</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/performance/summary?tenant=<?= e($tenantKey) ?>">汇总统计</a>
        <a class="btn primary" href="/performance/products?tenant=<?= e($tenantKey) ?>">商品分析</a>
    </div>
</div>

<form class="panel" method="get" action="/performance">
    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
    <div class="panel-body filters">
        <label>平台
            <select name="platform">
                <option value="">全部平台</option>
                <?php foreach ($platformOptions as $code => $name): ?>
                    <option value="<?= e($code) ?>" <?= (string) ($filters['platform'] ?? '') === (string) $code ? 'selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>开始日期 <input type="date" name="start_date" value="<?= e($filters['start_date'] ?? '') ?>"></label>
        <label>结束日期 <input type="date" name="end_date" value="<?= e($filters['end_date'] ?? '') ?>"></label>
        <button class="btn primary" type="submit">查询</button>
    </div>
</form>

<section class="grid stats">
    <div class="stat"><div class="stat-label">今日订单</div><div class="stat-value"><?= e($dashboard['today']['order_count'] ?? 0) ?></div><div class="stat-sub"><?= e($money($dashboard['today']['total_amount'] ?? 0)) ?></div></div>
    <div class="stat"><div class="stat-label">昨日订单</div><div class="stat-value"><?= e($dashboard['yesterday']['order_count'] ?? 0) ?></div><div class="stat-sub"><?= e($money($dashboard['yesterday']['total_amount'] ?? 0)) ?></div></div>
    <div class="stat"><div class="stat-label">本月订单</div><div class="stat-value"><?= e($dashboard['month']['order_count'] ?? 0) ?></div><div class="stat-sub"><?= e($money($dashboard['month']['total_amount'] ?? 0)) ?></div></div>
    <div class="stat"><div class="stat-label">筛选区间</div><div class="stat-value"><?= e($dashboard['range']['order_count'] ?? 0) ?></div><div class="stat-sub"><?= e($money($dashboard['range']['total_amount'] ?? 0)) ?></div></div>
</section>

<section class="grid two-col">
    <div class="panel">
        <div class="panel-head"><span>每日店铺业绩</span><span class="sub"><?= e($dailySummary['total_orders'] ?? 0) ?> 单 / <?= e($money($dailySummary['total_amount'] ?? 0)) ?></span></div>
        <div class="panel-body">
            <table class="table">
                <thead><tr><th>日期</th><th>平台</th><th>店铺</th><th>订单数</th><th>销售额</th></tr></thead>
                <tbody>
                <?php foreach ($daily as $row): ?>
                    <tr>
                        <td><?= e($row['date'] ?? '') ?></td>
                        <td><?= e($row['platform'] ?? '') ?></td>
                        <td><?= e($row['store'] ?? '') ?></td>
                        <td><?= e($row['order_count'] ?? 0) ?></td>
                        <td><?= e($money($row['total_amount'] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="panel">
        <div class="panel-head"><span>平台区间表现</span><span class="sub">按销售额排序</span></div>
        <div class="panel-body">
            <table class="table">
                <thead><tr><th>平台</th><th>订单数</th><th>销售额</th></tr></thead>
                <tbody>
                <?php foreach ((array) ($dashboard['platforms'] ?? []) as $platform): ?>
                    <tr>
                        <td><?= e($platform['name'] ?? '') ?></td>
                        <td><?= e($platform['order_count'] ?? 0) ?></td>
                        <td><?= e($money($platform['total_amount'] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
