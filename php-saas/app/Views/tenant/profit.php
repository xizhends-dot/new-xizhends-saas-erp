<?php
$filters = is_array($analysis['filters'] ?? null) ? $analysis['filters'] : [];
$settings = is_array($analysis['settings'] ?? null) ? $analysis['settings'] : [];
$platforms = is_array($analysis['platforms'] ?? null) ? $analysis['platforms'] : [];
$pagination = is_array($analysis['pagination'] ?? null) ? $analysis['pagination'] : [];
$statusOptions = is_array($analysis['status_options'] ?? null) ? $analysis['status_options'] : [];
$rows = is_array($analysis['rows'] ?? null) ? $analysis['rows'] : [];
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$sevenDaysStart = date('Y-m-d', strtotime('-6 days'));
$thirtyDaysStart = date('Y-m-d', strtotime('-29 days'));
$buildUrl = static function (array $extra = []) use ($tenantKey, $filters): string {
    $params = array_merge([
        'tenant' => $tenantKey,
        'platform' => $filters['platform'] ?? '',
        'date_start' => $filters['date_start'] ?? '',
        'date_end' => $filters['date_end'] ?? '',
        'status' => $filters['status'] ?? '',
        'shipping_type' => $filters['shipping_type'] ?? '',
        'profit_threshold' => $filters['profit_threshold'] ?? '',
        'filter_low_profit' => !empty($filters['filter_low_profit']) ? '1' : '',
        'target_profit_rate' => $filters['target_profit_rate'] ?? 15,
        'order_id' => $filters['order_id'] ?? '',
        'per_page' => $filters['per_page'] ?? 100,
    ], $extra);
    $params = array_filter($params, static fn (mixed $value): bool => $value !== '' && $value !== null);
    return '/analytics/profit?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
};
?>
<div class="page-head">
    <div>
        <h1>利润核算分析 <span class="sub">按旧系统 profit-analysis 的商品明细口径核算</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/import-export/export?tenant=<?= e($tenantKey) ?>&type=finance">导出财务表</a>
        <a class="btn primary" href="/settings?tenant=<?= e($tenantKey) ?>">利润设置</a>
    </div>
</div>

<div class="notice">
    当前口径：售价 = 商品单价 + 日本邮费；实际利润 = 售价 × 扣点 × 汇率 - 国际运费 - 采购金额。Y/R 平台日本邮费按同订单明细分摊，国际运费优先读取实际运费，缺失时使用默认运费。旧系统汇率参考：<?= e($legacySettings['profit_exchange_rate'] ?: '未配置') ?>。
</div>

<div class="profit-tabs">
    <a class="<?= ($filters['platform'] ?? '') === '' ? 'active' : '' ?>" href="<?= e($buildUrl(['platform' => '', 'page' => 1])) ?>">全部平台</a>
    <?php foreach ($platforms as $code => $platform): ?>
        <a class="<?= ($filters['platform'] ?? '') === (string) $code ? 'active' : '' ?>" href="<?= e($buildUrl(['platform' => (string) $code, 'page' => 1])) ?>">
            <?= e($platform['name'] ?? $code) ?>
        </a>
    <?php endforeach; ?>
</div>

