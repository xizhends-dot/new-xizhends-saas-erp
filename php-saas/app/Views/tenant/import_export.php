<?php
$importJobs = array_values(array_filter($jobs, fn (array $job): bool => ($job['direction'] ?? '') === 'import'));
$exportJobs = array_values(array_filter($jobs, fn (array $job): bool => ($job['direction'] ?? '') === 'export'));
$hasImportJobs = count($importJobs) > 0;
$exportMap = [
    'platform_export' => 'platform',
    'delivery_notice_export' => 'delivery_notice',
    'purchase_export' => 'purchase',
    'shipment_export' => 'shipment',
    'finance_export' => 'finance',
    'customers_export' => 'customers',
    'logistics_export' => 'logistics',
];
?>
<div class="page-head">
    <div>
        <h1>导入导出 <span class="sub">CSV / 物流 / 财务 XLSX / 客户资料 XLSX</span></h1>
    </div>
    <div class="head-actions">
        <?php if ($hasImportJobs): ?><a class="btn primary" href="#csv-import-form">上传 CSV</a><?php endif; ?>
    </div>
</div>

<?php if (($importMessage ?? '') !== ''): ?>
    <div class="notice"><?= e($importMessage) ?></div>
<?php endif; ?>

<div class="import-export-grid">
    <section class="panel">
        <div class="panel-head"><span>CSV 导入</span><span class="sub">解析预览后写入订单、采购或国际运单</span></div>
        <div class="panel-body">
            <?php if ($hasImportJobs): ?>
            <form id="csv-import-form" class="form-grid" method="post" action="/import-export/import" enctype="multipart/form-data">
                <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                <label>
                    <span>导入类型</span>
                    <select name="job">
                        <?php foreach ($importJobs as $job): ?>
                            <option value="<?= e($job['key']) ?>"><?= e($job['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>平台</span>
                    <select name="platform">
                        <option value="">自动识别/不限</option>
                        <?php foreach (($platforms ?? []) as $code => $name): ?>
                            <option value="<?= e($code) ?>"><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>店铺</span>
                    <select name="store_id">
                        <option value="0">按 CSV 或默认店铺</option>
                        <?php foreach (($stores ?? []) as $store): ?>
                            <option value="<?= e($store['id'] ?? 0) ?>"><?= e(($store['name'] ?? '') ?: ($store['short'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="wide">
                    <span>导入文件</span>
                    <input type="file" name="csv_file" accept=".csv,.xls,text/csv,text/plain">
                </label>
                <div class="setting-muted wide">支持 3MB 以内 CSV / 制表符文本 / 旧系统导出的文本型 .xls。会先解析前 5 行预览，再按导入类型写入或更新订单数据。</div>
                <div class="form-submit"><button class="btn primary" type="submit">导入并记录</button></div>
            </form>
            <?php else: ?>
                <div class="empty slim">当前租户未开通可用的 CSV 导入任务。</div>
            <?php endif; ?>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><span>导出</span><span class="sub">财务与客户资料输出 XLSX，其余任务输出 CSV</span></div>
        <div class="panel-body export-list">
            <?php foreach ($exportJobs as $job): ?>
                <?php $type = $exportMap[$job['key']] ?? 'purchase'; ?>
                <div class="export-row">
                    <div>
                        <strong><?= e($job['name']) ?></strong>
                        <span><?= e($job['scope']) ?></span>
                    </div>
                    <a class="btn" href="/import-export/export?tenant=<?= e($tenantKey) ?>&type=<?= e($type) ?>"><?= in_array($type, ['finance', 'customers'], true) ? '下载 XLSX' : '下载 CSV' ?></a>
                </div>
            <?php endforeach; ?>
            <?php if (empty($exportJobs)): ?>
                <div class="empty slim">当前租户未开通可用的 CSV 导出任务。</div>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="panel">
    <div class="panel-head"><span>任务类型</span><span class="sub">旧系统导入导出迁移清单</span></div>
    <div class="panel-body">
        <table class="table">
            <thead><tr><th>名称</th><th>来源</th><th>范围</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($jobs as $job): ?>
                <tr>
                    <td><?= e($job['name']) ?></td>
                    <td><?= e($job['source']) ?></td>
                    <td><?= e($job['scope']) ?></td>
                    <td><span class="tag blue"><?= e($job['status']) ?></span></td>
                    <td>
                        <?php if (($job['direction'] ?? '') === 'import'): ?>
                            <a class="btn" href="#csv-import-form">上传</a>
                        <?php else: ?>
                            <a class="btn" href="/import-export/export?tenant=<?= e($tenantKey) ?>&type=<?= e($exportMap[$job['key']] ?? 'purchase') ?>">生成</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($jobs)): ?>
                <tr><td colspan="5" class="sub">当前租户未开通导入导出子功能。</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><span>最近导入导出日志</span><span class="sub"><?= e(count($logs ?? [])) ?> 条</span></div>
    <div class="panel-body">
        <table class="table">
            <thead><tr><th>时间</th><th>类型</th><th>任务</th><th>文件</th><th>行数</th><th>状态</th><th>说明</th></tr></thead>
            <tbody>
            <?php foreach (($logs ?? []) as $log): ?>
                <tr>
                    <td><?= e($log['created_at'] ?? '') ?></td>
                    <td><span class="tag gray"><?= e(($log['type'] ?? '') === 'export' ? '导出' : '导入') ?></span></td>
                    <td><?= e($log['name'] ?? '') ?></td>
                    <td><?= e($log['file_name'] ?? '') ?></td>
                    <td><?= e($log['rows'] ?? 0) ?></td>
                    <td><span class="tag <?= ($log['status'] ?? '') === '解析失败' ? 'red' : 'green' ?>"><?= e($log['status'] ?? '') ?></span></td>
                    <td><?= e($log['message'] ?? '') ?></td>
                </tr>
                <?php if (!empty($log['preview']) && is_array($log['preview'])): ?>
                    <tr class="log-preview-row">
                        <td></td>
                        <td colspan="6">
                            <div class="import-preview">
                                <?php foreach ($log['preview'] as $row): ?>
                                    <code><?= e(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></code>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if (empty($logs)): ?>
                <tr><td colspan="7" class="sub">暂无导入导出日志。</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
