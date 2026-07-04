<?php
$errors = is_array($errors ?? null) ? $errors : [];
$message = (string) ($message ?? '');
$policy = is_array($policy ?? null) ? $policy : ['min_length' => 8, 'require_letter' => true, 'require_number' => true, 'forbid_whitespace' => true];
?>

<div class="page-head">
    <div>
        <h1>修改密码 <span class="sub"><?= e($currentUser['name'] ?? $currentUser['username'] ?? '当前员工') ?></span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/?tenant=<?= e($tenantKey) ?>">返回首页</a>
        <button class="btn primary" type="submit" form="password-edit-form">保存新密码</button>
    </div>
</div>

<?php if ($message !== ''): ?>
    <div class="notice"><?= e($message) ?></div>
<?php endif; ?>
<?php if (isset($errors['form'])): ?>
    <div class="notice danger"><?= e($errors['form']) ?></div>
<?php endif; ?>

<div class="panel form-panel">
    <div class="panel-head"><span>员工自助改密码</span><span class="sub">需要验证旧密码，保存后只写入安全哈希</span></div>
    <div class="panel-body">
        <form id="password-edit-form" class="form-grid" method="post" action="/password/update">
                <?= csrf_field() ?>
            <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">

            <label>
                <span>旧密码</span>
                <input type="password" name="old_password" autocomplete="current-password" required>
                <?php if (isset($errors['old_password'])): ?><small class="form-error"><?= e($errors['old_password']) ?></small><?php endif; ?>
            </label>

            <label>
                <span>新密码</span>
                <input type="password" name="new_password" autocomplete="new-password" required>
                <?php if (isset($errors['new_password'])): ?><small class="form-error"><?= e($errors['new_password']) ?></small><?php endif; ?>
            </label>

            <label>
                <span>确认新密码</span>
                <input type="password" name="confirm_password" autocomplete="new-password" required>
                <?php if (isset($errors['confirm_password'])): ?><small class="form-error"><?= e($errors['confirm_password']) ?></small><?php endif; ?>
            </label>

            <div class="wide">
                <span class="detail-lb">密码规则</span>
                <div class="notice">
                    至少 <?= e($policy['min_length'] ?? 8) ?> 位<?php if (!empty($policy['require_letter'])): ?>，包含字母<?php endif; ?><?php if (!empty($policy['require_number'])): ?>，包含数字<?php endif; ?><?php if (!empty($policy['forbid_whitespace'])): ?>，不能包含空白字符<?php endif; ?>。
                </div>
            </div>
        </form>
    </div>
</div>
