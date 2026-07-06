<?php
/** @var array<string, mixed> $tenant */
$can = static fn (string $permission): bool => \Xizhen\Core\Permission::has($currentUser ?? null, $permission);
$canAny = static fn (array $permissions): bool => \Xizhen\Core\Permission::hasAny($currentUser ?? null, $permissions);
$featureEnabled = static fn (string $feature): bool => ($tenantFeatures[$feature] ?? true);
$assetVersion = static fn (string $path): string => (string) @filemtime(BASE_PATH . '/public' . $path);
$tenantParam = rawurlencode((string) $tenantKey);
$tenantUrl = static fn (string $path, array $params = []): string => $path . '?' . http_build_query(['tenant' => $tenantKey] + $params, '', '&', PHP_QUERY_RFC3986);
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title ?? '西阵订单系统') ?></title>
    <link rel="stylesheet" href="/assets/app.css?v=<?= e($assetVersion('/assets/app.css')) ?>">
</head>
<body class="tenant-shell">
<aside class="sidebar">
    <div class="brand">
        <div class="brand-mark"><?= e(substr((string) ($tenant['key'] ?? 'T'), 0, 2)) ?></div>
        <div>
            <div class="brand-name"><?= e($tenant['short_name'] ?? $tenant['company_name'] ?? '租户') ?></div>
            <div class="brand-sub"><?= e($tenant['company_name'] ?? '租户') ?> · <?= e($tenant['plan'] ?? 'Basic') ?></div>
        </div>
    </div>
    <?php if (!empty($currentUser)): ?>
        <div class="session-box">
            <div>
                <strong><?= e($currentUser['name'] ?? $currentUser['username'] ?? '员工') ?></strong>
                <span><?= e($currentUser['role'] ?? '员工') ?></span>
            </div>
            <form method="post" action="/logout" class="mini-form">
                <?= csrf_field() ?>
                <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                <button type="submit">退出</button>
            </form>
        </div>
    <?php endif; ?>

    <nav class="nav">
        <a class="<?= ($active ?? '') === 'dashboard' ? 'active' : '' ?>" href="/?tenant=<?= e($tenantParam) ?>">首页仪表盘</a>
        <?php if ($featureEnabled('orders.platform') && $can('订单查看') && !empty($menu)): ?>
        <div class="nav-section">平台订单</div>
            <?php foreach (($menu ?? []) as $item): ?>
                <?php $platformUrl = $tenantUrl('/orders', ['view' => 'platform', 'platform' => (string) ($item['code'] ?? '')]); ?>
                <a class="<?= ($active ?? '') === 'platform' && ($_GET['platform'] ?? '') === $item['code'] ? 'active' : '' ?> <?= $item['locked'] ? 'locked' : '' ?>"
                   href="<?= e($item['locked'] ? '#' : $platformUrl) ?>">
                    <span class="dot" style="background: <?= e($item['color']) ?>"></span>
                    <?= e($item['name']) ?>
                    <?php if ($item['locked']): ?><span class="lock">锁</span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
        <div class="nav-section">工作台</div>
        <a class="<?= ($active ?? '') === 'features' ? 'active' : '' ?>" href="/features?tenant=<?= e($tenantKey) ?>">功能工作台</a>
        <?php if ($featureEnabled('orders.search') && $can('订单查看')): ?><a class="<?= ($active ?? '') === 'search' ? 'active' : '' ?>" href="/search?tenant=<?= e($tenantKey) ?>">全局搜索</a><?php endif; ?>
        <div class="nav-section">采购</div>
        <?php if ($featureEnabled('orders.purchase') && $can('采购订单')): ?><a class="<?= ($active ?? '') === 'purchase' ? 'active' : '' ?>" href="/orders?tenant=<?= e($tenantKey) ?>&view=purchase">采购订单</a><?php endif; ?>
        <?php if ($featureEnabled('orders.jp') && $can('日本仓发货')): ?><a class="<?= ($active ?? '') === 'jp' ? 'active' : '' ?>" href="/orders?tenant=<?= e($tenantKey) ?>&view=jp">日本仓发货</a><?php endif; ?>
        <div class="nav-section">日志</div>
        <?php if ($featureEnabled('logistics.1688') && $canAny(['1688物流', '1688物流日志'])): ?><a class="<?= ($active ?? '') === 'logistics_1688' ? 'active' : '' ?>" href="/logistics/1688?tenant=<?= e($tenantKey) ?>">1688物流查询日志</a><?php endif; ?>
        <?php if ($featureEnabled('logistics.express') && $canAny(['1688物流', '物流查看'])): ?><a class="<?= ($active ?? '') === 'logistics_express' ? 'active' : '' ?>" href="/logistics/express?tenant=<?= e($tenantKey) ?>">Showapi物流查询日志</a><?php endif; ?>
        <?php if ($featureEnabled('logistics.jp') && $canAny(['日本物流日志', '物流查看'])): ?><a class="<?= ($active ?? '') === 'logistics_jp' ? 'active' : '' ?>" href="/logistics/jp?tenant=<?= e($tenantKey) ?>">国际物流查询日志</a><?php endif; ?>
        <div class="nav-section">经营</div>
        <?php if ($featureEnabled('analytics.profit') && $can('利润分析')): ?><a class="<?= ($active ?? '') === 'profit' ? 'active' : '' ?>" href="/analytics/profit?tenant=<?= e($tenantKey) ?>">利润核算分析</a><?php endif; ?>
        <?php if ($featureEnabled('stats.performance') && $can('业绩统计')): ?><a class="<?= ($active ?? '') === 'performance' ? 'active' : '' ?>" href="/performance?tenant=<?= e($tenantKey) ?>">业绩面板</a><?php endif; ?>
        <?php if ($featureEnabled('stats.products') && $can('出单商品统计')): ?><a class="<?= ($active ?? '') === 'product_analysis' ? 'active' : '' ?>" href="/performance/products?tenant=<?= e($tenantKey) ?>">出单商品分析</a><?php endif; ?>
        <?php if ($featureEnabled('stats.purchase') && $can('采购统计')): ?><a class="<?= ($active ?? '') === 'purchase_stats' ? 'active' : '' ?>" href="/stats/purchase?tenant=<?= e($tenantKey) ?>">采购业绩统计</a><?php endif; ?>
        <?php if ($featureEnabled('tools.price_calculator') && $can('核价计算器')): ?><a class="<?= ($active ?? '') === 'price_calculator' ? 'active' : '' ?>" href="/price-calculator?tenant=<?= e($tenantKey) ?>">核价计算器</a><?php endif; ?>
        <?php if ($featureEnabled('mail.center') && $can('邮件中心')): ?><a class="<?= ($active ?? '') === 'mail' ? 'active' : '' ?>" href="/mail?tenant=<?= e($tenantKey) ?>">邮件中心</a><?php endif; ?>
        <?php if ($featureEnabled('import_export.center') && $canAny(['导入导出', '采购导入导出'])): ?><a class="<?= ($active ?? '') === 'import_export' ? 'active' : '' ?>" href="/import-export?tenant=<?= e($tenantKey) ?>">导入导出</a><?php endif; ?>
        <div class="nav-section">管理</div>
        <?php if ($featureEnabled('account.password_edit')): ?><a class="<?= ($active ?? '') === 'password_edit' ? 'active' : '' ?>" href="/password/edit?tenant=<?= e($tenantKey) ?>">修改密码</a><?php endif; ?>
        <?php if ($featureEnabled('management.notices') && $canAny(['公告管理', '通知查看'])): ?><a class="<?= ($active ?? '') === 'tenant_notices' ? 'active' : '' ?>" href="/notices?tenant=<?= e($tenantKey) ?>">通知公告</a><?php endif; ?>
        <?php if ($featureEnabled('management.stores') && $can('店铺新增')): ?><a class="<?= ($active ?? '') === 'stores' ? 'active' : '' ?>" href="/stores?tenant=<?= e($tenantKey) ?>">店铺管理</a><?php endif; ?>
        <?php if (!empty($currentUser['is_company_admin']) || \Xizhen\Core\Permission::normalizeRole($currentUser['role'] ?? '') === '公司管理员'): ?><a class="<?= ($active ?? '') === 'billing' ? 'active' : '' ?>" href="/billing?tenant=<?= e($tenantKey) ?>">积分账单</a><?php endif; ?>
        <?php if ($featureEnabled('management.users') && $can('员工管理')): ?><a class="<?= ($active ?? '') === 'users' ? 'active' : '' ?>" href="/users?tenant=<?= e($tenantKey) ?>">员工管理</a><?php endif; ?>
        <?php if ($featureEnabled('media.library') && $canAny(['图片管理', '图片上传', '图片删除'])): ?><a class="<?= ($active ?? '') === 'media' ? 'active' : '' ?>" href="/media?tenant=<?= e($tenantKey) ?>">租户图片库</a><?php endif; ?>
        <?php if ($featureEnabled('management.jobs')): ?><a class="<?= ($active ?? '') === 'jobs' ? 'active' : '' ?>" href="/jobs?tenant=<?= e($tenantKey) ?>">定时任务</a><?php endif; ?>
        <?php if ($featureEnabled('management.logs') && $can('订单日志')): ?><a class="<?= ($active ?? '') === 'logs' ? 'active' : '' ?>" href="/logs?tenant=<?= e($tenantKey) ?>">操作日志</a><?php endif; ?>
        <?php if ($featureEnabled('management.settings') && $canAny(['公司设置', '系统设置'])): ?><a class="<?= ($active ?? '') === 'settings' ? 'active' : '' ?>" href="/settings?tenant=<?= e($tenantKey) ?>">系统设置</a><?php endif; ?>
        <a href="/admin">SaaS 管理</a>
    </nav>
</aside>

<main class="main">
    <?= $content ?>
</main>

<script src="/assets/app.js?v=<?= e($assetVersion('/assets/app.js')) ?>"></script>
<script src="/assets/order-ajax.js?v=<?= e($assetVersion('/assets/order-ajax.js')) ?>"></script>
</body>
</html>
