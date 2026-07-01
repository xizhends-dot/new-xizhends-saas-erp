<?php
/** @var array<string, mixed> $analysis */
$analysis = $analysis ?? ['filters' => [], 'rows' => [], 'summary' => [], 'pagination' => [], 'platforms' => [], 'chart' => []];
$filters = (array) ($analysis['filters'] ?? []);
$rows = (array) ($analysis['rows'] ?? []);
$summary = (array) ($analysis['summary'] ?? []);
$pagination = (array) ($analysis['pagination'] ?? []);
$platforms = (array) ($analysis['platforms'] ?? []);
$money = static fn (mixed $value): string => '￥' . number_format((float) $value, 0);
$productUrl = static function (array $row): string {
    $base = (string) ($row['product_url'] ?? '');
    $code = rawurlencode((string) ($row['product_code'] ?? ''));
    $platform = (string) ($row['platform'] ?? '');
    $dpid = trim((string) ($row['dpid'] ?? ''));
    if ($base === '' || $code === '') {
        return '';
    }
    if ($platform === 'y' && $dpid !== '') {
        return $base . rawurlencode($dpid) . '/' . $code . '.html';
    }
    if ($platform === 'r' && $dpid !== '') {
        return $base . rawurlencode($dpid) . '/' . $code . '/';
    }
    return $base . $code;
};
?>
<div class="page-head">
    <div>
        <h1>出单商品分析 <span class="sub">热卖排名</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/performance?tenant=<?= e($tenantKey) ?>">业绩面板</a>
        <a class="btn primary" href="/orders?tenant=<?= e($tenantKey) ?>&view=platform">查看订单</a>
    </div>
</div>

<form class="panel" method="get" action="/performance/products">
    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
    <div class="panel-body filters">
        <label>平台
            <select name="platform">
                <?php foreach ($platforms as $code => $name): ?>
                    <option value="<?= e($code) ?>" <?= (string) ($filters['platform'] ?? '') === (string) $code ? 'selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>商品类型
            <select name="product_type">
                <?php foreach (['all' => '全部', 'premium' => '精品 / 日本仓', 'distribution' => '铺货 / 国内采购'] as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= (string) ($filters['product_type'] ?? 'all') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>开始日期 <input type="date" name="start_date" value="<?= e($filters['start_date'] ?? '') ?>"></label>
        <label>结束日期 <input type="date" name="end_date" value="<?= e($filters['end_date'] ?? '') ?>"></label>
        <button class="btn primary" type="submit">查询</button>
    </div>
</form>

<section class="grid stats">
    <div class="stat"><div class="stat-label">商品种类</div><div class="stat-value"><?= e($summary['total_products'] ?? 0) ?></div><div class="stat-sub">按商品 code 去重</div></div>
    <div class="stat"><div class="stat-label">销售数量</div><div class="stat-value"><?= e($summary['total_orders'] ?? 0) ?></div><div class="stat-sub">按数量汇总</div></div>
    <div class="stat"><div class="stat-label">销售额</div><div class="stat-value"><?= e($money($summary['total_amount'] ?? 0)) ?></div><div class="stat-sub">子商品金额汇总</div></div>
    <div class="stat"><div class="stat-label">品均销量</div><div class="stat-value"><?= e($summary['avg_orders_per_product'] ?? 0) ?></div><div class="stat-sub">数量 / 商品种类</div></div>
</section>

<div class="panel">
    <div class="panel-head">
        <span>热卖商品</span>
        <span class="sub">第 <?= e($pagination['current_page'] ?? 1) ?> / <?= e($pagination['total_pages'] ?? 1) ?> 页，共 <?= e($pagination['total_count'] ?? 0) ?> 个商品</span>
    </div>
    <div class="panel-body">
        <table class="table">
            <thead><tr><th>排名</th><th>图片</th><th>商品 Code</th><th>销售数量</th><th>均价</th><th>销售额</th><th>店铺数</th><th>涉及店铺</th><th>规格分布</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $url = $productUrl($row); ?>
                <tr>
                    <td><span class="tag blue"><?= e($row['rank'] ?? '') ?></span></td>
                    <td>
                        <?php if (!empty($row['image'])): ?>
                            <img src="<?= e($row['image']) ?>" alt="<?= e($row['product_code'] ?? '') ?>" style="width:54px;height:54px;object-fit:cover;border-radius:6px;">
                        <?php else: ?>
                            <span class="tag gray">无图</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($url !== ''): ?>
                            <a href="<?= e($url) ?>" target="_blank" rel="noreferrer"><?= e($row['product_code'] ?? '') ?></a>
                        <?php else: ?>
                            <?= e($row['product_code'] ?? '') ?>
                        <?php endif; ?>
                    </td>
                    <td><?= e($row['order_count'] ?? 0) ?></td>
                    <td><?= e($money($row['avg_price'] ?? 0)) ?></td>
                    <td><?= e($money($row['total_amount'] ?? 0)) ?></td>
                    <td><?= e($row['shop_count'] ?? 0) ?></td>
                    <td><?= e($row['shops'] ?? '') ?></td>
                    <td>
                        <?php foreach (array_slice((array) ($row['subcodes'] ?? []), 0, 3, true) as $subcode => $count): ?>
                            <span class="tag gray"><?= e($subcode) ?> <?= e($count) ?></span>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
