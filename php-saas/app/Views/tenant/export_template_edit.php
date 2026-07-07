<?php
$template = is_array($exportTemplate ?? null) ? $exportTemplate : (is_array($template ?? null) ? $template : null);
$fieldGroups = is_array($fieldGroups ?? null) ? $fieldGroups : [];
$errors = is_array($errors ?? null) ? $errors : [];
$returnUrl = is_string($returnUrl ?? null) && $returnUrl !== '' ? $returnUrl : '/import-export/non-excel?tenant=' . rawurlencode((string) $tenantKey);
$columns = array_values((array) ($template['columns'] ?? []));
$selectedPlatforms = array_values(array_filter(array_map('strval', is_array($template['platforms'] ?? null) ? $template['platforms'] : [])));
$platformOptions = [
    'r' => 'Rakuten',
    'y' => 'Yahoo',
    'yp' => '雅拍',
    'w' => 'Wowma',
    'm' => 'Mercari',
    'q' => 'Qoo10',
];
// JSON 内嵌 <script>:必须 HEX_TAG 转义,防止列显示名里的 </script> 造成存储型 XSS
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$columnsJson = json_encode($columns, $jsonFlags) ?: '[]';
$fieldGroupsJson = json_encode($fieldGroups, $jsonFlags) ?: '{}';
?>
<div class="page-head">
    <div><h1><?= e($template === null || ($template['id'] ?? '') === '' ? '新建导出模板' : '编辑导出模板') ?> <span class="sub">选择字段 → 调整顺序 → 保存</span></h1></div>
</div>

<?php if ($errors): ?>
    <div class="panel export-template-errors"><div class="panel-body">
        <?php foreach ($errors as $error): ?><div class="setting-muted"><?= e($error) ?></div><?php endforeach; ?>
    </div></div>
<?php endif; ?>

