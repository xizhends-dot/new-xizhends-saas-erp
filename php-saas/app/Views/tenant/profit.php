<div class="page-head">
    <div>
        <h1>利润分析 <span class="sub">参考 old/plugins/profit-analysis</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/import-export/export?tenant=<?= e($tenantKey) ?>&type=finance">导出报表</a>
        <a class="btn primary" href="/settings?tenant=<?= e($tenantKey) ?>">利润设置</a>
    </div>
</div>

<div class="notice">
    当前利润口径使用租户设置：汇率 <?= e($summary['settings']['exchange_rate'] ?? '') ?>，默认国际运费 ￥<?= e(number_format((float) ($summary['settings']['default_intl_fee'] ?? 0), 2)) ?>/单；真实运费优先读取 intl_fee/comamount，Y/R 平台日本邮费按数量分摊，<?= !empty($summary['settings']['store_deduction_enabled']) ? '优先使用店铺扣点' : '使用平台扣点' ?>。旧系统汇率参考：<?= e($legacySettings['profit_exchange_rate'] ?: '未配置') ?>。
</div>

<section class="grid stats">
    <div class="stat"><div class="stat-label">销售额</div><div class="stat-value">￥<?= e(number_format((float) $summary['sales'], 0)) ?></div><div class="stat-sub"><?= e($summary['order_count'] ?? 0) ?> 单 / <?= e($summary['quantity'] ?? 0) ?> 件</div></div>
    <div class="stat"><div class="stat-label">采购成本</div><div class="stat-value">￥<?= e(number_format((float) $summary['purchase_cost'], 0)) ?></div><div class="stat-sub">按子商品成本汇总</div></div>
    <div class="stat"><div class="stat-label">国际运费</div><div class="stat-value">￥<?= e(number_format((float) $summary['intl_fee'], 0)) ?></div><div class="stat-sub">实际优先，缺失用默认分摊</div></div>
    <div class="stat"><div class="stat-label">日本邮费</div><div class="stat-value">￥<?= e(number_format((float) ($summary['japan_postage'] ?? 0), 0)) ?></div><div class="stat-sub">Y/R 订单邮费分摊</div></div>
    <div class="stat"><div class="stat-label">毛利</div><div class="stat-value">￥<?= e(number_format((float) $summary['profit'], 0)) ?></div><div class="stat-sub">扣点后收入 - 成本 - 运费</div></div>
</section>

<div class="panel">
    <div class="panel-head"><span>订单毛利明细</span><span class="sub">扣点、汇率、运费来自系统设置</span></div>
    <div class="panel-body">
        <table class="table">
            <thead><tr><th>订单号</th><th>店铺</th><th>数量</th><th>销售额</th><th>日本邮费</th><th>采购成本</th><th>国际运费</th><th>运费口径</th><th>扣点</th><th>平台费</th><th>扣点后收入</th><th>毛利</th><th>毛利率</th></tr></thead>
            <tbody>
            <?php foreach ($summary['rows'] as $row): ?>
                <tr>
                    <td class="order-id"><?= e($row['order_no']) ?></td>
                    <td><?= e($row['store']) ?></td>
                    <td><?= e($row['quantity']) ?></td>
                    <td>￥<?= e(number_format((float) $row['sales'], 0)) ?></td>
                    <td>￥<?= e(number_format((float) ($row['japan_postage'] ?? 0), 0)) ?><?= !empty($row['japan_postage_shared']) ? e(' 分摊') : '' ?></td>
                    <td>￥<?= e(number_format((float) $row['purchase_cost'], 0)) ?></td>
                    <td>￥<?= e(number_format((float) $row['intl_fee'], 0)) ?></td>
                    <td><?= e($row['intl_fee_source'] ?? '') ?></td>
                    <td><?= e($row['deduction_source']) ?> <?= e($row['deduction']) ?></td>
                    <td>￥<?= e(number_format((float) ($row['platform_fee'] ?? 0), 0)) ?></td>
                    <td>￥<?= e(number_format((float) ($row['sales_after_deduction_converted'] ?? 0), 0)) ?></td>
                    <td><span class="tag <?= $row['profit'] >= 0 ? 'green' : 'red' ?>">￥<?= e(number_format((float) $row['profit'], 0)) ?></span></td>
                    <td><?= e($row['margin']) ?>%</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
