<?php
/** @var array<string, mixed> $stats */
$stats = $stats ?? ['filters' => [], 'buyers' => [], 'statuses' => [], 'daily' => [], 'platforms' => [], 'trace_rows' => [], 'platformOptions' => []];
$filters = (array) ($stats['filters'] ?? []);
$totals = (array) ($stats['totals'] ?? []);
$buyers = array_values((array) ($stats['buyers'] ?? []));
$dailyRows = array_values((array) ($stats['daily'] ?? []));
$platformRows = array_values((array) ($stats['platforms'] ?? []));
$traceRows = array_values((array) ($stats['trace_rows'] ?? []));
$viewMode = (string) ($_GET['view'] ?? 'summary');
$viewMode = in_array($viewMode, ['summary', 'daily', 'amount', 'user'], true) ? $viewMode : 'summary';
$money = static fn (mixed $value, int $decimals = 0): string => '￥' . number_format((float) $value, $decimals);
$percent = static fn (mixed $part, mixed $whole): float => (float) $whole > 0 ? round((float) $part / (float) $whole * 100, 1) : 0.0;
$maxBuyerItems = max(1, (int) max(array_map(static fn (array $row): int => (int) ($row['item_count'] ?? 0), $buyers) ?: [0]));
$maxBuyerAmount = max(1.0, (float) max(array_map(static fn (array $row): float => (float) ($row['amount'] ?? 0), $buyers) ?: [0]));
$platformClass = static fn (mixed $code): string => preg_replace('/[^a-z0-9_-]/i', '', (string) $code) ?: 'x';
$queryUrl = static function (array $extra = []) use ($tenantKey, $filters, $viewMode): string {
    $params = [
        'tenant' => $tenantKey,
        'platform' => (string) ($filters['platform'] ?? ''),
        'buyer' => (string) ($filters['buyer'] ?? ''),
        'status' => (string) ($filters['status'] ?? ''),
        'start_date' => (string) ($filters['start_date'] ?? ''),
        'end_date' => (string) ($filters['end_date'] ?? ''),
        'view' => $viewMode,
    ];
    $params = array_merge($params, $extra);
    $params = array_filter($params, static fn (mixed $value): bool => $value !== '' && $value !== null);

    return '/stats/purchase?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
};
$today = date('Y-m-d');
$sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
$monthStart = date('Y-m-01');
$totalAmount = (float) ($totals['amount'] ?? 0);
$uniqueOrders = (int) ($totals['unique_orders'] ?? 0);
?>
<style>
.caigou-stats-container {
  background: #fff;
  border: 1px solid #d7dde5;
  padding: 18px;
}
.caigou-title {
  text-align: center;
  margin: 0 0 16px;
  color: #263238;
  font-size: 20px;
  font-weight: 800;
}
.caigou-title .sub {
  display: block;
  margin-top: 5px;
  color: #6b7280;
  font-size: 12px;
  font-weight: 500;
}
.caigou-filter {
  background: #f7f9fb;
  border: 1px solid #dfe5ec;
  padding: 12px;
  margin-bottom: 14px;
}
.caigou-filter-row {
  display: flex;
  flex-wrap: wrap;
  gap: 10px 14px;
  align-items: end;
}
.caigou-filter label {
  display: grid;
  gap: 5px;
  color: #586474;
  font-size: 12px;
  min-width: 140px;
}
.caigou-filter input,
.caigou-filter select {
  min-height: 32px;
}
.caigou-shortcuts {
  display: flex;
  flex-wrap: wrap;
  gap: 7px;
  margin-left: auto;
}
.caigou-tab-nav {
  display: flex;
  flex-wrap: wrap;
  gap: 0;
  margin: 0 0 14px;
  border-bottom: 2px solid #dfe5ec;
}
.caigou-tab-nav a {
  min-height: 38px;
  display: inline-flex;
  align-items: center;
  padding: 8px 18px;
  color: #52606d;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  font-weight: 700;
}
.caigou-tab-nav a:hover {
  background: #f3f7f3;
  color: #1f2937;
}
.caigou-tab-nav a.active {
  background: #eef9ee;
  color: #1f4d2b;
  border-bottom-color: #71b36f;
}
.caigou-summary-cards {
  display: grid;
  grid-template-columns: repeat(4, minmax(150px, 1fr));
  gap: 12px;
  margin-bottom: 18px;
}
.caigou-card {
  min-height: 112px;
  border: 1px solid #047857;
  background: #059669;
  padding: 16px 18px;
}
.caigou-card h3 {
  margin: 0 0 10px;
  color: #fff;
  font-size: 12px;
  font-weight: 700;
}
.caigou-card .count {
  color: #fff;
  font-size: 30px;
  font-weight: 900;
  line-height: 1.05;
}
.caigou-card .unit {
  color: #fff;
  font-size: 12px;
  margin-left: 4px;
}
.caigou-card.highlight {
  background: #07c160;
  border-color: #059669;
}
.caigou-card.money {
  background: #047857;
  border-color: #047857;
}
.caigou-card.order {
  background: #0f766e;
  border-color: #0f766e;
}
.caigou-section-title {
  color: #4b5563;
  font-size: 15px;
  font-weight: 800;
  margin: 18px 0 10px;
}
.caigou-table {
  width: 100%;
  border-collapse: collapse;
  background: #fff;
  font-size: 13px;
}
.caigou-table th,
.caigou-table td {
  border-bottom: 1px solid #e5e7eb;
  padding: 10px 12px;
}
.caigou-table th {
  background: #f3f5f7;
  color: #4b5563;
  text-align: center;
  font-size: 12px;
  font-weight: 800;
}
.caigou-table tr:hover td {
  background: #f8fafc;
}
.caigou-table .num {
  text-align: right;
  font-weight: 800;
  white-space: nowrap;
}
.caigou-table .strong-green {
  color: #18803a;
}
.caigou-table .strong-money {
  color: #d6336c;
}
.caigou-rank {
  width: 48px;
  text-align: center;
}
.caigou-rank-badge {
  display: inline-flex;
  justify-content: center;
  align-items: center;
  min-width: 30px;
  height: 24px;
  border-radius: 999px;
  background: #edf2f7;
  color: #4b5563;
  font-weight: 800;
}
.caigou-rank-badge.top {
  background: #263238;
  color: #fff;
}
.platform-badge {
  display: inline-flex;
  min-width: 34px;
  justify-content: center;
  padding: 3px 8px;
  color: #fff;
  font-size: 11px;
  font-weight: 800;
  margin-right: 7px;
}
.platform-y { background: #d6336c; }
.platform-w { background: #f59f00; }
.platform-r { background: #e03131; }
.platform-m { background: #1c7ed6; }
.platform-q { background: #7048e8; }
.platform-yp { background: #495057; }
.platform-x { background: #607d8b; }
.caigou-progress {
  height: 8px;
  background: #edf2f7;
  overflow: hidden;
  margin-top: 6px;
}
.caigou-progress span {
  display: block;
  height: 100%;
  background: linear-gradient(90deg, #2f9e44, #8ce99a);
}
.caigou-progress.money span {
  background: linear-gradient(90deg, #d6336c, #f783ac);
}
.caigou-empty {
  text-align: center;
  color: #7b8794;
  padding: 34px 10px;
  background: #f8fafc;
}
@media (max-width: 980px) {
  .caigou-summary-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .caigou-shortcuts { margin-left: 0; }
}
@media (max-width: 640px) {
  .caigou-summary-cards { grid-template-columns: 1fr; }
  .caigou-tab-nav a { flex: 1 1 50%; justify-content: center; }
}
</style>

<div class="page-head">
    <div>
        <h1>采购业绩统计 <span class="sub">按采购员、平台和日期统计采购完成情况</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/stats/purchase/status-daily?tenant=<?= e($tenantKey) ?>">采购状态日报</a>
        <a class="btn primary" href="/orders?tenant=<?= e($tenantKey) ?>&view=purchase">采购订单</a>
    </div>
</div>

<div class="caigou-stats-container">
    <h2 class="caigou-title">
        采购员业绩统计
        <span class="sub">按采购时间统计完成采购、采购金额、1688 单号与采购员排名</span>
    </h2>

    <form class="caigou-filter" method="get" action="/stats/purchase">
        <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
        <input type="hidden" name="view" value="<?= e($viewMode) ?>">
        <div class="caigou-filter-row">
            <label>开始日期
                <input type="date" name="start_date" value="<?= e($filters['start_date'] ?? '') ?>">
            </label>
            <label>结束日期
                <input type="date" name="end_date" value="<?= e($filters['end_date'] ?? '') ?>">
            </label>
            <label>平台
                <select name="platform">
                    <option value="">全部平台</option>
                    <?php foreach ((array) ($stats['platformOptions'] ?? []) as $code => $name): ?>
                        <option value="<?= e($code) ?>" <?= (string) ($filters['platform'] ?? '') === (string) $code ? 'selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>采购员
                <input type="text" name="buyer" value="<?= e($filters['buyer'] ?? '') ?>" placeholder="姓名">
            </label>
            <label>状态
                <input type="text" name="status" value="<?= e($filters['status'] ?? '') ?>" placeholder="采购状态">
            </label>
            <button class="btn primary" type="submit">查询</button>
            <div class="caigou-shortcuts">
                <a class="btn" href="<?= e($queryUrl(['start_date' => $today, 'end_date' => $today])) ?>">今日</a>
                <a class="btn" href="<?= e($queryUrl(['start_date' => $sevenDaysAgo, 'end_date' => $today])) ?>">近7天</a>
                <a class="btn" href="<?= e($queryUrl(['start_date' => $monthStart, 'end_date' => $today])) ?>">本月</a>
            </div>
        </div>
    </form>

    <nav class="caigou-tab-nav">
        <a class="<?= $viewMode === 'summary' ? 'active' : '' ?>" href="<?= e($queryUrl(['view' => 'summary'])) ?>">总体概览</a>
        <a class="<?= $viewMode === 'daily' ? 'active' : '' ?>" href="<?= e($queryUrl(['view' => 'daily'])) ?>">每日统计</a>
        <a class="<?= $viewMode === 'amount' ? 'active' : '' ?>" href="<?= e($queryUrl(['view' => 'amount'])) ?>">采购金额</a>
        <a class="<?= $viewMode === 'user' ? 'active' : '' ?>" href="<?= e($queryUrl(['view' => 'user'])) ?>">采购员排名</a>
    </nav>

    <?php if ($viewMode === 'summary'): ?>
        <div class="caigou-summary-cards">
            <div class="caigou-card highlight">
                <h3>完成采购</h3>
                <span class="count"><?= e($totals['item_count'] ?? 0) ?></span><span class="unit">单</span>
            </div>
            <div class="caigou-card money">
                <h3>采购总金额</h3>
                <span class="count"><?= e($money($totalAmount, 2)) ?></span>
            </div>
            <div class="caigou-card order">
                <h3>1688 订单数（去重）</h3>
                <span class="count"><?= e($uniqueOrders) ?></span><span class="unit">单</span>
            </div>
            <div class="caigou-card">
                <h3>平均每单金额</h3>
                <span class="count"><?= e($uniqueOrders > 0 ? $money($totalAmount / $uniqueOrders, 2) : $money(0, 2)) ?></span>
            </div>
        </div>

        <div class="caigou-section-title">各平台采购统计</div>
        <table class="caigou-table">
            <thead><tr><th>平台</th><th class="num">完成采购</th><th class="num">件数</th><th class="num">采购金额</th><th class="num">1688 单号</th><th class="num">金额占比</th></tr></thead>
            <tbody>
            <?php if (!$platformRows): ?>
                <tr><td class="caigou-empty" colspan="6">所选日期范围内没有采购业绩记录</td></tr>
            <?php endif; ?>
            <?php foreach ($platformRows as $platform): ?>
                <?php $amount = (float) ($platform['amount'] ?? 0); ?>
                <tr>
                    <td><span class="platform-badge platform-<?= e($platformClass($platform['code'] ?? 'x')) ?>"><?= e(strtoupper((string) ($platform['code'] ?? ''))) ?></span><?= e($platform['name'] ?? '') ?></td>
                    <td class="num strong-green"><?= e($platform['item_count'] ?? 0) ?></td>
                    <td class="num"><?= e($platform['quantity'] ?? 0) ?></td>
                    <td class="num strong-money"><?= e($money($amount, 2)) ?></td>
                    <td class="num"><?= e($platform['unique_orders'] ?? 0) ?></td>
                    <td class="num"><?= e($percent($amount, $totalAmount)) ?>%</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="caigou-section-title">采购员业绩排名</div>
        <table class="caigou-table">
            <thead><tr><th>排名</th><th>采购员</th><th class="num">完成采购</th><th class="num">采购金额</th><th class="num">1688 单号</th><th>占比</th></tr></thead>
            <tbody>
            <?php if (!$buyers): ?>
                <tr><td class="caigou-empty" colspan="6">所选日期范围内没有采购员完成采购的记录</td></tr>
            <?php endif; ?>
            <?php foreach ($buyers as $index => $buyer): ?>
                <?php $itemCount = (int) ($buyer['item_count'] ?? 0); ?>
                <tr>
                    <td class="caigou-rank"><span class="caigou-rank-badge <?= $index < 3 ? 'top' : '' ?>"><?= e($index + 1) ?></span></td>
                    <td><strong><?= e($buyer['buyer'] ?? '') ?></strong></td>
                    <td class="num strong-green"><?= e($itemCount) ?></td>
                    <td class="num strong-money"><?= e($money($buyer['amount'] ?? 0, 2)) ?></td>
                    <td class="num"><?= e($buyer['unique_orders'] ?? 0) ?></td>
                    <td>
                        <?= e($percent($itemCount, $totals['item_count'] ?? 0)) ?>%
                        <div class="caigou-progress"><span style="width:<?= e(min(100, $percent($itemCount, $maxBuyerItems))) ?>%"></span></div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($viewMode === 'daily'): ?>
        <div class="caigou-section-title">每日采购统计</div>
        <table class="caigou-table">
            <thead><tr><th>日期</th><th class="num">完成采购</th><th class="num">件数</th><th class="num">采购金额</th><th class="num">1688 单号</th></tr></thead>
            <tbody>
            <?php if (!$dailyRows): ?>
                <tr><td class="caigou-empty" colspan="5">所选日期范围内没有采购记录</td></tr>
            <?php endif; ?>
            <?php foreach ($dailyRows as $row): ?>
                <tr>
                    <td><strong><?= e($row['date'] ?? '') ?></strong></td>
                    <td class="num strong-green"><?= e($row['item_count'] ?? 0) ?></td>
                    <td class="num"><?= e($row['quantity'] ?? 0) ?></td>
                    <td class="num strong-money"><?= e($money($row['amount'] ?? 0, 2)) ?></td>
                    <td class="num"><?= e($row['unique_orders'] ?? 0) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="caigou-section-title">采购业绩明细</div>
        <table class="caigou-table">
            <thead><tr><th>日期</th><th>采购员</th><th>平台</th><th>订单号</th><th>商品</th><th class="num">金额</th><th>1688 单号</th></tr></thead>
            <tbody>
            <?php foreach ($traceRows as $row): ?>
                <tr>
                    <td><?= e($row['date'] ?? '') ?></td>
                    <td><?= e($row['buyer'] ?? '') ?></td>
                    <td><?= e($row['platform'] ?? '') ?></td>
                    <td class="order-id"><?= e($row['order_no'] ?? '') ?></td>
                    <td><?= e(trim((string) (($row['item_code'] ?? '') . ' ' . ($row['title'] ?? '')))) ?></td>
                    <td class="num strong-money"><?= e($money($row['amount'] ?? 0, 2)) ?></td>
                    <td><?= e($row['tabaono'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($viewMode === 'amount'): ?>
        <div class="caigou-summary-cards">
            <div class="caigou-card money">
                <h3>采购总金额</h3>
                <span class="count"><?= e($money($totalAmount, 2)) ?></span>
            </div>
            <div class="caigou-card order">
                <h3>1688 订单数（去重）</h3>
                <span class="count"><?= e($uniqueOrders) ?></span><span class="unit">单</span>
            </div>
            <div class="caigou-card">
                <h3>平均每单金额</h3>
                <span class="count"><?= e($uniqueOrders > 0 ? $money($totalAmount / $uniqueOrders, 2) : $money(0, 2)) ?></span>
            </div>
            <div class="caigou-card highlight">
                <h3>采购员数量</h3>
                <span class="count"><?= e($totals['buyer_count'] ?? 0) ?></span><span class="unit">人</span>
            </div>
        </div>

        <div class="caigou-section-title">各平台采购金额</div>
        <table class="caigou-table">
            <thead><tr><th>平台</th><th class="num">采购金额</th><th class="num">1688 单号</th><th class="num">平均每单</th></tr></thead>
            <tbody>
            <?php foreach ($platformRows as $platform): ?>
                <?php $orders = (int) ($platform['unique_orders'] ?? 0); $amount = (float) ($platform['amount'] ?? 0); ?>
                <tr>
                    <td><span class="platform-badge platform-<?= e($platformClass($platform['code'] ?? 'x')) ?>"><?= e(strtoupper((string) ($platform['code'] ?? ''))) ?></span><?= e($platform['name'] ?? '') ?></td>
                    <td class="num strong-money"><?= e($money($amount, 2)) ?></td>
                    <td class="num"><?= e($orders) ?></td>
                    <td class="num"><?= e($orders > 0 ? $money($amount / $orders, 2) : $money(0, 2)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="caigou-section-title">采购员金额排名</div>
        <table class="caigou-table">
            <thead><tr><th>排名</th><th>采购员</th><th class="num">采购金额</th><th class="num">1688 单号</th><th>占比</th></tr></thead>
            <tbody>
            <?php foreach ($buyers as $index => $buyer): ?>
                <?php $amount = (float) ($buyer['amount'] ?? 0); ?>
                <tr>
                    <td class="caigou-rank"><span class="caigou-rank-badge <?= $index < 3 ? 'top' : '' ?>"><?= e($index + 1) ?></span></td>
                    <td><strong><?= e($buyer['buyer'] ?? '') ?></strong></td>
                    <td class="num strong-money"><?= e($money($amount, 2)) ?></td>
                    <td class="num"><?= e($buyer['unique_orders'] ?? 0) ?></td>
                    <td>
                        <?= e($percent($amount, $totalAmount)) ?>%
                        <div class="caigou-progress money"><span style="width:<?= e(min(100, $percent($amount, $maxBuyerAmount))) ?>%"></span></div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="caigou-section-title">采购员排名</div>
        <table class="caigou-table">
            <thead><tr><th>排名</th><th>采购员</th><th class="num">采购总数</th><th class="num">件数</th><th class="num">采购金额</th><th>占比</th></tr></thead>
            <tbody>
            <?php if (!$buyers): ?>
                <tr><td class="caigou-empty" colspan="6">所选日期范围内没有采购员完成采购的记录</td></tr>
            <?php endif; ?>
            <?php foreach ($buyers as $index => $buyer): ?>
                <?php $itemCount = (int) ($buyer['item_count'] ?? 0); ?>
                <tr>
                    <td class="caigou-rank"><span class="caigou-rank-badge <?= $index < 3 ? 'top' : '' ?>"><?= e($index + 1) ?></span></td>
                    <td><strong><?= e($buyer['buyer'] ?? '') ?></strong></td>
                    <td class="num strong-green"><?= e($itemCount) ?></td>
                    <td class="num"><?= e($buyer['quantity'] ?? 0) ?></td>
                    <td class="num strong-money"><?= e($money($buyer['amount'] ?? 0, 2)) ?></td>
                    <td>
                        <?= e($percent($itemCount, $totals['item_count'] ?? 0)) ?>%
                        <div class="caigou-progress"><span style="width:<?= e(min(100, $percent($itemCount, $maxBuyerItems))) ?>%"></span></div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