<form class="filter profit-filter" method="get" action="/analytics/profit">
    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
    <input type="hidden" name="platform" value="<?= e($filters['platform'] ?? '') ?>">
    <div class="profit-filter-row profit-filter-row-dates">
        <label class="fg"><span>订单日期起</span><input type="date" name="date_start" value="<?= e($filters['date_start'] ?? '') ?>"></label>
        <label class="fg"><span>订单日期止</span><input type="date" name="date_end" value="<?= e($filters['date_end'] ?? '') ?>"></label>
        <div class="fg profit-date-shortcuts">
            <span>快捷日期</span>
            <div>
                <a class="btn" href="<?= e($buildUrl(['date_start' => $today, 'date_end' => $today, 'page' => 1])) ?>">今天</a>
                <a class="btn" href="<?= e($buildUrl(['date_start' => $yesterday, 'date_end' => $yesterday, 'page' => 1])) ?>">昨天</a>
                <a class="btn" href="<?= e($buildUrl(['date_start' => $sevenDaysStart, 'date_end' => $today, 'page' => 1])) ?>">7天</a>
                <a class="btn" href="<?= e($buildUrl(['date_start' => $thirtyDaysStart, 'date_end' => $today, 'page' => 1])) ?>">30天</a>
            </div>
        </div>
    </div>
    <div class="profit-filter-row profit-filter-row-search">
        <label class="fg"><span>采购状态</span>
            <select name="status">
                <option value="">默认铺货状态</option>
                <option value="__ALL__" <?= ($filters['status'] ?? '') === '__ALL__' ? 'selected' : '' ?>>全部状态</option>
                <?php foreach ($statusOptions as $status): ?>
                    <option value="<?= e($status) ?>" <?= ($filters['status'] ?? '') === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="fg"><span>运费类型</span>
            <select name="shipping_type">
                <option value="">全部</option>
                <option value="actual" <?= ($filters['shipping_type'] ?? '') === 'actual' ? 'selected' : '' ?>>实际运费</option>
                <option value="estimate" <?= ($filters['shipping_type'] ?? '') === 'estimate' ? 'selected' : '' ?>>预估运费</option>
            </select>
        </label>
        <label class="fg"><span>订单/商品搜索</span><input name="order_id" value="<?= e($filters['order_id'] ?? '') ?>" placeholder="订单号、Item、lot"></label>
    </div>
    <div class="profit-filter-row profit-filter-row-profit">
        <label class="fg"><span>利润率低于(%)</span><input type="number" step="0.1" name="profit_threshold" value="<?= e($filters['profit_threshold'] ?? '') ?>" placeholder="如 5"></label>
        <label class="fg"><span>目标利润率(%)</span><input type="number" step="0.1" name="target_profit_rate" value="<?= e($filters['target_profit_rate'] ?? 15) ?>"></label>
        <label class="fg"><span>每页</span>
            <select name="per_page">
                <?php foreach ([50, 100, 200, 500] as $size): ?>
                    <option value="<?= e($size) ?>" <?= (int) ($filters['per_page'] ?? 100) === $size ? 'selected' : '' ?>><?= e($size) ?> 条</option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="fg check-line profit-check"><input type="checkbox" name="filter_low_profit" value="1" <?= !empty($filters['filter_low_profit']) ? 'checked' : '' ?>>只显示低利润</label>
        <div class="fg profit-actions">
            <button class="btn primary" type="submit">查询</button>
            <a class="btn" href="/analytics/profit?tenant=<?= e($tenantKey) ?>">重置</a>
        </div>
    </div>
</form>

<section class="grid stats profit-stats">
    <div class="stat"><div class="stat-label">核算明细</div><div class="stat-value"><?= e($analysis['total_count'] ?? 0) ?></div><div class="stat-sub">按商品明细行统计</div></div>
    <div class="stat"><div class="stat-label">亏本明细</div><div class="stat-value danger-text"><?= e($analysis['loss_count'] ?? 0) ?></div><div class="stat-sub">实际利润率低于 0%</div></div>
    <div class="stat"><div class="stat-label">平均利润率</div><div class="stat-value <?= ((float) ($analysis['avg_profit_rate'] ?? 0)) >= 0 ? 'ok-text' : 'danger-text' ?>"><?= e(number_format((float) ($analysis['avg_profit_rate'] ?? 0), 2)) ?>%</div><div class="stat-sub">明细利润率平均</div></div>
    <div class="stat"><div class="stat-label">总实际利润</div><div class="stat-value <?= ((float) ($analysis['total_profit'] ?? 0)) >= 0 ? 'ok-text' : 'danger-text' ?>">￥<?= e(number_format((float) ($analysis['total_profit'] ?? 0), 2)) ?></div><div class="stat-sub">参考采购价 - 采购金额</div></div>
    <div class="stat"><div class="stat-label">需调价</div><div class="stat-value warn-text"><?= e($analysis['need_adjust_count'] ?? 0) ?></div><div class="stat-sub">低于阈值且可反推售价</div></div>
</section>

