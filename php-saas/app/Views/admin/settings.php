<?php
$mapping = is_array($settings['logistics_mapping'] ?? null) ? $settings['logistics_mapping'] : [];
$showapi = is_array($settings['showapi'] ?? null) ? $settings['showapi'] : [];
$proxy = is_array($settings['proxy'] ?? null) ? $settings['proxy'] : [];
$mappingFields = [
    'yahoo' => ['title' => '雅虎', 'hint' => 'Yahoo 平台物流编号映射'],
    'rakuten' => ['title' => '乐天', 'hint' => 'Rakuten RMS 物流编号映射'],
    'wowma' => ['title' => 'Wowma', 'hint' => 'Wowma 平台物流编号映射'],
    'jp_carrier' => ['title' => '日本快递公司', 'hint' => '佐川、日本邮政、黑猫等承运商代码'],
    'tracking_query' => ['title' => '物流状态查询', 'hint' => '物流轨迹查询状态映射'],
];
$lineCount = static function (string $value): int {
    return count(array_filter(array_map('trim', preg_split('/\R/', $value) ?: [])));
};
?>
<div class="page-head">
    <div>
        <h1>系统设置 <span class="sub">平台全局物流、ShowAPI 与代理配置</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/admin/system">系统状态</a>
        <button class="btn admin" type="submit" form="admin-settings-form">保存设置</button>
    </div>
</div>

<?php if (trim((string) ($message ?? '')) !== ''): ?>
    <div class="notice slim"><?= e($message) ?></div>
<?php elseif (($saved ?? '') === '1'): ?>
    <div class="notice slim">系统设置已保存。</div>
<?php endif; ?>

<div class="grid admin-settings-stats">
    <div class="stat">
        <div class="stat-label">配置归属</div>
        <div class="stat-value billing-name">超管全局</div>
        <div class="stat-sub">租户侧不展示这些维护入口</div>
    </div>
    <div class="stat">
        <div class="stat-label">物流映射</div>
        <div class="stat-value"><?= e(array_sum(array_map(fn (string $key): int => $lineCount((string) ($mapping[$key] ?? '')), array_keys($mappingFields)))) ?></div>
        <div class="stat-sub">按旧 setting.ini 多行规则承接</div>
    </div>
    <div class="stat">
        <div class="stat-label">ShowAPI</div>
        <div class="stat-value billing-state <?= !empty($showapi['enabled']) ? 'ok' : 'warn' ?>"><?= !empty($showapi['enabled']) ? '启用' : '关闭' ?></div>
        <div class="stat-sub">用于后续物流轨迹接口接入</div>
    </div>
    <div class="stat">
        <div class="stat-label">轮循代理</div>
        <div class="stat-value billing-state <?= !empty($proxy['enabled']) ? 'ok' : 'warn' ?>"><?= !empty($proxy['enabled']) ? '启用' : '关闭' ?></div>
        <div class="stat-sub">平台同步与物流查询共享</div>
    </div>
</div>

<form id="admin-settings-form" class="admin-settings-form" method="post" action="/admin/settings/save">
                <?= csrf_field() ?>
    <section class="panel settings-wide">
        <div class="panel-head">
            <span>物流编号对照表</span>
            <span class="sub">从 old/setting.ini 迁入，后续可拆为结构化表</span>
        </div>
        <div class="panel-body admin-mapping-grid">
            <?php foreach ($mappingFields as $key => $field): ?>
                <?php $value = (string) ($mapping[$key] ?? ''); ?>
                <label class="mapping-field <?= in_array($key, ['jp_carrier', 'tracking_query'], true) ? 'wide' : '' ?>">
                    <span><?= e($field['title']) ?> <small><?= e($field['hint']) ?>，<?= e($lineCount($value)) ?> 条</small></span>
                    <textarea name="logistics_mapping[<?= e($key) ?>]" spellcheck="false"><?= e($value) ?></textarea>
                </label>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="grid admin-settings-two">
        <section class="panel">
            <div class="panel-head"><span>ShowAPI 配置</span><span class="sub">平台级物流查询接口</span></div>
            <div class="panel-body form-grid">
                <label class="check-line admin-check"><input type="checkbox" name="showapi[enabled]" value="1" <?= !empty($showapi['enabled']) ? 'checked' : '' ?>>启用 ShowAPI</label>
                <label class="check-line admin-check"><input type="checkbox" name="showapi[baidu_enabled]" value="1" <?= !empty($showapi['baidu_enabled']) ? 'checked' : '' ?>>启用百度物流备用查询</label>
                <div class="setting-muted wide">ShowAPI AppID/Sign 不写入 Store，也不在页面回显；请通过环境变量 `SHOWAPI_APP_ID` 和 `SHOWAPI_SIGN` 配置。启用百度备用后，ShowAPI 失败或无轨迹时会访问 https://www.baidu.com/s?wd=物流号 {国内运单号} 并解析页面轨迹。</div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><span>轮循代理</span><span class="sub">平台同步 / 物流查询共用</span></div>
            <div class="panel-body form-grid">
                <label class="check-line admin-check"><input type="checkbox" name="proxy[enabled]" value="1" <?= !empty($proxy['enabled']) ? 'checked' : '' ?>>启用轮循代理</label>
                <div class="setting-muted wide">代理地址不写入 Store，也不在页面回显；请通过环境变量 `XIZHEN_ROTATION_PROXY` 配置。</div>
            </div>
        </section>
    </div>

    <div class="settings-submit-row settings-wide">
        <span class="setting-muted">这些设置会影响所有租户，关闭后不影响租户授权开关本身，只会影响真实外部接口能力。</span>
        <button class="btn admin" type="submit">保存系统设置</button>
    </div>
</form>

<div class="settings-grid settings-page settings-reference">
    <?php foreach ($legacyGroups as $group): ?>
        <div class="panel">
            <div class="panel-head"><span><?= e($group['group']) ?></span><span class="sub"><?= e($group['source']) ?></span></div>
            <div class="panel-body">
                <?php foreach ($group['items'] as $item): ?>
                    <div class="setting-row">
                        <span><?= e($item['name']) ?></span>
                        <strong><?= e($item['value']) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
