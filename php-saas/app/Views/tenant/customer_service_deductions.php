<?php
$rows = is_array($rows ?? null) ? $rows : [];
$summary = is_array($summary ?? null) ? $summary : ['total' => 0, 'configured' => 0, 'defaulted' => 0, 'average' => 0];
$message = (string) ($message ?? '');
$errors = is_array($errors ?? null) ? $errors : [];
?>

<div class="page-head">
    <div>
        <h1>客服扣点 <span class="sub">快捷维护利润计算扣点</span></h1>
    </div>
    <div class="head-actions">
        <button class="btn primary" type="submit" form="customer-service-deduction-form">保存扣点</button>
    </div>
</div>

<?php if ($message !== ''): ?>
    <div class="notice"><?= e($message) ?></div>
<?php endif; ?>
<?php if (isset($errors['form'])): ?>
    <div class="notice danger"><?= e($errors['form']) ?></div>
<?php endif; ?>

<section class="grid stats">
    <div class="stat"><div class="stat-label">客服账号</div><div class="stat-value"><?= e($summary['total'] ?? 0) ?></div><div class="stat-sub">仅统计角色为客服的员工</div></div>
    <div class="stat"><div class="stat-label">已配置</div><div class="stat-value"><?= e($summary['configured'] ?? 0) ?></div><div class="stat-sub">来自用户字段或租户设置</div></div>
    <div class="stat"><div class="stat-label">默认值</div><div class="stat-value"><?= e($summary['defaulted'] ?? 0) ?></div><div class="stat-sub">未配置时使用 70%</div></div>
    <div class="stat"><div class="stat-label">平均扣点</div><div class="stat-value"><?= e(number_format((float) ($summary['average'] ?? 0), 2)) ?>%</div><div class="stat-sub">保存前校验 0-100</div></div>
</section>

<div class="panel">
    <div class="panel-head"><span>快捷编辑</span><span class="sub">old 用户列表内保存 profit_deduction 的迁移视图</span></div>
    <div class="panel-body">
        <form id="customer-service-deduction-form" method="post" action="/users/customer-service-deductions/save">
                <?= csrf_field() ?>
            <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
            <table class="table">
                <thead><tr><th>客服</th><th>登录名</th><th>状态</th><th>扣点</th><th>来源</th><th>校验</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $userId = (int) ($row['id'] ?? 0); ?>
                    <tr>
                        <td><strong><?= e($row['name'] ?? '') ?></strong></td>
                        <td><?= e($row['username'] ?? '') ?></td>
                        <td><span class="tag <?= ($row['status'] ?? '') === 'active' ? 'green' : 'gray' ?>"><?= ($row['status'] ?? '') === 'active' ? '启用' : '停用' ?></span></td>
                        <td>
                            <input type="number" name="deductions[<?= e($userId) ?>]" min="0" max="100" step="0.01" value="<?= e($row['deduction_display'] ?? $row['deduction'] ?? 70) ?>">
                            <span class="sub">%</span>
                        </td>
                        <td><span class="tag blue"><?= e($row['source_label'] ?? '') ?></span></td>
                        <td><?= isset($errors[(string) $userId]) ? e($errors[(string) $userId]) : '0 到 100 之间' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="6">暂无客服账号。</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </form>
    </div>
</div>
