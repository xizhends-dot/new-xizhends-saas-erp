<main class="auth-page">
    <section class="auth-card">
        <div class="auth-brand">
            <div class="brand-mark"><?= e(substr((string) ($tenant['key'] ?? 'T'), 0, 2)) ?></div>
            <div>
                <div class="brand-name"><?= e($tenant['short_name'] ?? $tenant['company_name'] ?? '租户订单系统') ?></div>
                <div class="brand-sub"><?= e($tenant['company_name'] ?? '租户') ?> · xizhends.com</div>
            </div>
        </div>

        <div class="auth-copy">
            <h1>订单系统登录</h1>
            <p><?= e($tenant['company_name'] ?? '当前租户') ?>员工入口，请使用本公司分配的账号登录。</p>
        </div>

        <?php if (trim((string) ($error ?? '')) !== ''): ?>
            <div class="auth-error"><?= e($error) ?></div>
        <?php endif; ?>

        <form class="auth-form" method="post" action="/login">
                <?= csrf_field() ?>
            <input type="hidden" name="return" value="<?= e($returnUrl ?? '/?tenant=' . $tenantKey) ?>">
            <?php if (!($tenantHostMode ?? false)): ?>
                <label>
                    <span>租户</span>
                    <select name="tenant">
                        <?php foreach (($tenants ?? []) as $item): ?>
                            <?php $key = (string) ($item['key'] ?? ''); ?>
                            <option value="<?= e($key) ?>" <?= $key === ($tenantKey ?? '') ? 'selected' : '' ?>>
                                <?= e($item['company_name'] ?? $key) ?> / <?= e($key) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php else: ?>
                <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
            <?php endif; ?>
            <label>
                <span>登录名</span>
                <input name="username" autocomplete="username" autofocus placeholder="请输入登录名">
            </label>
            <label>
                <span>密码</span>
                <input name="password" type="password" autocomplete="current-password" placeholder="请输入员工密码">
            </label>
            <button class="btn primary auth-submit" type="submit">登录系统</button>
        </form>
    </section>
</main>
