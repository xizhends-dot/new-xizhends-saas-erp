<div class="page-head">
    <div>
        <h1>概览 <span class="sub">SaaS 平台运营总览</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/admin/tenants">租户列表</a>
        <a class="btn admin" href="/admin/platforms">平台授权</a>
    </div>
</div>

<section class="grid stats">
    <div class="stat"><div class="stat-label">租户公司</div><div class="stat-value"><?= e($stats['tenant_count']) ?></div><div class="stat-sub">全部开户注册</div></div>
    <div class="stat"><div class="stat-label">活跃租户</div><div class="stat-value"><?= e($stats['active_tenant_count']) ?></div><div class="stat-sub">可正常访问系统</div></div>
    <div class="stat"><div class="stat-label">员工总数</div><div class="stat-value"><?= e($stats['staff_count']) ?></div><div class="stat-sub">跨全部租户</div></div>
    <div class="stat"><div class="stat-label">平台授权</div><div class="stat-value"><?= e($stats['platform_auth_count']) ?></div><div class="stat-sub">已开通平台账号</div></div>
</section>

<section class="grid two-col">
    <div class="panel">
        <div class="panel-head"><span>最近租户</span><span class="sub">按示例数据排序</span></div>
        <div class="panel-body">
            <table class="table">
                <thead><tr><th>公司</th><th>子域名</th><th>套餐</th><th>状态</th><th>积分余额</th></tr></thead>
                <tbody>
                <?php foreach ($tenants as $tenant): ?>
                    <tr>
                        <td><?= e($tenant['company_name']) ?></td>
                        <td><?= e($tenant['subdomain']) ?>.xizhends.com</td>
                        <td><?= e($tenant['plan']) ?></td>
                        <td><span class="tag <?= $tenant['status'] === 'active' ? 'green' : 'gray' ?>"><?= e($tenant['status']) ?></span></td>
                        <td><strong><?= e(number_format((int) $tenant['balance'])) ?>pt</strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head"><span>系统状态</span><span class="sub">开发环境</span></div>
        <div class="panel-body">
            <table class="table">
                <tbody>
                <tr><th>PHP</th><td><?= e(PHP_VERSION) ?></td></tr>
                <tr><th>数据存储</th><td><?= e($diagnostics['effective_driver']) ?></td></tr>
                <tr><th>MySQL 状态</th><td><?= e($diagnostics['fallback_reason'] ?? '可用') ?></td></tr>
                <tr><th>运行模式</th><td>PHP SaaS 主线</td></tr>
                <tr><th>调试模式</th><td><a class="btn" href="/admin/settings">前往设置</a></td></tr>
                <tr><th>详情</th><td><a class="btn" href="/admin/system">查看系统状态</a></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</section>
