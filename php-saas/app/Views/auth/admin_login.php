<main class="auth-page admin-login">
    <section class="auth-card">
        <div class="auth-brand">
            <div class="brand-mark admin-mark">SA</div>
            <div>
                <div class="brand-name">西阵 SaaS 管理系统</div>
                <div class="brand-sub">saas.xizhends.com</div>
            </div>
        </div>

        <div class="auth-copy">
            <h1>管理后台登录</h1>
            <p>管理租户、平台授权、系统公告与全局运行状态。</p>
        </div>

        <?php if (trim((string) ($error ?? '')) !== ''): ?>
            <div class="auth-error"><?= e($error) ?></div>
        <?php endif; ?>

        <form class="auth-form" method="post" action="/admin/login">
                <?= csrf_field() ?>
            <input type="hidden" name="return" value="<?= e($returnUrl ?? '/admin') ?>">
            <label>
                <span>账号</span>
                <input name="username" autocomplete="username" autofocus placeholder="请输入管理账号">
            </label>
            <label>
                <span>密码</span>
                <input name="password" type="password" autocomplete="current-password" placeholder="请输入超管密码">
            </label>
            <button class="btn admin auth-submit" type="submit">登录后台</button>
        </form>
    </section>
</main>
