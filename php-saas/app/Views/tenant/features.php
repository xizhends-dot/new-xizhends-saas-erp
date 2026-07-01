<div class="page-head">
    <div>
        <h1>功能工作台 <span class="sub">按旧系统模块补齐的新 SaaS 入口</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/settings?tenant=<?= e($tenantKey) ?>">配置 API</a>
        <a class="btn primary" href="/search?tenant=<?= e($tenantKey) ?>">全局搜索</a>
    </div>
</div>

<?php foreach ($groups as $groupName => $features): ?>
    <section class="panel feature-section">
        <div class="panel-head">
            <span><?= e($groupName) ?></span>
            <span class="sub"><?= e(count($features)) ?> 个入口</span>
        </div>
        <div class="feature-grid">
            <?php foreach ($features as $feature): ?>
                <a class="feature-card" href="<?= e($feature['href']) ?>">
                    <div class="feature-title">
                        <span><?= e($feature['title']) ?></span>
                        <span class="tag <?= $feature['status'] === '已可用' ? 'green' : 'blue' ?>"><?= e($feature['status']) ?></span>
                    </div>
                    <p><?= e($feature['desc']) ?></p>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>
