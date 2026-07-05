<?php

use Xizhen\Services\OrderPageConfigRegistry;

$builtinTools = array_values(array_filter(is_array($builtinTools ?? null) ? $builtinTools : [], 'is_array'));
$templates = array_values(array_filter(is_array($templates ?? null) ? $templates : [], 'is_array'));
$displayConfig = is_array($displayConfig ?? null) ? $displayConfig : [];
$message = trim((string) ($message ?? ''));
$displayLabels = [
    'primary' => '常驻',
    'more' => '更多',
    'hidden' => '隐藏',
];
$displayFor = static function (string $key, string $default) use ($displayConfig): string {
    $value = (string) ($displayConfig[$key] ?? $default);

    return in_array($value, ['primary', 'more', 'hidden'], true) ? $value : $default;
};
$renderRadios = static function (string $key, string $current) use ($displayLabels): void {
    foreach ($displayLabels as $value => $label):
        ?>
        <label class="seg-option">
            <input type="radio" name="tools[<?= e($key) ?>]" value="<?= e($value) ?>" <?= e($current === $value ? 'checked' : '') ?>>
            <span><?= e($label) ?></span>
        </label>
        <?php
    endforeach;
};
?>
<div class="page-head">
    <div>
        <h1>订单页导出按钮管理 <span class="sub">配置订单页右侧工具区的常驻、更多或隐藏状态</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/import-export?tenant=<?= e($tenantKey) ?>">返回导入导出</a>
    </div>
</div>

<?php if ($message !== ''): ?>
    <div class="notice"><?= e($message) ?></div>
<?php endif; ?>

<form method="post" action="/import-export/order-tools">
    <?= csrf_field() ?>
    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">

    <section class="panel">
        <div class="panel-head"><span>内置导入导出工具</span><span class="sub">未配置时沿用订单页 D3.2 默认分组</span></div>
        <div class="panel-body">
            <table class="table export-tool-config-table">
                <thead><tr><th>按钮</th><th>动作</th><th>默认</th><th>展示状态</th></tr></thead>
                <tbody>
                <?php foreach ($builtinTools as $tool): ?>
                    <?php
                    $key = (string) ($tool['key'] ?? '');
                    $default = in_array((string) ($tool['group'] ?? ''), ['primary', 'more'], true) ? (string) $tool['group'] : 'hidden';
                    $current = $displayFor($key, $default);
                    ?>
                    <tr>
                        <td><strong><?= e($tool['label'] ?? $key) ?></strong><span class="sub"><?= e($key) ?></span></td>
                        <td><?= e(((string) ($tool['method'] ?? 'get') === 'post' ? 'POST ' : 'GET ') . (string) ($tool['action'] ?? '')) ?></td>
                        <td><span class="tag gray"><?= e($displayLabels[$default] ?? $default) ?></span></td>
                        <td><div class="seg-control"><?php $renderRadios($key, $current); ?></div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$builtinTools): ?>
                    <tr><td colspan="4" class="sub">暂无可配置的内置工具。</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><span>导出模板按钮</span><span class="sub">预置和自定义模板默认隐藏，设为常驻或更多后出现在订单页</span></div>
        <div class="panel-body">
            <table class="table export-tool-config-table">
                <thead><tr><th>模板</th><th>格式</th><th>默认</th><th>展示状态</th></tr></thead>
                <tbody>
                <?php foreach ($templates as $template): ?>
                    <?php
                    $templateId = (string) ($template['id'] ?? '');
                    $key = OrderPageConfigRegistry::templateToolKey($templateId);
                    $current = $displayFor($key, 'hidden');
                    ?>
                    <tr>
                        <td>
                            <strong><?= e($template['name'] ?? $templateId) ?></strong>
                            <span class="sub"><?= e($templateId) ?><?= !empty($template['builtin']) ? e(' · 预置') : '' ?></span>
                        </td>
                        <td><?= e(strtoupper((string) ($template['format'] ?? 'csv'))) ?></td>
                        <td><span class="tag gray">隐藏</span></td>
                        <td><div class="seg-control"><?php $renderRadios($key, $current); ?></div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$templates): ?>
                    <tr><td colspan="4" class="sub">暂无导出模板。</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="form-submit">
        <button class="btn primary" type="submit">保存配置</button>
        <a class="btn" href="/import-export?tenant=<?= e($tenantKey) ?>">取消</a>
    </div>
</form>
