<div class="page-head">
    <div>
        <h1>系统状态 <span class="sub">数据层、PHP 扩展与迁移来源</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn admin" href="/admin">返回概览</a>
    </div>
</div>

<section class="grid two-col">
    <div class="panel">
        <div class="panel-head"><span>数据存储</span><span class="sub"><?= e($diagnostics['effective_driver']) ?></span></div>
        <div class="panel-body">
            <table class="table">
                <tbody>
                <tr><th>请求驱动</th><td><?= e($diagnostics['requested_driver']) ?></td></tr>
                <tr><th>实际驱动</th><td><?= e($diagnostics['effective_driver']) ?></td></tr>
                <tr><th>回退原因</th><td><?= e($diagnostics['fallback_reason'] ?? '无') ?></td></tr>
                <tr><th>JSON 文件</th><td><code><?= e($diagnostics['json_path']) ?></code></td></tr>
                <tr><th>MySQL DSN</th><td><code><?= e($diagnostics['mysql_dsn']) ?></code></td></tr>
                <tr><th>MySQL 用户</th><td><?= e($diagnostics['mysql_user']) ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="panel">
        <div class="panel-head"><span>PHP 扩展</span><span class="sub">影响 MySQL、邮件与外部同步</span></div>
        <div class="panel-body">
            <table class="table">
                <tbody>
                <?php foreach ($diagnostics['extensions'] as $name => $enabled): ?>
                    <tr><th><?= e($name) ?></th><td><span class="tag <?= $enabled ? 'green' : 'red' ?>"><?= $enabled ? '可用' : '缺失' ?></span></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<div class="panel">
    <div class="panel-head"><span>迁移来源</span><span class="sub">Rust SQL 可以被 PHP 直接沿用</span></div>
    <div class="panel-body">
        <table class="table">
            <thead><tr><th>范围</th><th>文件</th><th>用途</th></tr></thead>
            <tbody>
            <?php foreach ($diagnostics['schema_sources'] as $name => $source): ?>
                <tr><td><?= e($name) ?></td><td><code><?= e($source) ?></code></td><td>作为 PHP 新系统的数据契约与迁移依据</td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><span>SaaS 权限边界</span><span class="sub">超管设置与租户设置分离</span></div>
    <div class="panel-body">
        <table class="table">
            <thead><tr><th>设置范围</th><th>归属</th><th>说明</th></tr></thead>
            <tbody>
            <tr><td>租户创建、套餐、停用、余额</td><td><span class="tag red">超管</span></td><td>影响整套 SaaS 资源和计费，租户不可自行修改。</td></tr>
            <tr><td>平台授权、平台锁定、平台级 API 开关</td><td><span class="tag red">超管</span></td><td>决定租户是否能使用 Yahoo / 乐天 / Wowma 等平台。</td></tr>
            <tr><td>定时任务频率、启停、失败重试</td><td><span class="tag red">超管</span></td><td>订单同步、物流同步、邮件同步属于平台基础设施。</td></tr>
            <tr><td>公司名称、联系人、业务备注</td><td><span class="tag green">租户管理员</span></td><td>租户可自行维护公司资料。</td></tr>
            <tr><td>店铺新增、隐藏店铺、员工店铺范围</td><td><span class="tag green">租户管理员</span></td><td>可授权给有“店铺新增”或“员工管理”权限的员工。</td></tr>
            <tr><td>采购、客服、品检角色权限</td><td><span class="tag green">租户管理员</span></td><td>员工操作权限在租户内生效，不影响超管。</td></tr>
            <tr><td>开发阶段权限拦截</td><td><span class="tag blue">默认通过</span></td><td>当前按你的要求采用全权限开发模式，不阻断页面和接口；权限 key 与角色矩阵会保留，方便上线前切严格模式。</td></tr>
            </tbody>
        </table>
    </div>
</div>
