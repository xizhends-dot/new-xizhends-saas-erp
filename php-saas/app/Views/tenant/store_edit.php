<div class="page-head">
    <div>
        <h1>编辑店铺 <span class="sub"><?= e($store['name'] ?? '') ?></span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="<?= e($returnUrl) ?>">返回列表</a>
        <?php if (($store['platform'] ?? '') === 'y'): ?>
            <a class="btn" href="/stores/yahoo/authorize?tenant=<?= e($tenantKey) ?>&id=<?= e($store['id'] ?? '') ?>">发起授权</a>
        <?php endif; ?>
        <button class="btn primary" type="submit" form="store-edit-form">保存店铺</button>
    </div>
</div>

<div class="notice">店铺由租户管理员维护，用于订单归属、员工店铺范围、店铺级 API 凭证和隐藏店铺控制；平台授权开关仍由超管后台统一控制。</div>
<?php if (trim((string) ($_GET['message'] ?? '')) !== ''): ?><div class="notice ok"><?= e($_GET['message']) ?></div><?php endif; ?>
<?php if (trim((string) ($_GET['error'] ?? '')) !== ''): ?><div class="notice error"><?= e($_GET['error']) ?></div><?php endif; ?>
<?php if (($store['platform'] ?? '') === 'y'): ?>
    <div class="notice">Yahoo Shop 授权会使用店铺 API 配置中的 AppID/Secret 和 seller_id，回调地址为 <code>/oauth/yahoo/callback</code>。请确保 Yahoo Developer Console 中登记了当前域名的完整回调 URL。</div>
<?php endif; ?>

<div class="panel form-panel">
    <div class="panel-head"><span>店铺资料</span><span class="sub">只影响当前租户：<?= e($tenant['company_name'] ?? $tenantKey) ?></span></div>
    <div class="panel-body">
        <form id="store-edit-form" class="form-grid" method="post" action="/stores/update">
            <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
            <input type="hidden" name="id" value="<?= e($store['id'] ?? '') ?>">

            <label>
                <span>平台</span>
                <select name="platform">
                    <?php foreach ($platformNames as $code => $name): ?>
                        <option value="<?= e($code) ?>" <?= ($store['platform'] ?? '') === $code ? 'selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>旧系统店铺ID</span>
                <input name="legacy_dpid" value="<?= e($store['legacy_dpid'] ?? '') ?>" placeholder="乐天填 dpid，用于商品页和主图">
            </label>

            <label>
                <span>店铺缩写</span>
                <input name="short" value="<?= e($store['short'] ?? '') ?>" placeholder="如 R-01 / Yahoo-main">
            </label>

            <label class="wide">
                <span>店铺全称</span>
                <input name="name" value="<?= e($store['name'] ?? '') ?>" placeholder="如 乐天旗舰店">
            </label>

            <label>
                <span>店铺扣点(%)</span>
                <input name="profit_deduction" value="<?= e($store['profit_deduction'] ?? 70) ?>" placeholder="默认 70">
            </label>

            <label>
                <span>可见状态</span>
                <select name="status">
                    <option value="visible" <?= ($store['status'] ?? '') === 'visible' ? 'selected' : '' ?>>可见</option>
                    <option value="hidden" <?= ($store['status'] ?? '') === 'hidden' ? 'selected' : '' ?>>隐藏</option>
                </select>
            </label>

            <label>
                <span>API 状态</span>
                <select name="api_status">
                    <?php foreach (['未配置', '已配置', '平台锁定', '同步异常'] as $status): ?>
                        <option value="<?= e($status) ?>" <?= ($store['api_status'] ?? '') === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="wide">
                <span>隐藏原因</span>
                <input name="hidden_reason" value="<?= e($store['hidden_reason'] ?? '') ?>" placeholder="如 已休店 / 测试店铺 / 平台锁定">
            </label>

            <label class="wide">
                <span>店铺 API 配置</span>
                <textarea name="api_config" placeholder='乐天 RMS 示例：{"Secret":"...","Key":"..."}；也兼容旧 dpapi_config JSON'><?= e($store['api_config'] ?? '') ?></textarea>
            </label>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><span>权限边界</span><span class="sub">SaaS 租户侧</span></div>
    <div class="panel-body">
        <div class="check-list">
            <div>租户管理员可以新增、编辑、隐藏本公司店铺。</div>
            <div>旧系统 `dpapi_config`、店铺扣点、隐藏店铺原因已纳入新店铺资料。</div>
            <div>有“店铺新增”或“员工管理”权限的员工，可以按授权范围维护店铺或分配店铺。</div>
            <div>平台是否开通、是否锁定、定时任务频率和系统级 API Provider 由超管维护。</div>
        </div>
    </div>
</div>
