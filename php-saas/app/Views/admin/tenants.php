<div class="page-head">
    <div>
        <h1>租户管理 <span class="sub">管理所有公司、数据库与套餐</span></h1>
    </div>
    <div class="head-actions">
        <button class="btn admin" type="button">新建租户</button>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><span>租户列表</span><span class="sub"><?= e(count($tenants)) ?> 家公司</span></div>
    <div class="panel-body">
        <table class="table">
            <thead>
            <tr>
                <th>公司</th>
                <th>子域名</th>
                <th>数据库</th>
                <th>套餐</th>
                <th>平台授权</th>
                <th>员工</th>
                <th>积分余额</th>
                <th>状态</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($tenants as $tenant): ?>
                <tr>
                    <td>
                        <strong><?= e($tenant['company_name']) ?></strong>
                        <div class="sub"><?= e($tenant['contact']) ?> · <?= e($tenant['phone']) ?></div>
                    </td>
                    <td><?= e($tenant['subdomain']) ?>.xizhends.com</td>
                    <td><?= e($tenant['db_name']) ?></td>
                    <td><?= e($tenant['plan']) ?></td>
                    <td>
                        <?php foreach ($tenant['platform_labels'] as $label): ?>
                            <span class="tag blue"><?= e($label) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td><?= e($tenant['staff_count']) ?></td>
                    <td><strong><?= e(number_format((int) $tenant['balance'])) ?>pt</strong></td>
                    <td><span class="tag <?= $tenant['status'] === 'active' ? 'green' : 'gray' ?>"><?= e($tenant['status']) ?></span></td>
                    <td>
                        <a class="btn" href="/admin/platforms?tenant=<?= e($tenant['key']) ?>">授权</a>
                        <a class="btn" href="/admin/billing?tenant=<?= e($tenant['key']) ?>">费用</a>
                        <a class="btn" href="/?tenant=<?= e($tenant['key']) ?>">进入</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
