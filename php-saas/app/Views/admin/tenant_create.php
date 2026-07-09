<?php
$values = is_array($values ?? null) ? $values : [];
$baseDomain = (string) ($baseDomain ?? 'xizhends.com');
$subdomain = (string) ($values['subdomain'] ?? '');
$dbName = (string) (($values['db_name'] ?? '') ?: ($subdomain !== '' ? 'xizhen_tenant_' . str_replace('-', '_', $subdomain) : ''));
$preview = $subdomain !== '' ? 'https://' . $subdomain . '.' . $baseDomain : 'https://{子域名}.' . $baseDomain;
?>
<div class="page-head">
    <div>
        <h1>新增租户 <span class="sub">一次完成主库登记、平台授权、计费账户与初始管理员</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/admin/tenants">返回租户</a>
    </div>
</div>

<?php if (trim((string) ($error ?? '')) !== ''): ?>
    <div class="notice error slim"><?= e($error) ?></div>
<?php endif; ?>

<div class="notice slim">
    访问地址预览：<strong id="tenant-url-preview"><?= e($preview) ?></strong>
</div>

<form id="tenant-create-form" class="admin-settings-form" method="post" action="/admin/tenants/create">
                <?= csrf_field() ?>
    <section class="panel settings-wide">
        <div class="panel-head"><span>公司资料</span><span class="sub">主库 tenants 档案</span></div>
        <div class="panel-body form-grid">
            <label class="wide">
                <span>公司名</span>
                <input name="company_name" maxlength="128" required value="<?= e($values['company_name'] ?? '') ?>">
            </label>
            <label class="wide">
                <span>公司简称</span>
                <input name="company_short_name" maxlength="64" value="<?= e($values['company_short_name'] ?? '') ?>">
            </label>
            <label>
                <span>子域名</span>
                <input id="tenant-subdomain" name="subdomain" maxlength="63" required pattern="[a-z0-9][a-z0-9-]{0,62}" value="<?= e($subdomain) ?>">
            </label>
            <label>
                <span>数据库名</span>
                <input id="tenant-db-name" name="db_name" maxlength="64" required pattern="[a-z0-9_]{1,64}" value="<?= e($dbName) ?>">
            </label>
            <label>
                <span>数据库主机</span>
                <input name="db_host" maxlength="255" required value="<?= e($values['db_host'] ?? '127.0.0.1') ?>">
            </label>
            <label>
                <span>套餐</span>
                <select name="plan">
                    <?php foreach (['basic' => 'Basic', 'pro' => 'Pro', 'ent' => 'Enterprise'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= (string) ($values['plan'] ?? 'basic') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </section>

    <section class="panel settings-wide">
        <div class="panel-head"><span>联系人</span><span class="sub">可选资料</span></div>
        <div class="panel-body form-grid">
            <label>
                <span>联系人</span>
                <input name="contact_name" maxlength="64" value="<?= e($values['contact_name'] ?? '') ?>">
            </label>
            <label>
                <span>电话</span>
                <input name="contact_phone" maxlength="32" value="<?= e($values['contact_phone'] ?? '') ?>">
            </label>
            <label>
                <span>邮箱</span>
                <input name="contact_email" maxlength="128" type="email" value="<?= e($values['contact_email'] ?? '') ?>">
            </label>
            <label>
                <span>微信</span>
                <input name="contact_wechat" maxlength="64" value="<?= e($values['contact_wechat'] ?? '') ?>">
            </label>
            <label class="wide">
                <span>地址</span>
                <input name="address" maxlength="255" value="<?= e($values['address'] ?? '') ?>">
            </label>
            <label class="wide">
                <span>备注</span>
                <textarea name="remark" maxlength="1000"><?= e($values['remark'] ?? '') ?></textarea>
            </label>
        </div>
    </section>

    <section class="panel settings-wide">
        <div class="panel-head"><span>初始管理员</span><span class="sub">写入新租户库 users</span></div>
        <div class="panel-body form-grid">
            <label>
                <span>管理员用户名</span>
                <input name="admin_username" maxlength="128" required value="<?= e($values['admin_username'] ?? '') ?>">
            </label>
            <label>
                <span>管理员密码</span>
                <input name="admin_password" type="password" minlength="8" required value="<?= e($values['admin_password'] ?? '') ?>">
            </label>
            <label>
                <span>初始积分</span>
                <input name="initial_points" type="number" min="0" step="1" value="<?= e($values['initial_points'] ?? 0) ?>">
            </label>
        </div>
    </section>

    <div class="settings-submit-row settings-wide">
        <span class="setting-muted">数据库名会用于 CREATE DATABASE，提交前会按白名单校验。</span>
        <button class="btn admin" type="submit">开通租户</button>
    </div>
</form>

<script>
(function () {
    var baseDomain = <?= json_encode($baseDomain, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var subdomain = document.getElementById('tenant-subdomain');
    var dbName = document.getElementById('tenant-db-name');
    var preview = document.getElementById('tenant-url-preview');
    var dbTouched = dbName.value.trim() !== '';
    dbName.addEventListener('input', function () {
        dbTouched = true;
    });
    subdomain.addEventListener('input', function () {
        var value = subdomain.value.toLowerCase().replace(/[^a-z0-9-]/g, '');
        if (subdomain.value !== value) {
            subdomain.value = value;
        }
        preview.textContent = value ? ('https://' + value + '.' + baseDomain) : ('https://{子域名}.' + baseDomain);
        if (!dbTouched || dbName.value.trim() === '') {
            dbName.value = value ? ('xizhen_tenant_' + value.replace(/-/g, '_')) : '';
        }
    });
}());
</script>
