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
$platformSyncServices = is_array($platformSyncServices ?? null) ? $platformSyncServices : [];
$storeApiFields = is_array($storeApiFields ?? null) ? $storeApiFields : [];
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$storeApiFieldsJson = json_encode($storeApiFields, $jsonFlags) ?: '{}';
$emptyApiValuesJson = '{}';
$currentUser = is_array($currentUser ?? null) ? $currentUser : [];
$canOperateOrders = \Xizhen\Core\Permission::hasAny($currentUser, ['导入导出', '订单编辑']);
$message = trim((string) ($message ?? ''));
?>
<?php if ($message !== ''): ?>
    <div class="notice ok slim"><?= e($message) ?></div>
<?php endif; ?>

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
        <form id="store-add-form" class="form-grid store-api-form" method="post" action="/stores/add" data-store-api-form data-store-api-definitions="<?= e($storeApiFieldsJson) ?>" data-store-api-values="<?= e($emptyApiValuesJson) ?>">
                <?= csrf_field() ?>
            <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
            <input type="hidden" name="api_fields_original" value="<?= e($emptyApiValuesJson) ?>">
            <input type="hidden" name="api_config_original" value="">
            <input type="hidden" name="api_config_platform_original" value="">
            <label><span>平台</span><select name="platform" data-store-api-platform>
                <?php foreach ($platformNames as $code => $name): ?>
                    <option value="<?= e($code) ?>"><?= e($name) ?></option>
                <?php endforeach; ?>
            </select></label>
            <label><span>平台店铺ID</span><input name="legacy_dpid" placeholder="Rakuten 填 dpid，用于商品页和主图"></label>
            <label><span>店铺缩写</span><input name="short" placeholder="如 R-01 / Yahoo-main"></label>
            <label class="wide"><span>店铺全称</span><input name="name" placeholder="如 Rakuten旗舰店"></label>
            <label><span>店铺扣点(%)</span><input name="profit_deduction" value="70" placeholder="默认 70"></label>
            <label><span>可见状态</span><select name="status"><option value="visible">可见</option><option value="hidden">隐藏</option></select></label>
            <label><span>API 状态</span><select name="api_status"><option>未配置</option><option>已配置</option><option>平台锁定</option></select></label>
            <label class="wide"><span>隐藏原因</span><input name="hidden_reason" placeholder="如 已休店 / 测试店铺 / 平台锁定"></label>
            <div class="wide store-api-fields">
                <div class="store-api-title">店铺 API 配置</div>
                <div class="store-api-field-list" data-store-api-field-list></div>
            </div>
            <details class="wide store-api-advanced">
                <summary>高级：原始JSON</summary>
                <label>
                    <span>原始 JSON</span>
                    <textarea name="api_config_raw" data-store-api-raw placeholder='特殊场景可直接填写 JSON，例如 {"Secret":"...","Key":"..."}'></textarea>
                </label>
            </details>
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
                    <td>
                        <?php
                        $storeId = (int) ($store['id'] ?? 0);
                        $platform = (string) ($store['platform'] ?? '');
                        $apiConfigured = (string) ($store['api_status'] ?? '') === '已配置';
                        ?>
                        <div class="store-row-actions">
                            <a class="btn" href="/stores/edit?tenant=<?= e($tenantKey) ?>&id=<?= e($storeId) ?>">编辑</a>
                            <?php if ($canOperateOrders && isset($platformSyncServices[$platform])): ?>
                                <form class="store-sync-form" method="post" action="/orders/platform/sync" <?= $platform === 'y' ? 'data-confirm="Yahoo 平台订单同步需要在指定 IP 环境下执行。确认当前网络符合要求后再继续同步？"' : '' ?>>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                                    <input type="hidden" name="platform" value="<?= e($platform) ?>">
                                    <input type="hidden" name="store_id" value="<?= e($storeId) ?>">
                                    <input type="hidden" name="return" value="/stores?tenant=<?= e(rawurlencode($tenantKey)) ?>">
                                    <select name="days" aria-label="同步天数">
                                        <?php foreach ([1, 3, 7, 15, 30] as $days): ?>
                                            <option value="<?= e($days) ?>" <?= $days === 7 ? 'selected' : '' ?>><?= e($days) ?>天</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn" type="submit" <?= $apiConfigured ? '' : 'disabled title="请先在编辑页配置 API"' ?>>同步订单</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canOperateOrders): ?>
                                <a class="btn" href="/stores/import?tenant=<?= e($tenantKey) ?>&id=<?= e($storeId) ?>">导入订单</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
