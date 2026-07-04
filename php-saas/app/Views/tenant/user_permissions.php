<?php
$user = is_array($user ?? null) ? $user : [];
$groups = is_array($groups ?? null) ? $groups : [];
$message = (string) ($message ?? '');
?>

<div class="page-head">
    <div>
        <h1>权限覆盖 <span class="sub"><?= e($user['name'] ?? $user['username'] ?? '员工') ?></span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/users?tenant=<?= e($tenantKey) ?>">返回员工列表</a>
        <button class="btn primary" type="submit" form="permission-override-form">保存覆盖</button>
    </div>
</div>

<?php if ($message !== ''): ?>
    <div class="notice"><?= e($message) ?></div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head"><span>员工信息</span><span class="sub">当前角色：<?= e($user['role'] ?? '') ?></span></div>
    <div class="panel-body">
        <table class="table">
            <tbody>
                <tr><th>姓名</th><td><?= e($user['name'] ?? '') ?></td><th>登录名</th><td><?= e($user['username'] ?? '') ?></td></tr>
                <tr><th>角色</th><td><?= e($user['role'] ?? '') ?></td><th>店铺范围</th><td><?= e(implode('、', (array) ($user['stores'] ?? []))) ?></td></tr>
            </tbody>
        </table>
    </div>
</div>

<form id="permission-override-form" method="post" action="/users/permissions/save">
                <?= csrf_field() ?>
    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
    <input type="hidden" name="user_id" value="<?= e($user['id'] ?? '') ?>">

    <?php foreach ($groups as $group => $items): ?>
        <div class="panel">
            <div class="panel-head"><span><?= e($group) ?></span><span class="sub">继承角色默认、单独允许或单独拒绝</span></div>
            <div class="panel-body">
                <table class="table">
                    <thead><tr><th>权限</th><th>角色默认</th><th>当前生效</th><th>覆盖方式</th><th>说明</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php $state = (string) ($item['state'] ?? 'inherit'); ?>
                        <tr>
                            <td>
                                <strong><?= e($item['label'] ?? $item['key'] ?? '') ?></strong>
                                <?php if (!empty($item['legacy_key'])): ?><div class="sub"><?= e($item['legacy_key']) ?></div><?php endif; ?>
                            </td>
                            <td><span class="tag <?= !empty($item['inherited']) ? 'green' : 'gray' ?>"><?= !empty($item['inherited']) ? '有' : '无' ?></span></td>
                            <td><span class="tag <?= !empty($item['effective']) ? 'green' : 'gray' ?>"><?= !empty($item['effective']) ? '允许' : '拒绝' ?></span></td>
                            <td>
                                <select name="states[<?= e($item['key'] ?? '') ?>]">
                                    <option value="inherit" <?= $state === 'inherit' ? 'selected' : '' ?>>继承角色</option>
                                    <option value="allow" <?= $state === 'allow' ? 'selected' : '' ?>>单独允许</option>
                                    <option value="deny" <?= $state === 'deny' ? 'selected' : '' ?>>单独拒绝</option>
                                </select>
                            </td>
                            <td><?= e($item['description'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
</form>
