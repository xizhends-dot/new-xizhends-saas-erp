<?php
$allPermissions = array_unique(array_merge(...array_values($rolePermissions)));
$userPermissions = $user['permissions'] ?? [];
$userStores = $user['stores'] ?? [];
?>

<div class="page-head">
    <div>
        <h1>编辑员工 <span class="sub"><?= e($user['name'] ?? $user['username'] ?? '') ?></span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="<?= e($returnUrl) ?>">返回列表</a>
        <button class="btn primary" type="submit" form="user-edit-form">保存员工</button>
    </div>
</div>

<div class="notice">员工权限只在当前租户内生效。公司管理员拥有本公司业务管理权限；采购、客服按角色默认权限加附加权限生效。</div>

<div class="panel form-panel">
    <div class="panel-head"><span>账号资料</span><span class="sub">角色、店铺范围与功能权限</span></div>
    <div class="panel-body">
        <form id="user-edit-form" class="form-grid" method="post" action="/users/update">
            <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
            <input type="hidden" name="id" value="<?= e($user['id'] ?? '') ?>">

            <label>
                <span>姓名</span>
                <input name="name" value="<?= e($user['name'] ?? '') ?>" placeholder="如 李四">
            </label>

            <label>
                <span>登录名</span>
                <input name="username" value="<?= e($user['username'] ?? '') ?>" placeholder="如 lisi">
            </label>

            <label>
                <span>角色</span>
                <select name="role">
                    <?php foreach (array_keys($rolePermissions) as $role): ?>
                        <option value="<?= e($role) ?>" <?= ($user['role'] ?? '') === $role ? 'selected' : '' ?>><?= e($role) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>状态</span>
                <select name="status">
                    <option value="active" <?= ($user['status'] ?? '') === 'active' ? 'selected' : '' ?>>启用</option>
                    <option value="disabled" <?= ($user['status'] ?? '') === 'disabled' ? 'selected' : '' ?>>停用</option>
                </select>
            </label>

            <label>
                <span>重置密码</span>
                <input name="password_reset" value="" placeholder="仅在需要重置时填写">
            </label>

            <label>
                <span>首选入口</span>
                <select name="preference_module">
                    <?php foreach (['' => '默认首页', 'platform' => '平台订单', 'purchase' => '采购订单', 'jp' => '日本仓发货', 'mail' => '邮件中心', 'profit' => '利润分析'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= ($user['preference_module'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="wide">
                <span class="detail-lb">店铺范围</span>
                <div class="perm-grid">
                    <label class="perm-check"><input type="checkbox" name="stores[]" value="全部店铺" <?= in_array('全部店铺', $userStores, true) ? 'checked' : '' ?>>全部店铺</label>
                    <?php foreach ($stores as $store): ?>
                        <?php $storeName = (string) ($store['name'] ?? ''); ?>
                        <label class="perm-check"><input type="checkbox" name="stores[]" value="<?= e($storeName) ?>" <?= in_array($storeName, $userStores, true) ? 'checked' : '' ?>><?= e($storeName) ?></label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="wide">
                <span class="detail-lb">功能权限</span>
                <div class="perm-grid">
                    <?php foreach ($allPermissions as $permission): ?>
                        <label class="perm-check"><input type="checkbox" name="permissions[]" value="<?= e($permission) ?>" <?= in_array($permission, $userPermissions, true) ? 'checked' : '' ?>><?= e($permission) ?></label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="notice wide">员工个人 1688 API 配置不再明文维护；如需接入采购物流，请在租户系统设置中替换租户级 1688 配置文件。</div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><span>角色默认权限</span><span class="sub">保存时会合并角色默认权限与勾选权限</span></div>
    <div class="panel-body">
        <table class="table">
            <thead><tr><th>角色</th><th>默认功能</th></tr></thead>
            <tbody>
            <?php foreach ($rolePermissions as $role => $permissions): ?>
                <tr>
                    <td><strong><?= e($role) ?></strong></td>
                    <td><?php foreach ($permissions as $permission): ?><span class="tag blue"><?= e($permission) ?></span> <?php endforeach; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
