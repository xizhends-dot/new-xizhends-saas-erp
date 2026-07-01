<div class="page-head">
    <div>
        <h1>租户图片库 <span class="sub">订单图片、上传附件与缓存清理</span></h1>
    </div>
    <div class="head-actions">
        <button class="btn primary" type="button">上传附件</button>
        <button class="btn" type="button">清理临时缓存</button>
    </div>
</div>

<div class="notice">图片管理不是全局图片仓库，而是当前公司独立的图片文件区。订单主图、SKU 图、采购凭证、客服截图和品检照片都必须按租户隔离存储，避免不同公司之间互相看到或覆盖。</div>

<div class="grid stats media-stats">
    <?php foreach ($library['summary'] as $item): ?>
        <div class="stat">
            <div class="stat-label"><?= e($item['label']) ?></div>
            <div class="stat-value"><?= e($item['value']) ?></div>
            <div class="stat-sub"><?= e($item['note']) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="panel detail-section">
    <div class="panel-head"><span>当前租户存储根目录</span><span class="sub">只服务 <?= e($tenant['company_name'] ?? $tenantKey) ?></span></div>
    <div class="panel-body">
        <code><?= e($library['base']) ?>/</code>
        <div class="setting-muted media-note">后续接入对象存储时，也应保留同样的租户前缀，例如 <code>tenants/<?= e($tenantKey) ?>/images/</code>。</div>
    </div>
</div>

<div class="media-grid">
    <?php foreach ($library['buckets'] as $bucket): ?>
        <div class="panel media-card">
            <div class="panel-head"><span><?= e($bucket['name']) ?></span><span class="tag blue"><?= e($bucket['status']) ?></span></div>
            <div class="panel-body">
                <div class="media-path"><code><?= e($bucket['path']) ?></code></div>
                <div class="setting-row"><span>业务范围</span><strong><?= e($bucket['scope']) ?></strong></div>
                <div class="setting-row"><span>写入来源</span><strong><?= e($bucket['owner']) ?></strong></div>
                <p class="setting-muted"><?= e($bucket['policy']) ?></p>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="panel">
    <div class="panel-head"><span>旧系统迁移对应</span><span class="sub">只作为迁移参考，不直接暴露旧路径</span></div>
    <div class="panel-body">
        <table class="table">
            <thead><tr><th>功能</th><th>旧系统参考</th><th>新系统租户路径</th></tr></thead>
            <tbody>
            <?php foreach ($library['references'] as $row): ?>
                <tr>
                    <td><strong><?= e($row['name']) ?></strong></td>
                    <td><code><?= e($row['old']) ?></code></td>
                    <td><code><?= e($row['new']) ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
