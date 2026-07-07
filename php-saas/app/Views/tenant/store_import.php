<div class="page-head">
    <div>
        <h1>店铺订单导入 <span class="sub">CSV / XLSX 平台订单写入</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/stores?tenant=<?= e($tenantKey) ?>">返回店铺管理</a>
    </div>
</div>

<?php if (trim((string) ($message ?? '')) !== ''): ?>
    <div class="notice ok slim"><?= e($message) ?></div>
<?php endif; ?>

<div class="grid">
    <section class="panel">
        <div class="panel-head"><span>目标店铺</span><span class="sub">导入记录将强制归属此店铺</span></div>
        <div class="panel-body">
            <table class="table">
                <tbody>
                    <tr><th>平台</th><td><?= e($platformName ?? ($store['platform'] ?? '')) ?></td></tr>
                    <tr><th>店铺缩写</th><td><?= e($store['short'] ?? '') ?></td></tr>
                    <tr><th>店铺全称</th><td><?= e($store['name'] ?? '') ?></td></tr>
                    <tr><th>API 状态</th><td><span class="tag <?= ($store['api_status'] ?? '') === '已配置' ? 'green' : 'gray' ?>"><?= e($store['api_status'] ?? '未配置') ?></span></td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><span>上传订单文件</span><span class="sub">店铺列为空或匹配当前店铺时写入</span></div>
        <div class="panel-body">
            <form class="form-grid" method="post" action="/stores/import" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                <input type="hidden" name="id" value="<?= e($store['id'] ?? 0) ?>">
                <label class="wide">
                    <span>导入文件</span>
                    <input type="file" name="csv_file" accept=".csv,.xls,.xlsx,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                </label>
                <div class="setting-muted wide">支持 10MB 以内 CSV / 制表符文本 / XLS / XLSX。平台订单导入会识别订单号、商品、客户、金额、店铺等列。</div>
                <div class="setting-muted wide">文件中的“店铺”列为空时归属当前店铺；非空且不等于当前店铺全称或缩写时，该行会被跳过。</div>
                <div class="form-submit wide"><button class="btn primary" type="submit">导入订单</button></div>
            </form>
        </div>
    </section>
</div>
