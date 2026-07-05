<?php
/** @var array<string, mixed> $stats */
$stats = $stats ?? ['filters' => [], 'platforms' => [], 'platformOptions' => [], 'chart' => []];
$filters = (array) ($stats['filters'] ?? []);
$platforms = (array) ($stats['platforms'] ?? []);
$platformOptions = (array) ($stats['platformOptions'] ?? []);
$changeTag = static function (int $diff): string {
    if ($diff > 0) {
        return '+' . number_format($diff) . ' ↑';
    }
    if ($diff < 0) {
        return number_format($diff) . ' ↓';
    }
    return '0';
};
?>
<div class="page-head">
    <div>
        <h1>采购状态每日统计 <span class="sub">状态分布 / 日环比</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/stats/purchase?tenant=<?= e($tenantKey) ?>">采购业绩统计</a>
        <a class="btn primary" href="/orders?tenant=<?= e($tenantKey) ?>&view=purchase">采购订单</a>
    </div>
</div>

<form class="panel" method="get" action="/stats/purchase/status-daily">
    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
    <div class="panel-body filters">
        <label>统计日期 <input type="date" name="date" value="<?= e($filters['date'] ?? '') ?>"></label>
        <label>平台
            <select name="platform">
                <option value="">全部平台</option>
                <?php foreach ($platformOptions as $code => $name): ?>
                    <option value="<?= e($code) ?>" <?= (string) ($filters['platform'] ?? '') === (string) $code ? 'selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><input type="checkbox" name="compare" value="1" <?= !empty($filters['compare']) ? 'checked' : '' ?>> 显示日环比</label>
        <button class="btn primary" type="submit">查询</button>
    </div>
</form>

<section class="grid stats">
    <div class="stat"><div class="stat-label">当天采购子商品</div><div class="stat-value"><?= e($stats['total'] ?? 0) ?></div><div class="stat-sub"><?= e($filters['date'] ?? '') ?></div></div>
    <div class="stat"><div class="stat-label">前一日</div><div class="stat-value"><?= e($stats['previous_total'] ?? 0) ?></div><div class="stat-sub"><?= e($stats['previous_date'] ?? '') ?></div></div>
    <div class="stat"><div class="stat-label">日环比</div><div class="stat-value"><?= e($changeTag((int) ($stats['diff'] ?? 0))) ?></div><div class="stat-sub">按采购日期</div></div>
    <div class="stat"><div class="stat-label">覆盖平台</div><div class="stat-value"><?= e(count($platforms)) ?></div><div class="stat-sub">当前筛选</div></div>
</section>

<section class="grid two-col">
    <?php foreach ($platforms as $platform): ?>
        <div class="panel">
            <div class="panel-head">
                <span><?= e($platform['name'] ?? '') ?></span>
                <span class="sub"><?= e($platform['total'] ?? 0) ?> 条 / <?= e($changeTag((int) ($platform['diff'] ?? 0))) ?></span>
            </div>
            <div class="panel-body">
                <table class="table">
                    <thead><tr><th>状态</th><th>数量</th><th>占比</th><th>前一日</th><th>变化</th></tr></thead>
                    <tbody>
                    <?php foreach ((array) ($platform['statuses'] ?? []) as $status): ?>
                        <tr>
                            <td><span class="tag <?= !empty($status['is_defined']) ? 'gray' : 'yellow' ?>"><?= e($status['status'] ?? '') ?></span></td>
                            <td><?= e($status['count'] ?? 0) ?></td>
                            <td><?= e($status['percent'] ?? 0) ?>%</td>
                            <td><?= e($status['previous_count'] ?? 0) ?></td>
                            <td><span class="tag <?= (int) ($status['diff'] ?? 0) >= 0 ? 'green' : 'red' ?>"><?= e($changeTag((int) ($status['diff'] ?? 0))) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
</section>
