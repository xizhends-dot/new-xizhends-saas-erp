<?php
/** @var array<string, mixed> $stats */
$stats = $stats ?? ['filters' => [], 'buyers' => [], 'statuses' => [], 'daily' => [], 'platforms' => [], 'trace_rows' => [], 'platformOptions' => []];
$filters = (array) ($stats['filters'] ?? []);
$totals = (array) ($stats['totals'] ?? []);
$money = static fn (mixed $value): string => '￥' . number_format((float) $value, 0);
?>
<div class="page-head">
    <div>
        <h1>采购统计 <span class="sub">日视图 / 用户追溯</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/stats/purchase/status-daily?tenant=<?= e($tenantKey) ?>">采购状态日报</a>
        <a class="btn primary" href="/orders?tenant=<?= e($tenantKey) ?>&view=purchase">采购订单</a>
    </div>
</div>

<form class="panel" method="get" action="/stats/purchase">
    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
    <div class="panel-body filters">
        <label>平台
            <select name="platform">
                <option value="">全部平台</option>
                <?php foreach ((array) ($stats['platformOptions'] ?? []) as $code => $name): ?>
                    <option value="<?= e($code) ?>" <?= (string) ($filters['platform'] ?? '') === (string) $code ? 'selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>采购人 <input type="text" name="buyer" value="<?= e($filters['buyer'] ?? '') ?>" placeholder="未分配 / 姓名"></label>
        <label>状态 <input type="text" name="status" value="<?= e($filters['status'] ?? '') ?>" placeholder="采购状态"></label>
        <label>开始日期 <input type="date" name="start_date" value="<?= e($filters['start_date'] ?? '') ?>"></label>
        <label>结束日期 <input type="date" name="end_date" value="<?= e($filters['end_date'] ?? '') ?>"></label>
        <button class="btn primary" type="submit">查询</button>
    </div>
</form>

<section class="grid stats">
    <div class="stat"><div class="stat-label">采购子商品</div><div class="stat-value"><?= e($totals['item_count'] ?? 0) ?></div><div class="stat-sub"><?= e($totals['quantity'] ?? 0) ?> 件</div></div>
    <div class="stat"><div class="stat-label">采购金额</div><div class="stat-value"><?= e($money($totals['amount'] ?? 0)) ?></div><div class="stat-sub">按成本字段</div></div>
    <div class="stat"><div class="stat-label">采购人数</div><div class="stat-value"><?= e($totals['buyer_count'] ?? 0) ?></div><div class="stat-sub">含未分配</div></div>
    <div class="stat"><div class="stat-label">状态数</div><div class="stat-value"><?= e($totals['status_count'] ?? 0) ?></div><div class="stat-sub">当前筛选</div></div>
</section>

<section class="grid two-col">
    <div class="panel">
        <div class="panel-head"><span>日视图</span><span class="sub">按采购时间 / 订单时间兜底</span></div>
        <div class="panel-body">
            <table class="table">
                <thead><tr><th>日期</th><th>子商品</th><th>件数</th><th>采购金额</th></tr></thead>
                <tbody>
                <?php foreach ((array) ($stats['daily'] ?? []) as $row): ?>
                    <tr>
                        <td><?= e($row['date'] ?? '') ?></td>
                        <td><?= e($row['item_count'] ?? 0) ?></td>
                        <td><?= e($row['quantity'] ?? 0) ?></td>
                        <td><?= e($money($row['amount'] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="panel">
        <div class="panel-head"><span>采购人统计</span><span class="sub">用户追溯入口</span></div>
        <div class="panel-body">
            <table class="table">
                <thead><tr><th>采购人</th><th>子商品</th><th>件数</th><th>采购金额</th></tr></thead>
                <tbody>
                <?php foreach ((array) ($stats['buyers'] ?? []) as $buyer): ?>
                    <tr>
                        <td><?= e($buyer['buyer'] ?? '') ?></td>
                        <td><?= e($buyer['item_count'] ?? 0) ?></td>
                        <td><?= e($buyer['quantity'] ?? 0) ?></td>
                        <td><?= e($money($buyer['amount'] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="grid two-col">
    <div class="panel">
        <div class="panel-head"><span>平台分布</span><span class="sub">采购子商品口径</span></div>
        <div class="panel-body">
            <table class="table">
                <thead><tr><th>平台</th><th>子商品</th><th>件数</th><th>采购金额</th></tr></thead>
                <tbody>
                <?php foreach ((array) ($stats['platforms'] ?? []) as $platform): ?>
                    <tr>
                        <td><?= e($platform['name'] ?? '') ?></td>
                        <td><?= e($platform['item_count'] ?? 0) ?></td>
                        <td><?= e($platform['quantity'] ?? 0) ?></td>
                        <td><?= e($money($platform['amount'] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="panel">
        <div class="panel-head"><span>采购状态分布</span><span class="sub">当前筛选</span></div>
        <div class="panel-body">
            <table class="table">
                <thead><tr><th>状态</th><th>数量</th></tr></thead>
                <tbody>
                <?php foreach ((array) ($stats['statuses'] ?? []) as $status => $count): ?>
                    <tr><td><span class="tag gray"><?= e($status) ?></span></td><td><?= e($count) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<div class="panel">
    <div class="panel-head"><span>采购人追溯明细</span><span class="sub">最多显示 500 行</span></div>
    <div class="panel-body">
        <table class="table">
            <thead><tr><th>日期</th><th>采购人</th><th>平台</th><th>店铺</th><th>订单号</th><th>商品</th><th>状态</th><th>数量</th><th>金额</th><th>1688 单号</th></tr></thead>
            <tbody>
            <?php foreach ((array) ($stats['trace_rows'] ?? []) as $row): ?>
                <tr>
                    <td><?= e($row['date'] ?? '') ?></td>
                    <td><?= e($row['buyer'] ?? '') ?></td>
                    <td><?= e($row['platform'] ?? '') ?></td>
                    <td><?= e($row['store'] ?? '') ?></td>
                    <td class="order-id"><?= e($row['order_no'] ?? '') ?></td>
                    <td><?= e(trim((string) (($row['item_code'] ?? '') . ' ' . ($row['title'] ?? '')))) ?></td>
                    <td><span class="tag gray"><?= e($row['status'] ?? '') ?></span></td>
                    <td><?= e($row['quantity'] ?? 0) ?></td>
                    <td><?= e($money($row['amount'] ?? 0)) ?></td>
                    <td><?= e($row['tabaono'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
