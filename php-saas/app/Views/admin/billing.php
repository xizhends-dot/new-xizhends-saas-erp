<?php
$selectedTenant = [];
foreach ($tenants as $tenant) {
    if (($tenant['key'] ?? '') === $selected) {
        $selectedTenant = $tenant;
        break;
    }
}
$balance = (int) ($account['balance'] ?? 0);
$storeAddFee = (int) ($account['store_add_fee'] ?? 50);
$storeMonthlyFee = (int) ($account['store_monthly_fee'] ?? 50);
$debtSuspendThreshold = (int) ($account['debt_suspend_threshold'] ?? -300);
$dueSubscriptions = array_values(array_filter($subscriptions ?? [], static function (array $row): bool {
    $next = trim((string) ($row['next_charge_at'] ?? ''));
    return ($row['status'] ?? 'active') === 'active' && $next !== '' && strtotime($next) !== false && strtotime($next) <= strtotime(date('Y-m-d'));
}));
$typeLabels = [
    'recharge' => '充值',
    'adjustment' => '调整',
    'charge' => '扣费',
];
?>
<div class="page-head">
    <div>
        <h1>费用管理 <span class="sub">租户积分余额、充值与扣费流水</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/admin/tenants">返回租户</a>
        <a class="btn admin" href="/admin/billing?tenant=<?= e($selected) ?>">刷新</a>
    </div>
</div>

<?php if (trim((string) $message) !== ''): ?>
    <div class="notice slim"><?= e($message) ?></div>
<?php endif; ?>

<div class="grid billing-stats">
    <div class="stat">
        <div class="stat-label">当前租户</div>
        <div class="stat-value billing-name"><?= e($selectedTenant['company_name'] ?? $selected) ?></div>
        <div class="stat-sub"><?= e($selected) ?>.xizhends.com</div>
    </div>
    <div class="stat">
        <div class="stat-label">可用积分</div>
        <div class="stat-value"><?= e(number_format($balance)) ?><span class="pt-unit">pt</span></div>
        <div class="stat-sub">积分只作系统内部额度，不展示外部币种</div>
    </div>
    <div class="stat">
        <div class="stat-label">新增店铺扣费</div>
        <div class="stat-value"><?= e($storeAddFee) ?><span class="pt-unit">pt</span></div>
        <div class="stat-sub">新增一个店铺立即扣除</div>
    </div>
    <div class="stat">
        <div class="stat-label">店铺月费</div>
        <div class="stat-value"><?= e($storeMonthlyFee) ?><span class="pt-unit">pt</span></div>
        <div class="stat-sub">每个店铺按月同日扣除</div>
    </div>
    <div class="stat">
        <div class="stat-label">余额状态</div>
        <div class="stat-value billing-state <?= $balance <= $debtSuspendThreshold ? 'low' : ($balance >= $storeAddFee ? 'ok' : 'warn') ?>">
            <?= $balance <= $debtSuspendThreshold ? '已停用线' : ($balance >= $storeAddFee ? '可新增' : '需充值') ?>
        </div>
        <div class="stat-sub"><?= $balance <= $debtSuspendThreshold ? '余额达到停用阈值' : ($balance >= $storeAddFee ? '余额满足一次店铺新增扣费' : '余额不足时会阻止新增店铺') ?></div>
    </div>
</div>

<div class="notice billing-rule-notice">
    <strong>扣费规则</strong>
    <span>租户新增店铺立即扣除 <?= e($storeAddFee) ?>pt；从下个月同日开始，每个店铺每月扣除 <?= e($storeMonthlyFee) ?>pt。月费允许进入欠费，余额不足时应提示充值；余额达到 <?= e($debtSuspendThreshold) ?>pt 时系统自动停用租户。</span>
</div>

<div class="grid billing-layout">
    <section class="panel">
        <div class="panel-head"><span>选择租户</span><span class="sub"><?= e(count($tenants)) ?> 个租户</span></div>
        <div class="panel-body">
            <form class="form-grid billing-picker" method="get" action="/admin/billing">
                <label class="wide">
                    <span>租户</span>
                    <select name="tenant" onchange="this.form.submit()">
                        <?php foreach ($tenants as $tenant): ?>
                            <?php $key = (string) ($tenant['key'] ?? ''); ?>
                            <option value="<?= e($key) ?>" <?= $key === $selected ? 'selected' : '' ?>>
                                <?= e($tenant['company_name'] ?? $key) ?> / <?= e($key) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="form-submit"><button class="btn admin" type="submit">查看</button></div>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><span>充值 / 扣减</span><span class="sub">单位：pt</span></div>
        <div class="panel-body">
            <form class="form-grid billing-adjust" method="post" action="/admin/billing/adjust">
                <input type="hidden" name="tenant" value="<?= e($selected) ?>">
                <label>
                    <span>操作</span>
                    <select name="action">
                        <option value="recharge">充值</option>
                        <option value="deduct">扣减</option>
                    </select>
                </label>
                <label>
                    <span>积分数量</span>
                    <input name="amount" type="number" min="1" step="1" value="500">
                </label>
                <label class="wide">
                    <span>备注</span>
                    <input name="note" placeholder="例如：线下收款充值 / 违规手动扣减 / 账务修正">
                </label>
                <div class="form-submit"><button class="btn admin" type="submit">提交</button></div>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><span>到期扣费</span><span class="sub"><?= e(count($dueSubscriptions)) ?> 笔到期</span></div>
        <div class="panel-body">
            <form class="billing-process" method="post" action="/admin/billing/process">
                <input type="hidden" name="tenant" value="<?= e($selected) ?>">
                <div>
                    <strong><?= e($selectedTenant['company_name'] ?? $selected) ?></strong>
                    <p class="sub">处理当前租户所有到期店铺月费；余额可能扣到负数，达到 <?= e($debtSuspendThreshold) ?>pt 自动停用。</p>
                </div>
                <button class="btn admin" type="submit">处理到期扣费</button>
            </form>
        </div>
    </section>
