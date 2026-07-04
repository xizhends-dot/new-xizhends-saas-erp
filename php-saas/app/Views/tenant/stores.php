<div class="page-head">
    <div>
        <h1>店铺管理 <span class="sub">平台店铺与可见范围</span></h1>
    </div>
    <div class="head-actions">
        <button class="btn primary" type="submit" form="store-add-form">新增店铺</button>
    </div>
</div>

<?php
$billingBalance = (int) ($billingAccount['balance'] ?? 0);
$storeAddFee = (int) ($billingAccount['store_add_fee'] ?? 50);
$storeMonthlyFee = (int) ($billingAccount['store_monthly_fee'] ?? 50);
$debtSuspendThreshold = (int) ($billingAccount['debt_suspend_threshold'] ?? -300);
?>
<div class="notice store-billing-notice">
    <strong>积分余额：<?= e(number_format($billingBalance)) ?>pt</strong>
    <span>新增店铺立即扣除 <?= e($storeAddFee) ?>pt；下个月同日开始每月扣除 <?= e($storeMonthlyFee) ?>pt。</span>
    <?php if ($billingBalance < $storeAddFee): ?>
        <span class="tag red"><?= $billingBalance <= $debtSuspendThreshold ? '租户已达到停用线，请立即充值' : '余额不足，请联系 SaaS 超级管理员充值' ?></span>
    <?php endif; ?>
</div>

<div class="panel form-panel">
    <div class="panel-head"><span>新增店铺</span><span class="sub">租户管理员或有“店铺新增”权限的员工可操作</span></div>
    <div class="panel-body">
        <form id="store-add-form" class="form-grid" method="post" action="/stores/add">
                <?= csrf_field() ?>
            <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
            <label><span>平台</span><select name="platform">
                <?php foreach ($platformNames as $code => $name): ?>
                    <option value="<?= e($code) ?>"><?= e($name) ?></option>
                <?php endforeach; ?>
            </select></label>
            <label><span>旧系统店铺ID</span><input name="legacy_dpid" placeholder="乐天填 dpid，用于商品页和主图"></label>
            <label><span>店铺缩写</span><input name="short" placeholder="如 R-01 / Yahoo-main"></label>
            <label class="wide"><span>店铺全称</span><input name="name" placeholder="如 乐天旗舰店"></label>
            <label><span>店铺扣点(%)</span><input name="profit_deduction" value="70" placeholder="默认 70"></label>
            <label><span>可见状态</span><select name="status"><option value="visible">可见</option><option value="hidden">隐藏</option></select></label>
            <label><span>API 状态</span><select name="api_status"><option>未配置</option><option>已配置</option><option>平台锁定</option></select></label>
            <label class="wide"><span>隐藏原因</span><input name="hidden_reason" placeholder="如 已休店 / 测试店铺 / 平台锁定"></label>
            <label class="wide"><span>店铺 API 配置</span><textarea name="api_config" placeholder='乐天 RMS 示例：{"Secret":"...","Key":"..."}；也兼容旧 dpapi_config JSON'></textarea></label>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><span>店铺列表</span><span class="sub"><?= e(count($stores)) ?> 个店铺</span></div>
    <div class="panel-body">
        <table class="table">
            <thead><tr><th>平台</th><th>店铺缩写</th><th>店铺全称</th><th>扣点</th><th>API 状态</th><th>可见状态</th><th>隐藏原因</th><th>创建来源</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($stores as $store): ?>
                <tr>
                    <td><?= e($platformNames[$store['platform']] ?? $store['platform']) ?></td>
                    <td><?= e($store['short']) ?></td>
                    <td><?= e($store['name']) ?></td>
                    <td><?= e($store['profit_deduction'] ?? 70) ?>%</td>
                    <td><span class="tag <?= ($store['api_status'] ?? '') === '已配置' ? 'green' : 'gray' ?>"><?= e($store['api_status'] ?? '未配置') ?></span></td>
                    <td><span class="tag <?= ($store['status'] ?? '') === 'visible' ? 'green' : 'gray' ?>"><?= ($store['status'] ?? '') === 'visible' ? '可见' : '隐藏' ?></span></td>
                    <td><?= e($store['hidden_reason'] ?? '-') ?></td>
                    <td><?= e($store['created_by'] ?? '-') ?></td>
                    <td><a class="btn" href="/stores/edit?tenant=<?= e($tenantKey) ?>&id=<?= e($store['id']) ?>">编辑</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
