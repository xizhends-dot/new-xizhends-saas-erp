<!doctype html>
<?php $assetVersion = static fn (string $path): string => (string) @filemtime(BASE_PATH . '/public' . $path); ?>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title ?? '超管后台') ?></title>
    <link rel="stylesheet" href="/assets/app.css?v=<?= e($assetVersion('/assets/app.css')) ?>">
</head>
<body class="admin-shell">
<aside class="sidebar admin">
    <div class="brand">
            <div class="brand-mark admin-mark">SA</div>
            <div>
            <div class="brand-name">西阵 SaaS</div>
            <div class="brand-sub">管理系统</div>
        </div>
    </div>
    <?php if (!empty($currentAdmin)): ?>
        <div class="session-box admin-session">
            <div>
                <strong><?= e($currentAdmin['display_name'] ?? $currentAdmin['username'] ?? '超管') ?></strong>
                <span><?= e($currentAdmin['username'] ?? '') ?></span>
            </div>
            <form method="post" action="/admin/logout" class="mini-form">
                <?= csrf_field() ?>
                <button type="submit">退出</button>
            </form>
        </div>
    <?php endif; ?>
    <nav class="nav">
        <a class="<?= ($active ?? '') === 'overview' ? 'active' : '' ?>" href="/admin">概览</a>
        <a class="<?= ($active ?? '') === 'tenants' ? 'active' : '' ?>" href="/admin/tenants">租户管理</a>
        <a class="<?= ($active ?? '') === 'billing' ? 'active' : '' ?>" href="/admin/billing">费用管理</a>
        <a class="<?= ($active ?? '') === 'platforms' ? 'active' : '' ?>" href="/admin/platforms">平台授权</a>
        <a class="<?= ($active ?? '') === 'announcements' ? 'active' : '' ?>" href="/admin/announcements">系统公告</a>
        <a class="<?= ($active ?? '') === 'settings' ? 'active' : '' ?>" href="/admin/settings">系统设置</a>
        <a class="<?= ($active ?? '') === 'system' ? 'active' : '' ?>" href="/admin/system">系统状态</a>
        <div class="nav-section">快捷入口</div>
        <a href="/?tenant=erp">进入测试租户</a>
    </nav>
</aside>
<main class="main">
    <?= $content ?>
</main>
<script src="/assets/app.js?v=<?= e($assetVersion('/assets/app.js')) ?>"></script>
</body>
</html>