</div>

<div class="panel">
    <div class="panel-head"><span>租户积分总览</span><span class="sub">只显示 pt</span></div>
    <div class="panel-body">
        <table class="table">
            <thead>
            <tr>
                <th>租户</th>
                <th>套餐</th>
                <th>员工</th>
                <th>平台授权</th>
                <th>余额</th>
                <th>状态</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($tenants as $tenant): ?>
                <?php $tenantBalance = (int) ($tenant['balance'] ?? 0); ?>
                <tr class="<?= ($tenant['key'] ?? '') === $selected ? 'row-selected' : '' ?>">
                    <td>
                        <strong><?= e($tenant['company_name'] ?? '') ?></strong>
                        <div class="sub"><?= e($tenant['key'] ?? '') ?>.xizhends.com</div>
                    </td>
                    <td><?= e($tenant['plan'] ?? '-') ?></td>
                    <td><?= e($tenant['staff_count'] ?? 0) ?></td>
                    <td>
                        <?php foreach (($tenant['platform_labels'] ?? []) as $label): ?>
                            <span class="tag blue"><?= e($label) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td><strong><?= e(number_format($tenantBalance)) ?>pt</strong></td>
                    <td><span class="tag <?= $tenantBalance <= $debtSuspendThreshold ? 'red' : ($tenantBalance >= $storeAddFee ? 'green' : 'red') ?>"><?= $tenantBalance <= $debtSuspendThreshold ? '停用线' : ($tenantBalance >= $storeAddFee ? '正常' : '余额不足') ?></span></td>
                    <td><a class="btn" href="/admin/billing?tenant=<?= e($tenant['key'] ?? '') ?>">管理</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><span>店铺月费订阅</span><span class="sub"><?= e(count($subscriptions ?? [])) ?> 个店铺</span></div>
    <div class="panel-body">
        <table class="table">
            <thead>
            <tr>
                <th>店铺</th>
                <th>扣费</th>
                <th>周期</th>
                <th>下次扣费</th>
                <th>上次扣费</th>
                <th>状态</th>
                <th>说明</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($subscriptions)): ?>
                <tr><td colspan="7"><div class="empty">暂无店铺月费订阅</div></td></tr>
            <?php endif; ?>
            <?php foreach (($subscriptions ?? []) as $subscription): ?>
                <?php
                $nextCharge = (string) ($subscription['next_charge_at'] ?? '');
                $isDue = ($subscription['status'] ?? 'active') === 'active' && $nextCharge !== '' && strtotime($nextCharge) !== false && strtotime($nextCharge) <= strtotime(date('Y-m-d'));
                ?>
                <tr>
                    <td>
                        <strong><?= e($subscription['store_name'] ?? '') ?></strong>
                        <div class="sub">ID：<?= e($subscription['store_id'] ?? '') ?></div>
                    </td>
                    <td><?= e(number_format((int) ($subscription['amount'] ?? $storeMonthlyFee))) ?>pt</td>
                    <td><?= e(($subscription['cycle'] ?? 'monthly') === 'monthly' ? '每月' : ($subscription['cycle'] ?? '')) ?></td>
                    <td><span class="tag <?= $isDue ? 'red' : 'gray' ?>"><?= e($nextCharge ?: '-') ?></span></td>
                    <td><?= e(($subscription['last_charge_at'] ?? '') ?: '-') ?></td>
                    <td><span class="tag <?= ($subscription['status'] ?? 'active') === 'active' ? 'green' : 'gray' ?>"><?= e(($subscription['status'] ?? 'active') === 'active' ? '启用' : '停用') ?></span></td>
                    <td><?= e($subscription['note'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><span>最近流水</span><span class="sub"><?= e(count($ledger)) ?> 条</span></div>
    <div class="panel-body">
        <table class="table">
            <thead>
            <tr>
                <th>时间</th>
                <th>租户</th>
                <th>类型</th>
                <th>变动</th>
                <th>变动后余额</th>
                <th>备注</th>
                <th>操作人</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$ledger): ?>
                <tr><td colspan="7"><div class="empty">暂无积分流水</div></td></tr>
            <?php endif; ?>
            <?php foreach ($ledger as $row): ?>
                <?php $amount = (int) ($row['amount'] ?? 0); ?>
                <tr>
                    <td><?= e($row['created_at'] ?? '') ?></td>
                    <td>
                        <strong><?= e(($row['tenant_name'] ?? '') ?: ($selectedTenant['company_name'] ?? $selected)) ?></strong>
                        <div class="sub"><?= e(($row['tenant_key'] ?? '') ?: $selected) ?></div>
                    </td>
                    <td><span class="tag <?= $amount >= 0 ? 'green' : 'gray' ?>"><?= e($typeLabels[(string) ($row['type'] ?? '')] ?? ($row['type'] ?? '调整')) ?></span></td>
                    <td class="<?= $amount >= 0 ? 'point-plus' : 'point-minus' ?>"><?= $amount >= 0 ? '+' : '' ?><?= e(number_format($amount)) ?>pt</td>
                    <td><?= e(number_format((int) ($row['balance_after'] ?? 0))) ?>pt</td>
                    <td><?= e($row['note'] ?? '') ?></td>
                    <td><?= e($row['operator'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