<div class="panel">
    <div class="panel-head">
        <span>利润核算明细</span>
        <span class="sub">
            显示 <?= e($pagination['from'] ?? 0) ?>-<?= e($pagination['to'] ?? 0) ?> / <?= e($pagination['total'] ?? 0) ?>，
            汇率 <?= e($settings['exchange_rate'] ?? '') ?>，默认运费 ￥<?= e(number_format((float) ($settings['default_intl_fee'] ?? 0), 2)) ?>
        </span>
    </div>
    <div class="panel-body scroll-x">
        <table class="table profit-table">
            <thead>
            <tr>
                <th>图片</th>
                <th>平台</th>
                <th>店铺</th>
                <th>订单号</th>
                <th>订单日期</th>
                <th>Item / lot</th>
                <th>数量</th>
                <th>单价</th>
                <th>邮费</th>
                <th>售价</th>
                <th>国际运费</th>
                <th>扣点</th>
                <th>参考采购价</th>
                <th>采购金额</th>
                <th>实际利润</th>
                <th>实际利润率</th>
                <th>建议售价</th>
                <th>采购状态</th>
                <th>1688单号</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="19" class="empty-cell">没有符合条件的利润核算明细。</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <?php
                $profitClass = ((float) ($row['profit'] ?? 0)) >= 0 ? 'green' : 'red';
                $rate = (float) ($row['profit_rate'] ?? 0);
                $rowClass = $rate < 0 ? 'profit-row-loss' : (!empty($row['need_adjust']) ? 'profit-row-warning' : '');
                ?>
                <tr class="<?= e($rowClass) ?>">
                    <td class="img-cell">
                        <?php if (trim((string) ($row['image'] ?? '')) !== ''): ?>
                            <img src="<?= e($row['image']) ?>" alt="" class="profit-img" loading="lazy">
                        <?php else: ?>
                            <span class="sub">无图</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($row['platform_name'] ?? $row['platform'] ?? '') ?></td>
                    <td><?= e($row['store'] ?? '') ?></td>
                    <td class="order-id"><?= e($row['order_no'] ?? '') ?></td>
                    <td><?= e($row['order_date'] ?? '') ?></td>
                    <td>
                        <strong><?= e(($row['item_management_id'] ?? '') ?: ($row['item_code'] ?? '')) ?></strong>
                        <?php if (!empty($row['lot_number'])): ?><div class="sub"><?= e($row['lot_number']) ?></div><?php endif; ?>
                        <?php if (!empty($row['title'])): ?><div class="sub"><?= e($row['title']) ?></div><?php endif; ?>
                    </td>
                    <td class="num"><?= e($row['quantity'] ?? 0) ?></td>
                    <td class="num">￥<?= e(number_format((float) ($row['unit_price'] ?? 0), 0)) ?></td>
                    <td class="num">
                        ￥<?= e(number_format((float) ($row['postage'] ?? 0), 0)) ?>
                        <?php if (!empty($row['postage_shared'])): ?><div class="sub">原￥<?= e(number_format((float) ($row['original_postage'] ?? 0), 0)) ?></div><?php endif; ?>
                    </td>
                    <td class="num strong">￥<?= e(number_format((float) ($row['sale_price'] ?? 0), 0)) ?></td>
                    <td class="num">
                        ￥<?= e(number_format((float) ($row['shipping'] ?? 0), 2)) ?>
                        <span class="tag <?= !empty($row['has_actual_shipping']) ? 'green' : 'gray' ?>"><?= e($row['shipping_source'] ?? '') ?></span>
                        <?php if ((int) ($row['shipping_count'] ?? 1) > 1): ?><span class="tag blue">÷<?= e($row['shipping_count']) ?></span><?php endif; ?>
                    </td>
                    <td><?= e($row['deduction_source'] ?? '') ?> <?= e($row['deduction'] ?? '') ?>%</td>
                    <td class="num"><?= ((float) ($row['cost'] ?? 0)) > 0 ? '-' : '￥' . e(number_format((float) ($row['ref_cost'] ?? 0), 2)) ?></td>
                    <td class="num"><?= ((float) ($row['cost'] ?? 0)) > 0 ? '￥' . e(number_format((float) ($row['cost'] ?? 0), 2)) : '-' ?></td>
                    <td class="num"><span class="tag <?= e($profitClass) ?>">￥<?= e(number_format((float) ($row['profit'] ?? 0), 2)) ?></span></td>
                    <td class="num <?= $rate >= 0 ? 'ok-text' : 'danger-text' ?>"><?= e(number_format($rate, 2)) ?>%</td>
                    <td class="num">
                        <?php if (!empty($row['need_adjust']) && (int) ($row['suggested_price'] ?? 0) > 0): ?>
                            <strong class="warn-text">￥<?= e(number_format((float) $row['suggested_price'], 0)) ?></strong>
                            <div class="sub">+<?= e(number_format((float) ($row['suggested_diff'] ?? 0), 0)) ?></div>
                        <?php else: ?>
                            <span class="sub">-</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($row['purchase_status'] ?? '') ?></td>
                    <td><?= e($row['tabaono'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (($pagination['total_pages'] ?? 1) > 1): ?>
        <div class="profit-pagination">
            <a class="btn <?= (int) ($pagination['page'] ?? 1) <= 1 ? 'disabled' : '' ?>" href="<?= e($buildUrl(['page' => max(1, (int) ($pagination['page'] ?? 1) - 1)])) ?>">上一页</a>
            <span>第 <?= e($pagination['page'] ?? 1) ?> / <?= e($pagination['total_pages'] ?? 1) ?> 页</span>
            <a class="btn <?= (int) ($pagination['page'] ?? 1) >= (int) ($pagination['total_pages'] ?? 1) ? 'disabled' : '' ?>" href="<?= e($buildUrl(['page' => min((int) ($pagination['total_pages'] ?? 1), (int) ($pagination['page'] ?? 1) + 1)])) ?>">下一页</a>
        </div>
    <?php endif; ?>
</div>
