<div class="page-head">
    <div>
        <h1>定时任务状态 <span class="sub">租户只查看执行状态，调度由超管维护</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/settings?tenant=<?= e($tenantKey) ?>">返回设置</a>
    </div>
</div>

<div class="notice">定时任务属于平台级能力。租户可以查看订单同步、物流同步、邮件同步是否正常，但不能修改频率、关闭任务或配置失败重试。</div>

<div class="panel">
    <div class="panel-head"><span>任务清单</span><span class="sub"><?= e(count($jobs)) ?> 个平台任务</span></div>
    <div class="panel-body">
        <table class="table">
            <thead><tr><th>任务</th><th>旧脚本</th><th>建议频率</th><th>租户可见状态</th><th>权限边界</th></tr></thead>
            <tbody>
            <?php foreach ($jobs as $job): ?>
                <tr>
                    <td><?= e($job['name']) ?></td>
                    <td><code><?= e($job['old']) ?></code></td>
                    <td><?= e($job['schedule']) ?></td>
                    <td><span class="tag gray"><?= e($job['status']) ?></span></td>
                    <td>只读；由超管配置调度</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