<form method="post" action="/import-export/export-templates/save" id="tpl-form" class="export-template-editor">
    <?= csrf_field() ?>
    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
    <input type="hidden" name="id" value="<?= e($template['id'] ?? '') ?>">
    <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
    <input type="hidden" name="columns_json" id="columns-json" value="">

    <div class="panel">
        <div class="panel-head"><span>基本信息</span></div>
        <div class="panel-body export-template-basic">
            <label><span>模板名称</span><input type="text" name="name" maxlength="64" required value="<?= e($template['name'] ?? '') ?>"></label>
            <label><span>导出格式</span>
                <select name="format">
                    <option value="xlsx" <?= ($template['format'] ?? 'xlsx') === 'xlsx' ? 'selected' : '' ?>>XLSX(图片嵌入)</option>
                    <option value="csv" <?= ($template['format'] ?? '') === 'csv' ? 'selected' : '' ?>>CSV</option>
                </select>
            </label>
            <div class="export-template-platforms">
                <div class="export-template-platforms-head">
                    <span>适用平台</span>
                    <em id="platform-empty-hint">不选则全平台通用</em>
                </div>
                <div class="export-platform-chip-list" id="platform-chip-list">
                    <?php foreach ($platformOptions as $code => $label): ?>
                        <?php $selected = in_array($code, $selectedPlatforms, true); ?>
                        <button
                            type="button"
                            class="field-chip platform-chip<?= $selected ? ' selected' : '' ?>"
                            data-platform-chip
                            data-platform="<?= e($code) ?>"
                            aria-pressed="<?= e($selected ? 'true' : 'false') ?>"
                        >
                            <span class="field-chip-mark">✓</span>
                            <span><?= e($label) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div id="platform-inputs">
                    <?php foreach ($selectedPlatforms as $code): ?>
                        <input type="hidden" name="platforms[]" value="<?= e($code) ?>">
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="panel export-template-workbench">
        <div class="panel-head"><span>列配置</span><span class="sub">字段以标签方式选择，已选列可改名与排序</span></div>
        <div class="panel-body">
            <section class="export-template-picker" aria-label="字段选择">
                <div class="export-template-picker-head">
                    <div>
                        <strong>字段库</strong>
                        <span class="sub">点击字段加入导出列，再次点击移除</span>
                    </div>
                    <label class="export-field-search">
                        <span>搜索字段</span>
                        <input type="search" id="field-search" placeholder="输入字段名，例如 电话 / 运单 / 金额">
                    </label>
                </div>

                <div class="export-field-groups">
                    <?php foreach ($fieldGroups as $group => $fields): ?>
                        <section class="export-field-group" data-field-group>
                            <h3><?= e($group) ?></h3>
                            <div class="export-field-chip-list">
                                <?php foreach ((array) $fields as $field): ?>
                                    <?php
                                    $fieldLabel = (string) ($field['label'] ?? '');
                                    $fieldSearch = function_exists('mb_strtolower') ? mb_strtolower($fieldLabel) : strtolower($fieldLabel);
                                    ?>
                                    <button
                                        type="button"
                                        class="field-chip"
                                        data-field-chip
                                        data-key="<?= e($field['key'] ?? '') ?>"
                                        data-label="<?= e($fieldLabel) ?>"
                                        data-search="<?= e($fieldSearch) ?>"
                                        aria-pressed="false"
                                    >
                                        <span class="field-chip-mark">✓</span>
                                        <span><?= e($field['label'] ?? '') ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="export-template-selected" aria-label="已选列">
                <div class="export-template-selected-head">
                    <div>
                        <strong>已选列</strong>
                        <span class="sub" id="selected-count">0 列</span>
                    </div>
                    <div class="export-template-inline-actions">
                        <button class="btn" type="button" data-panel-toggle="const-panel">+ 固定值列</button>
                        <button class="btn" type="button" data-panel-toggle="raw-panel">+ 原始字段列</button>
                    </div>
                </div>

                <div class="export-inline-panels">
                    <div class="inline-add-panel" id="const-panel" hidden>
                        <label><span>列名</span><input type="text" id="const-label" maxlength="64" placeholder="例如 国家"></label>
                        <label><span>固定值</span><input type="text" id="const-value" placeholder="每行输出的固定内容，可留空"></label>
                        <button class="btn primary" type="button" id="confirm-const">添加固定值列</button>
                    </div>
                    <div class="inline-add-panel" id="raw-panel" hidden>
                        <label><span>原始字段路径</span><input type="text" id="raw-path" placeholder="order./item./customer. 开头，例如 item.tabaono"></label>
                        <label><span>列名</span><input type="text" id="raw-label" maxlength="64" placeholder="留空则使用字段路径"></label>
                        <button class="btn primary" type="button" id="confirm-raw">添加原始字段列</button>
                        <span class="inline-panel-error" id="raw-error" aria-live="polite"></span>
                    </div>
                </div>

                <div class="export-template-table-wrap">
                    <table class="table export-template-columns-table" id="columns-table">
                        <thead><tr><th>#</th><th>来源</th><th>显示名</th><th>操作</th></tr></thead>
                        <tbody></tbody>
                    </table>
                    <div class="empty slim" id="columns-empty">还没有选择字段。</div>
                </div>

                <div class="export-template-submit-row">
                    <button class="btn primary" type="submit">保存模板</button>
                    <button class="btn" type="button" id="preview-btn">导出预览(前3行)</button>
                    <a class="btn" href="<?= e($returnUrl) ?>">返回</a>
                </div>
                <div id="preview-area" class="export-template-preview"></div>
            </section>
        </div>
    </div>
</form>

