<?php
$account = is_array($account ?? null) ? $account : [];
$ledger = is_array($ledger ?? null) ? $ledger : [];
$subscriptions = is_array($subscriptions ?? null) ? $subscriptions : [];
$balance = (int) ($account['balance'] ?? $account['balance_points'] ?? 0);
$storeAddFee = (int) ($account['store_add_fee'] ?? 50);
$storeMonthlyFee = (int) ($account['store_monthly_fee'] ?? 50);
$debtSuspendThreshold = (int) ($account['debt_suspend_threshold'] ?? -300);
$balanceLow = $balance < $storeAddFee;
$balanceStopped = $balance <= $debtSuspendThreshold;
$typeLabels = [
    'recharge' => '充值',
    'charge' => '扣费',
    'monthly' => '月费',
    'store_monthly' => '月费',
    'store.monthly' => '月费',
    'adjustment' => '调整',
];
?>
<div class="page-head">
    <div>
        <h1>积分账单 <span class="sub">积分余额、扣费规则与流水明细</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/stores?tenant=<?= e($tenantKey) ?>">店铺管理</a>
    </div>
</div>

<div class="grid billing-stats">
    <div class="stat">
        <div class="stat-label">当前余额</div>
        <div class="stat-value <?= $balanceLow ? 'point-minus' : 'point-plus' ?>"><?= e(number_format($balance)) ?><span class="pt-unit">pt</span></div>
        <div class="stat-sub">积分只作系统内部额度，不展示外部币种</div>
    </div>
    <div class="stat">
        <div class="stat-label">开店扣费</div>
        <div class="stat-value"><?= e($storeAddFee) ?><span class="pt-unit">pt</span></div>
        <div class="stat-sub">新增一个店铺立即扣除</div>
    </div>
    <div class="stat">
        <div class="stat-label">店铺月费</div>
        <div class="stat-value"><?= e($storeMonthlyFee) ?><span class="pt-unit">pt</span></div>
        <div class="stat-sub">每个店铺按月扣除</div>
    </div>
    <div class="stat">
        <div class="stat-label">停用线</div>
        <div class="stat-value billing-state <?= $balanceStopped ? 'low' : 'ok' ?>"><?= e($debtSuspendThreshold) ?><span class="pt-unit">pt</span></div>
        <div class="stat-sub">达到停用线后租户会被停用</div>
    </div>
    <div class="stat">
        <div class="stat-label">账单状态</div>
        <div class="stat-value billing-state <?= $balanceStopped ? 'low' : ($balanceLow ? 'low' : 'ok') ?>">
            <?= e($balanceStopped ? '已达停用线' : ($balanceLow ? '余额不足' : '正常')) ?>
        </div>
        <div class="stat-sub"><?= e($balanceLow ? '请联系 SaaS 超级管理员充值' : '余额满足一次开店扣费') ?></div>
    </div>
</div>

<?php if ($balanceStopped): ?>
    <div class="notice error slim">已达 <?= e($debtSuspendThreshold) ?>pt 停用线，请立即联系超管充值，否则租户将被停用。</div>
<?php elseif ($balanceLow): ?>
    <div class="notice error slim">余额不足，新增店铺需 <?= e($storeAddFee) ?>pt，请联系 SaaS 超级管理员充值。</div>
<?php endif; ?>

<div class="notice billing-rule-notice">
    <strong>收费规则</strong>
    <span>新增店铺扣除 <?= e($storeAddFee) ?>pt；每个店铺每月扣除 <?= e($storeMonthlyFee) ?>pt；欠至 <?= e($debtSuspendThreshold) ?>pt 自动停用。</span>
</div>

<div class="panel">
    <div class="panel-head"><span>月费订阅</span><span class="sub"><?= e(count($subscriptions)) ?> 个店铺</span></div>
    <div class="panel-body">
        <table class="table">
            <thead>
            <tr>
                <th>店铺名</th>
                <th>月费</th>
                <th>下次扣费日</th>
                <th>状态</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$subscriptions): ?>
                <tr><td colspan="4"><div class="empty">暂无店铺订阅</div></td></tr>
            <?php endif; ?>
            <?php foreach ($subscriptions as $subscription): ?>
                <?php
                $amount = (int) ($subscription['amount'] ?? $subscription['amount_points'] ?? $subscription['fee'] ?? $storeMonthlyFee);
                $status = (string) ($subscription['status'] ?? 'active');
                ?>
                <tr>
                    <td><?= e(($subscription['store_name'] ?? '') ?: ('店铺 #' . (string) ($subscription['store_id'] ?? '-'))) ?></td>
                    <td><?= e(number_format($amount)) ?>pt</td>
                    <td><?= e(($subscription['next_charge_at'] ?? '') ?: '-') ?></td>
                    <td><span class="tag <?= $status === 'active' ? 'green' : 'gray' ?>"><?= e($status === 'active' ? '启用' : ($status ?: '未知')) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><span>积分流水</span><span class="sub">最近 <?= e(count($ledger)) ?> 条</span></div>
    <div class="panel-body">
        <table class="table">
            <thead>
            <tr>
                <th>时间</th>
                <th>类型</th>
                <th>变动</th>
                <th>变动后余额</th>
                <th>备注</th>
                <th>操作人</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$ledger): ?>
                <tr><td colspan="6"><div class="empty">暂无积分流水</div></td></tr>
            <?php endif; ?>
            <?php foreach ($ledger as $row): ?>
                <?php
                $type = (string) ($row['type'] ?? $row['entry_type'] ?? '');
                $amount = (int) ($row['amount'] ?? $row['amount_points'] ?? 0);
                $balanceAfter = (int) ($row['balance_after'] ?? $row['balance_after_points'] ?? 0);
                ?>
                <tr>
                    <td><?= e($row['created_at'] ?? '') ?></td>
                    <td><span class="tag <?= $amount >= 0 ? 'green' : 'gray' ?>"><?= e($typeLabels[$type] ?? ($type !== '' ? $type : '调整')) ?></span></td>
                    <td class="<?= $amount >= 0 ? 'point-plus' : 'point-minus' ?>"><?= $amount >= 0 ? '+' : '' ?><?= e(number_format($amount)) ?>pt</td>
                    <td><?= e(number_format($balanceAfter)) ?>pt</td>
                    <td><?= e($row['note'] ?? '') ?></td>
                    <td><?= e($row['operator'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
