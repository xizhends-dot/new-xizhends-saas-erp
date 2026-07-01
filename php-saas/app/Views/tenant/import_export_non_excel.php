<?php
$platformVariants = is_array($platformVariants ?? null) ? $platformVariants : [];
$previewDatasets = is_array($previewDatasets ?? null) ? $previewDatasets : [];
$importPreviews = is_array($importPreviews ?? null) ? $importPreviews : [];
$excelRequirements = is_array($excelRequirements ?? null) ? $excelRequirements : [];
$stores = is_array($stores ?? null) ? $stores : [];
$json = static fn (mixed $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '[]';
?>
<div class="page-head">
    <div>
        <h1>非 Excel 导入导出 <span class="sub">平台 CSV / 财务匹配 / 国际运单 / YD 表</span></h1>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><span>平台专用 CSV</span><span class="sub">旧 HTML Excel 模板的字段迁移</span></div>
    <div class="panel-body">
        <table class="table">
            <thead><tr><th>类型</th><th>来源</th><th>平台</th><th>说明</th></tr></thead>
            <tbody>
            <?php foreach ($platformVariants as $key => $variant): ?>
                <tr>
                    <td><?= e($key) ?> / <?= e($variant['name'] ?? '') ?></td>
                    <td><?= e($variant['source'] ?? '') ?></td>
                    <td><?= e($variant['platform'] ?? '') ?></td>
                    <td><?= e($variant['note'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$platformVariants): ?>
                <tr><td colspan="4" class="sub">暂无平台专用 CSV 任务。</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php foreach ($previewDatasets as $dataset): ?>
    <div class="panel">
        <div class="panel-head">
            <span><?= e($dataset['name'] ?? 'CSV 预览') ?></span>
            <span class="sub"><?= e($dataset['filename'] ?? '') ?></span>
        </div>
        <div class="panel-body">
            <div class="setting-muted"><?= e($dataset['note'] ?? '') ?></div>
            <table class="table">
                <thead>
                <tr>
                    <?php foreach (($dataset['headers'] ?? []) as $header): ?>
                        <th><?= e($header) ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach (array_slice((array) ($dataset['rows'] ?? []), 0, 5) as $row): ?>
                    <tr>
                        <?php foreach ((array) $row as $cell): ?>
                            <td><?= e($cell) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($dataset['rows'])): ?>
                    <tr><td colspan="<?= e(max(1, count((array) ($dataset['headers'] ?? [])))) ?>" class="sub">没有可预览的数据。</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>

<div class="panel">
    <div class="panel-head"><span>导入解析预览</span><span class="sub">服务输出结构，落库由主控接 Store</span></div>
    <div class="panel-body">
        <div class="import-export-grid">
            <?php foreach ([
                ['title' => '财务数据导入', 'preview' => '/import-export/finance-import/preview', 'import' => '/import-export/finance-import/import', 'note' => '按运单号精确/前缀/后缀/中间匹配，写入重量、国际运费、国内金额。'],
                ['title' => '国际运单追加/覆盖', 'preview' => '/import-export/shipping-modes/preview', 'import' => '/import-export/shipping-modes/import', 'note' => '2/3/6/7 列格式；第三列为 1 时覆盖，否则追加去重。'],
                ['title' => '日本仓 YD 表导入', 'preview' => '/import-export/jp-warehouse/preview', 'import' => '/import-export/jp-warehouse/import', 'note' => '按运单匹配当前账号可访问订单，写入件数、重量、国际运费、分配/出库字段。'],
                ['title' => '外部运单/订单插入', 'preview' => '/import-export/external-insert/preview', 'import' => '/import-export/external-insert/import', 'note' => '按平台、订单号、运单号插入外部记录。', 'needs_store' => true],
            ] as $form): ?>
                <form class="panel mini-import-panel" method="post" action="<?= e($form['preview']) ?>" enctype="multipart/form-data">
                    <div class="panel-head"><span><?= e($form['title']) ?></span><span class="sub">CSV</span></div>
                    <div class="panel-body">
                        <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                        <?php if (!empty($form['needs_store'])): ?>
                            <label class="wide">
                                <span>目标店铺</span>
                                <select name="store_id" required>
                                    <option value="">请选择</option>
                                    <?php foreach ($stores as $store): ?>
                                        <option value="<?= e($store['id'] ?? 0) ?>"><?= e(($store['name'] ?? '') ?: ($store['short'] ?? '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <?php endif; ?>
                        <label class="wide">
                            <span>文件</span>
                            <input type="file" name="csv_file" accept=".csv,.xls,text/csv,text/plain">
                        </label>
                        <div class="setting-muted"><?= e($form['note']) ?></div>
                        <div class="head-actions" style="justify-content:flex-start;">
                            <button class="btn" type="submit" formaction="<?= e($form['preview']) ?>">预览 JSON</button>
                            <button class="btn primary" type="submit" formaction="<?= e($form['import']) ?>">确认导入</button>
                        </div>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
        <?php foreach ($importPreviews as $name => $preview): ?>
            <h3><?= e($name) ?></h3>
            <pre class="import-preview"><code><?= e($json($preview)) ?></code></pre>
        <?php endforeach; ?>
        <?php if (!$importPreviews): ?>
            <div class="empty slim">暂无导入解析预览。</div>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><span>已迁移的 Excel 项</span><span class="sub">PhpSpreadsheet XLSX</span></div>
    <div class="panel-body">
        <table class="table">
            <thead><tr><th>项目</th><th>原因</th><th>旧系统来源</th></tr></thead>
            <tbody>
            <?php foreach ($excelRequirements as $item): ?>
                <tr>
                    <td><?= e($item['item'] ?? $item) ?></td>
                    <td><?= e($item['reason'] ?? '') ?></td>
                    <td><?= e($item['old_source'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$excelRequirements): ?>
                <tr><td colspan="3" class="sub">当前没有登记 Excel 库依赖项。</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
