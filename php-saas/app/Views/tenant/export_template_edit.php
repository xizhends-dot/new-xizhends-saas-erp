<?php
$template = is_array($template ?? null) ? $template : null;
$fieldGroups = is_array($fieldGroups ?? null) ? $fieldGroups : [];
$errors = is_array($errors ?? null) ? $errors : [];
$columns = array_values((array) ($template['columns'] ?? []));
// JSON 内嵌 <script>:必须 HEX_TAG 转义,防止列显示名里的 </script> 造成存储型 XSS
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$columnsJson = json_encode($columns, $jsonFlags) ?: '[]';
?>
<div class="page-head">
    <div><h1><?= e($template === null || ($template['id'] ?? '') === '' ? '新建导出模板' : '编辑导出模板') ?> <span class="sub">勾选字段 → 调整顺序 → 保存</span></h1></div>
</div>

<?php if ($errors): ?>
    <div class="panel"><div class="panel-body">
        <?php foreach ($errors as $error): ?><div class="setting-muted" style="color:#c0392b;"><?= e($error) ?></div><?php endforeach; ?>
    </div></div>
<?php endif; ?>

<form method="post" action="/import-export/export-templates/save" id="tpl-form">
                <?= csrf_field() ?>
    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
    <input type="hidden" name="id" value="<?= e($template['id'] ?? '') ?>">
    <input type="hidden" name="columns_json" id="columns-json" value="">
    <div class="panel">
        <div class="panel-head"><span>基本信息</span></div>
        <div class="panel-body">
            <label><span>模板名称</span><input type="text" name="name" maxlength="64" required value="<?= e($template['name'] ?? '') ?>"></label>
            <label><span>导出格式</span>
                <select name="format">
                    <option value="xlsx" <?= ($template['format'] ?? 'xlsx') === 'xlsx' ? 'selected' : '' ?>>XLSX(图片嵌入)</option>
                    <option value="csv" <?= ($template['format'] ?? '') === 'csv' ? 'selected' : '' ?>>CSV</option>
                </select>
            </label>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head"><span>列配置</span><span class="sub">左侧勾选字段加入;右侧调序/改显示名/删除</span></div>
        <div class="panel-body" style="display:flex; gap:16px; align-items:flex-start;">
            <div style="flex:0 0 260px;">
                <?php foreach ($fieldGroups as $group => $fields): ?>
                    <details open>
                        <summary><strong><?= e($group) ?></strong></summary>
                        <?php foreach ($fields as $field): ?>
                            <div><label style="font-weight:normal;">
                                <input type="checkbox" class="field-toggle" value="<?= e($field['key']) ?>" data-label="<?= e($field['label']) ?>">
                                <?= e($field['label']) ?>
                            </label></div>
                        <?php endforeach; ?>
                    </details>
                <?php endforeach; ?>
                <div class="head-actions" style="justify-content:flex-start; margin-top:8px;">
                    <button class="btn" type="button" id="add-const">+ 固定值列</button>
                    <button class="btn" type="button" id="add-raw">+ 原始字段列</button>
                </div>
            </div>
            <div style="flex:1;">
                <table class="table" id="columns-table">
                    <thead><tr><th style="width:36px;">#</th><th>来源</th><th>显示名</th><th style="width:150px;">操作</th></tr></thead>
                    <tbody></tbody>
                </table>
                <div class="head-actions" style="justify-content:flex-start;">
                    <button class="btn primary" type="submit">保存模板</button>
                    <button class="btn" type="button" id="preview-btn">导出预览(前3行)</button>
                    <a class="btn" href="/import-export/non-excel?tenant=<?= e($tenantKey) ?>">返回</a>
                </div>
                <div id="preview-area"></div>
            </div>
        </div>
    </div>
</form>

<script>
(function () {
    'use strict';
    var columns = <?= $columnsJson ?>;
    var tenant = <?= json_encode((string) $tenantKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var tbody = document.querySelector('#columns-table tbody');
    var form = document.getElementById('tpl-form');

    function sourceText(col) {
        if (col.type === 'field') { return '字段:' + (col.key || ''); }
        if (col.type === 'const') { return '固定值:' + (col.value || '(空)'); }
        return '原始:' + (col.path || '');
    }

    function esc(value) {
        var div = document.createElement('div');
        div.textContent = String(value == null ? '' : value);
        return div.innerHTML;
    }

    function renderRows() {
        tbody.innerHTML = '';
        columns.forEach(function (col, i) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<td>' + (i + 1) + '</td>'
                + '<td>' + esc(sourceText(col)) + '</td>'
                + '<td><input type="text" maxlength="64" value="' + esc(col.label || '') + '" data-i="' + i + '" class="col-label"></td>'
                + '<td><button type="button" class="btn mv" data-i="' + i + '" data-d="-1">↑</button> '
                + '<button type="button" class="btn mv" data-i="' + i + '" data-d="1">↓</button> '
                + '<button type="button" class="btn rm" data-i="' + i + '">✕</button></td>';
            tbody.appendChild(tr);
        });
        document.querySelectorAll('.field-toggle').forEach(function (box) {
            box.checked = columns.some(function (col) { return col.type === 'field' && col.key === box.value; });
        });
    }

    tbody.addEventListener('click', function (event) {
        var target = event.target;
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

    document.querySelectorAll('.field-toggle').forEach(function (box) {
        box.addEventListener('change', function () {
            if (box.checked) {
                columns.push({ type: 'field', key: box.value, label: box.getAttribute('data-label') || box.value });
            } else {
                columns = columns.filter(function (col) { return !(col.type === 'field' && col.key === box.value); });
            }
            renderRows();
        });
    });

    document.getElementById('add-const').addEventListener('click', function () {
        var label = window.prompt('固定值列的表头名:');
        if (!label) { return; }
        var value = window.prompt('该列每行输出的固定内容(可留空):') || '';
        columns.push({ type: 'const', label: label.slice(0, 64), value: value });
        renderRows();
    });

    document.getElementById('add-raw').addEventListener('click', function () {
        var path = window.prompt('原始字段路径(order./item./customer. 开头,如 item.tabaono):');
        if (!path || !/^(order|item|customer)\..+/.test(path)) {
            if (path) { window.alert('路径必须以 order./item./customer. 开头。'); }
            return;
        }
        var label = window.prompt('该列表头名:') || path;
        columns.push({ type: 'raw', path: path, label: label.slice(0, 64) });
        renderRows();
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
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (response) { return response.json(); }).then(function (data) {
            var area = document.getElementById('preview-area');
            if (!data.ok) {
                area.innerHTML = '<div class="setting-muted" style="color:#c0392b;">' + esc((data.errors || []).join(';')) + '</div>';
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
            document.getElementById('preview-area').innerHTML = '<div class="setting-muted" style="color:#c0392b;">预览请求失败。</div>';
        });
    });

    renderRows();
})();
</script>