<script>
(function () {
    'use strict';
    var columns = <?= $columnsJson ?>;
    var fieldGroups = <?= $fieldGroupsJson ?>;
    var tenant = <?= json_encode((string) $tenantKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var tbody = document.querySelector('#columns-table tbody');
    var form = document.getElementById('tpl-form');
    var searchInput = document.getElementById('field-search');
    var selectedCount = document.getElementById('selected-count');
    var emptyState = document.getElementById('columns-empty');
    var rawError = document.getElementById('raw-error');
    var fieldLabels = {};

    Object.keys(fieldGroups || {}).forEach(function (group) {
        (fieldGroups[group] || []).forEach(function (field) {
            fieldLabels[field.key] = field.label || field.key;
        });
    });

    function sourceText(col) {
        if (col.type === 'field') { return '字段:' + (fieldLabels[col.key] || col.key || ''); }
        if (col.type === 'const') { return '固定值:' + (col.value || '(空)'); }
        return '原始:' + (col.path || '');
    }

    function esc(value) {
        var div = document.createElement('div');
        div.textContent = String(value == null ? '' : value);
        return div.innerHTML;
    }

    function escAttr(value) {
        return esc(value).replace(/"/g, '&quot;');
    }

    function selectedFieldKeys() {
        return columns.filter(function (col) { return col.type === 'field'; }).map(function (col) { return col.key; });
    }

    function syncChips() {
        var selected = selectedFieldKeys();
        document.querySelectorAll('[data-field-chip]').forEach(function (chip) {
            var on = selected.indexOf(chip.getAttribute('data-key')) !== -1;
            chip.classList.toggle('selected', on);
            chip.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    function syncPlatformChips() {
        var selected = [];
        document.querySelectorAll('[data-platform-chip]').forEach(function (chip) {
            if (chip.classList.contains('selected')) {
                selected.push(chip.getAttribute('data-platform') || '');
            }
            chip.setAttribute('aria-pressed', chip.classList.contains('selected') ? 'true' : 'false');
        });
        var inputs = document.getElementById('platform-inputs');
        inputs.innerHTML = '';
        selected.filter(Boolean).forEach(function (code) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'platforms[]';
            input.value = code;
            inputs.appendChild(input);
        });
        document.getElementById('platform-empty-hint').textContent = selected.length === 0 ? '不选则全平台通用' : '仅在所选平台订单页显示';
    }

    function renderRows() {
        tbody.innerHTML = '';
        columns.forEach(function (col, i) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<td class="column-index">' + (i + 1) + '</td>'
                + '<td class="column-source">' + esc(sourceText(col)) + '</td>'
                + '<td><input type="text" maxlength="64" value="' + escAttr(col.label || '') + '" data-i="' + i + '" class="col-label"></td>'
                + '<td><div class="column-row-actions">'
                + '<button type="button" class="icon-btn mv" data-i="' + i + '" data-d="-1" aria-label="上移" title="上移">↑</button>'
                + '<button type="button" class="icon-btn mv" data-i="' + i + '" data-d="1" aria-label="下移" title="下移">↓</button>'
                + '<button type="button" class="icon-btn danger rm" data-i="' + i + '" aria-label="删除" title="删除">✕</button>'
                + '</div></td>';
            tbody.appendChild(tr);
        });
        selectedCount.textContent = columns.length + ' 列';
        emptyState.hidden = columns.length > 0;
        syncChips();
    }

    function toggleField(key, label) {
        var exists = columns.some(function (col) { return col.type === 'field' && col.key === key; });
        if (exists) {
            columns = columns.filter(function (col) { return !(col.type === 'field' && col.key === key); });
        } else {
            columns.push({ type: 'field', key: key, label: label || key });
        }
        renderRows();
    }

    function filterChips() {
        var keyword = (searchInput.value || '').trim().toLowerCase();
        document.querySelectorAll('[data-field-group]').forEach(function (group) {
            var visibleCount = 0;
            group.querySelectorAll('[data-field-chip]').forEach(function (chip) {
                var label = (chip.getAttribute('data-search') || '').toLowerCase();
                var show = keyword === '' || label.indexOf(keyword) !== -1;
                chip.hidden = !show;
                if (show) { visibleCount += 1; }
            });
            group.hidden = visibleCount === 0;
        });
    }

    document.querySelectorAll('[data-field-chip]').forEach(function (chip) {
        chip.addEventListener('click', function () {
            toggleField(chip.getAttribute('data-key') || '', chip.getAttribute('data-label') || '');
        });
    });

    searchInput.addEventListener('input', filterChips);

    document.querySelectorAll('[data-platform-chip]').forEach(function (chip) {
        chip.addEventListener('click', function () {
            chip.classList.toggle('selected');
            syncPlatformChips();
        });
    });

    document.querySelectorAll('[data-panel-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var panel = document.getElementById(button.getAttribute('data-panel-toggle') || '');
            if (!panel) { return; }
            var shouldOpen = panel.hidden;
            document.querySelectorAll('.inline-add-panel').forEach(function (item) { item.hidden = true; });
            panel.hidden = !shouldOpen;
            if (shouldOpen) {
                var input = panel.querySelector('input');
                if (input) { input.focus(); }
            }
        });
    });

    document.getElementById('confirm-const').addEventListener('click', function () {
        var label = (document.getElementById('const-label').value || '').trim();
        var value = document.getElementById('const-value').value || '';
        if (label === '') {
            document.getElementById('const-label').focus();
            return;
        }
        columns.push({ type: 'const', label: label.slice(0, 64), value: value });
        document.getElementById('const-label').value = '';
        document.getElementById('const-value').value = '';
        document.getElementById('const-panel').hidden = true;
        renderRows();
    });

    document.getElementById('confirm-raw').addEventListener('click', function () {
        var path = (document.getElementById('raw-path').value || '').trim();
        var label = (document.getElementById('raw-label').value || '').trim();
        rawError.textContent = '';
        if (!/^(order|item|customer)\..+/.test(path)) {
            rawError.textContent = '路径必须以 order./item./customer. 开头。';
            document.getElementById('raw-path').focus();
            return;
        }
        columns.push({ type: 'raw', path: path, label: (label || path).slice(0, 64) });
        document.getElementById('raw-path').value = '';
        document.getElementById('raw-label').value = '';
        document.getElementById('raw-panel').hidden = true;
        renderRows();
    });

    tbody.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof HTMLElement)) { return; }
        var i = parseInt(target.getAttribute('data-i') || '-1', 10);
        if (target.classList.contains('rm') && i >= 0) {
            columns.splice(i, 1);
            renderRows();
        } else if (target.classList.contains('mv') && i >= 0) {
            var j = i + parseInt(target.getAttribute('data-d'), 10);
            if (j >= 0 && j < columns.length) {
                var tmp = columns[i]; columns[i] = columns[j]; columns[j] = tmp;
                renderRows();
            }
        }
    });

    tbody.addEventListener('input', function (event) {
        if (event.target.classList.contains('col-label')) {
            var i = parseInt(event.target.getAttribute('data-i'), 10);
            if (columns[i]) { columns[i].label = event.target.value; }
        }
    });

    form.addEventListener('submit', function () {
        document.getElementById('columns-json').value = JSON.stringify(columns);
    });

    document.getElementById('preview-btn').addEventListener('click', function () {
        var body = new URLSearchParams();
        body.set('tenant', tenant);
        body.set('columns_json', JSON.stringify(columns));
        fetch('/import-export/export-templates/preview?tenant=' + encodeURIComponent(tenant), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').content : ''
            },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (response) { return response.json(); }).then(function (data) {
            var area = document.getElementById('preview-area');
            if (!data.ok) {
                area.innerHTML = '<div class="setting-muted export-template-preview-error">' + esc((data.errors || []).join(';')) + '</div>';
                return;
            }
            var html = '<table class="table"><thead><tr>';
            (data.headers || []).forEach(function (header) { html += '<th>' + esc(header) + '</th>'; });
            html += '</tr></thead><tbody>';
            (data.rows || []).forEach(function (row) {
                html += '<tr>';
                row.forEach(function (cell) { html += '<td>' + esc(cell) + '</td>'; });
                html += '</tr>';
            });
            html += (data.rows && data.rows.length ? '' : '<tr><td class="sub">当前筛选没有数据。</td></tr>') + '</tbody></table>';
            area.innerHTML = html;
        }).catch(function () {
            document.getElementById('preview-area').innerHTML = '<div class="setting-muted export-template-preview-error">预览请求失败。</div>';
        });
    });

    renderRows();
    filterChips();
    syncPlatformChips();
})();
</script>
