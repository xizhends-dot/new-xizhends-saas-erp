<?php
$company = is_array($settings['company'] ?? null) ? $settings['company'] : [];
$orders = is_array($settings['orders'] ?? null) ? $settings['orders'] : [];
$profit = is_array($settings['profit'] ?? null) ? $settings['profit'] : [];
$logistics = is_array($settings['logistics'] ?? null) ? $settings['logistics'] : [];
$api1688 = is_array($settings['api_1688'] ?? null) ? $settings['api_1688'] : [];
$wowmaSyncFolders = array_values(array_filter(array_map('strval', is_array($wowmaSyncFolders ?? null) ? $wowmaSyncFolders : ($orders['wowma_sync_folders'] ?? ['XIZHENDS', 'Ready_buy']))));
$platformDeductions = is_array($profit['platform_deductions'] ?? null) ? $profit['platform_deductions'] : [];
$excludedProfitStatuses = array_flip(array_values(array_filter(array_map('strval', is_array($profit['excluded_purchase_statuses'] ?? null) ? $profit['excluded_purchase_statuses'] : ['已取消', '客人取消订单']))));
$purchaseStatuses = array_values(array_filter(array_map('strval', is_array($purchaseStatuses ?? null) ? $purchaseStatuses : [])));
$profitExclusionStatusOptions = array_values(array_unique(array_merge($purchaseStatuses, array_keys($excludedProfitStatuses))));
$jpStockPurchaseStatuses = array_values(array_filter(array_map('strval', is_array($jpStockPurchaseStatuses ?? null) ? $jpStockPurchaseStatuses : [])));
$systemPurchaseStatuses = is_array($systemPurchaseStatuses ?? null) ? $systemPurchaseStatuses : [];
$purchaseStatusSaved = ($saved ?? '') === 'purchase_statuses';
$settingsSaved = ($saved ?? '') === '1';
$error = trim((string) ($error ?? ''));
$settingsTabs = [
    ['key' => 'company', 'title' => '公司资料', 'desc' => '登录页、侧栏与租户资料'],
    ['key' => 'orders', 'title' => '订单参数', 'desc' => '查询默认值与归档策略'],
    ['key' => 'purchase-statuses', 'title' => '采购状态', 'desc' => '状态清单与显示顺序'],
    ['key' => 'profit', 'title' => '利润参数', 'desc' => '利润核算分析与财务导出口径'],
    ['key' => 'logistics', 'title' => '国内快递', 'desc' => '签收地与国内物流口径'],
    ['key' => 'api1688', 'title' => '1688 接口', 'desc' => '租户业务接口配置'],
];
?>
<div class="page-head">
    <div>
        <h1>系统设置 <span class="sub">当前租户独立设置：资料、订单参数、国内快递、1688 与利润口径</span></h1>
    </div>
</div>

<?php if ($settingsSaved): ?>
    <div class="notice">设置已保存，利润核算分析和导出报表会使用新的口径。</div>
<?php endif; ?>
<?php if ($purchaseStatusSaved): ?>
    <div class="notice">采购状态已保存，订单页和导出模板会使用新的清单顺序。</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="notice error"><?= e($error) ?></div>
<?php endif; ?>

<form id="tenant-settings-form" method="post" action="/settings/save" class="settings-page">
                <?= csrf_field() ?>
    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">

    <div class="tenant-settings-layout" data-settings-layout>
        <aside class="settings-nav-panel" aria-label="系统设置导航">
            <div class="settings-nav-title">设置导航</div>
            <div class="settings-nav-list" role="tablist" aria-label="系统设置分类">
                <?php foreach ($settingsTabs as $index => $tab): ?>
                    <button
                        type="button"
                        class="settings-tab <?= $index === 0 ? 'active' : '' ?>"
                        data-settings-tab="<?= e($tab['key']) ?>"
                        aria-controls="settings-pane-<?= e($tab['key']) ?>"
                        aria-selected="<?= $index === 0 ? 'true' : 'false' ?>"
                        role="tab"
                    >
                        <strong><?= e($tab['title']) ?></strong>
                        <span><?= e($tab['desc']) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="settings-nav-note">这里的设置只影响当前租户；平台级物流编号对照表、ShowAPI 与轮循代理在 SaaS 超管维护。</div>
        </aside>

        <div class="settings-pane-wrap">
            <section class="panel settings-pane active" id="settings-pane-company" data-settings-pane="company" role="tabpanel">
                <div class="panel-head"><span>公司资料</span><span class="sub">用于登录页、侧栏与租户资料</span></div>
                <div class="panel-body form-grid">
                    <label><span>公司名</span><input name="company_name" value="<?= e($company['company_name'] ?? '') ?>"></label>
                    <label><span>简称</span><input name="short_name" value="<?= e($company['short_name'] ?? '') ?>"></label>
                    <label><span>联系人</span><input name="contact" value="<?= e($company['contact'] ?? '') ?>"></label>
                    <label><span>电话</span><input name="phone" value="<?= e($company['phone'] ?? '') ?>"></label>
                    <label class="wide"><span>地址</span><input name="address" value="<?= e($company['address'] ?? '') ?>"></label>
                    <label class="wide"><span>业务备注</span><textarea name="note"><?= e($company['note'] ?? '') ?></textarea></label>
                </div>
            </section>

            <section class="panel settings-pane" id="settings-pane-orders" data-settings-pane="orders" role="tabpanel" hidden>
                <div class="panel-head"><span>订单参数</span><span class="sub">查询默认值与归档策略</span></div>
                <div class="panel-body form-grid">
                    <label><span>默认分页</span><input type="number" min="20" max="1000" name="default_page_size" value="<?= e($orders['default_page_size'] ?? 200) ?>"></label>
                    <label><span>默认查询天数</span><input type="number" min="1" max="365" name="default_query_days" value="<?= e($orders['default_query_days'] ?? 30) ?>"></label>
                    <label><span>平台订单同步默认天数</span><input type="number" min="1" max="30" name="platform_sync_default_days" value="<?= e($orders['platform_sync_default_days'] ?? 7) ?>"></label>
                    <label><span>归档周期天数</span><input type="number" min="30" max="3650" name="archive_days" value="<?= e($orders['archive_days'] ?? 180) ?>"></label>
                    <label><span>售价预警指数</span><input type="number" step="0.01" min="0" name="price_warning_index" value="<?= e($orders['price_warning_index'] ?? 0) ?>"></label>
                    <label class="wide"><span>Wowma 文件夹名称</span><textarea name="wowma_sync_folders" placeholder="XIZHENDS&#10;Ready_buy"><?= e(implode(PHP_EOL, $wowmaSyncFolders)) ?></textarea></label>
                    <div class="setting-muted wide">同步 Wowma 订单时会先选择这里维护的名称；系统会把所选名称作为 Wowma API 的 orderStatus 参数。</div>
                </div>
            </section>

            <section class="panel settings-pane" id="settings-pane-purchase-statuses" data-settings-pane="purchase-statuses" role="tabpanel" hidden>
                <div class="panel-head"><span>采购状态</span><span class="sub">删除状态不会改动历史订单；历史值仍会在编辑时保留</span></div>
                <div class="panel-body">
                    <div class="purchase-status-editor" data-purchase-status-editor="statuses_json">
                        <div class="setting-section-title">国内采购</div>
                        <div class="purchase-status-list" data-purchase-status-list>
                            <?php foreach ($profitExclusionStatusOptions as $status): ?>
                                <?php
                                $lockReason = (string) ($systemPurchaseStatuses[$status] ?? '');
                                $locked = $lockReason !== '';
                                ?>
                                <div class="purchase-status-row" data-purchase-status-row data-locked="<?= $locked ? '1' : '0' ?>">
                                    <input class="purchase-status-name" value="<?= e($status) ?>" <?= $locked ? 'readonly' : '' ?> maxlength="32" data-purchase-status-name>
                                    <?php if ($locked): ?>
                                        <span class="status-lock-tag" title="<?= e($lockReason) ?>">系统状态·自动化依赖</span>
                                    <?php endif; ?>
                                    <div class="purchase-status-actions">
                                        <button class="btn-xs" type="button" data-purchase-status-move="up">上移</button>
                                        <button class="btn-xs" type="button" data-purchase-status-move="down">下移</button>
                                        <button class="btn-xs danger-text" type="button" data-purchase-status-delete <?= $locked ? 'disabled' : '' ?>>删除</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="purchase-status-add">
                            <input type="text" maxlength="32" placeholder="新增状态名称" data-purchase-status-new>
                            <button class="btn" type="button" data-purchase-status-add>新增状态</button>
                            <button class="btn" type="submit" name="reset_purchase_statuses" value="1" form="purchase-status-form" onclick="return confirm('确定恢复默认国内采购状态清单?')">恢复国内默认</button>
                        </div>
                        <div class="setting-muted">系统状态可移动顺序，但不能改名或删除；服务端会再次校验完整清单。</div>
                    </div>

                    <div class="purchase-status-editor" data-purchase-status-editor="jp_stock_statuses_json">
                        <div class="setting-section-title">日本仓</div>
                        <div class="purchase-status-list" data-purchase-status-list>
                            <?php foreach ($jpStockPurchaseStatuses as $status): ?>
                                <div class="purchase-status-row" data-purchase-status-row data-locked="0">
                                    <input class="purchase-status-name" value="<?= e($status) ?>" maxlength="32" data-purchase-status-name>
                                    <div class="purchase-status-actions">
                                        <button class="btn-xs" type="button" data-purchase-status-move="up">上移</button>
                                        <button class="btn-xs" type="button" data-purchase-status-move="down">下移</button>
                                        <button class="btn-xs danger-text" type="button" data-purchase-status-delete>删除</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="purchase-status-add">
                            <input type="text" maxlength="32" placeholder="新增日本仓状态名称" data-purchase-status-new>
                            <button class="btn" type="button" data-purchase-status-add>新增状态</button>
                            <button class="btn primary" type="submit" form="purchase-status-form">保存两套采购状态</button>
                            <button class="btn" type="submit" name="reset_jp_stock_statuses" value="1" form="purchase-status-form" onclick="return confirm('确定恢复默认日本仓采购状态清单?')">恢复日本仓默认</button>
                        </div>
                        <div class="setting-muted">日本仓采购状态可自由新增、改名、删除和排序；不参与上方系统状态锁定。</div>
                    </div>
                </div>
            </section>

            <section class="panel settings-pane" id="settings-pane-profit" data-settings-pane="profit" role="tabpanel" hidden>
                <div class="panel-head"><span>利润参数</span><span class="sub">利润核算分析与财务导出口径</span></div>
                <div class="panel-body form-grid">
                    <label><span>汇率</span><input type="number" step="0.0001" min="0.0001" name="exchange_rate" value="<?= e($profit['exchange_rate'] ?? 0.046) ?>"></label>
                    <label><span>汇率模式</span><select name="exchange_rate_mode">
                        <option value="fixed" <?= ($profit['exchange_rate_mode'] ?? 'fixed') === 'fixed' ? 'selected' : '' ?>>固定汇率</option>
                        <option value="manual" <?= ($profit['exchange_rate_mode'] ?? '') === 'manual' ? 'selected' : '' ?>>手动维护</option>
                    </select></label>
                    <label><span>固定汇率</span><input type="number" step="0.0001" min="0.0001" name="fixed_exchange_rate" value="<?= e($profit['fixed_exchange_rate'] ?? ($profit['exchange_rate'] ?? 0.046)) ?>"></label>
                    <label><span>默认国际运费/件</span><input type="number" step="0.01" min="0" name="default_intl_fee" value="<?= e($profit['default_intl_fee'] ?? 820) ?>"></label>
                    <label class="check-line"><input type="checkbox" name="store_deduction_enabled" value="1" <?= !empty($profit['store_deduction_enabled']) ? 'checked' : '' ?>>优先使用店铺扣点</label>
                    <?php foreach (($platformNames ?? []) as $code => $name): ?>
                        <label><span><?= e($name) ?> 扣点/回款率</span><input type="number" step="0.01" min="0" max="100" name="platform_deductions[<?= e($code) ?>]" value="<?= e($platformDeductions[$code] ?? 70) ?>"></label>
                    <?php endforeach; ?>
                    <div class="wide profit-status-exclusions">
                        <span>不进入利润核算的采购状态</span>
                        <div class="profit-status-exclusion-list">
                            <?php foreach ($purchaseStatuses as $status): ?>
                                <label>
                                    <input type="checkbox" name="excluded_purchase_statuses[]" value="<?= e($status) ?>" <?= isset($excludedProfitStatuses[$status]) ? 'checked' : '' ?>>
                                    <?= e($status) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="setting-muted">被勾选的采购状态不会进入利润核算分析；即使页面选择“全部状态”，这些状态也会被排除。</div>
                    </div>
                </div>
            </section>

            <section class="panel settings-pane" id="settings-pane-logistics" data-settings-pane="logistics" role="tabpanel" hidden>
                <div class="panel-head"><span>国内快递设置</span><span class="sub">每个租户自己的签收地和国内物流口径</span></div>
                <div class="panel-body form-grid">
                    <label class="wide">
                        <span>国内快递签收地</span>
                        <textarea name="domestic_receive_places" placeholder="例如：义乌,广州新势力,深圳威通"><?= e($logistics['domestic_receive_places'] ?? '') ?></textarea>
                    </label>
                </div>
            </section>

            <section class="panel settings-pane" id="settings-pane-api1688" data-settings-pane="api1688" role="tabpanel" hidden>
                <div class="panel-head"><span>1688 接口配置</span><span class="sub">租户级 API 配置替换入口</span></div>
                <div class="panel-body form-grid">
                    <label class="check-line"><input type="checkbox" name="api_1688_enabled" value="1" <?= !empty($api1688['enabled']) ? 'checked' : '' ?>>启用租户 1688 接口</label>
                    <label class="wide api-config-field">
                        <span>替换 API 账户配置</span>
                        <textarea name="api_1688_config_content" spellcheck="false" placeholder="#账号名称 APP_KEY APP_SECRET ACCESS_TOKEN&#10;留空表示保留现有配置"></textarea>
                    </label>
                    <div class="setting-muted wide">
                        <div>当前状态：<?= !empty($api1688['has_config']) ? '已配置' : '未配置' ?>。每行一个账户配置，格式：账号名称 APP_KEY APP_SECRET ACCESS_TOKEN。留空保存不会清空现有配置。</div>
                        <div>保存位置：<?= e($api1688['config_file'] ?? '') ?></div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <div class="settings-submit-row settings-wide">
        <button class="btn primary" type="submit">保存设置</button>
        <span class="setting-muted">保存后仅对当前租户生效，不会影响其他租户。</span>
    </div>
</form>

<form id="purchase-status-form" method="post" action="/settings/purchase-statuses/save">
                <?= csrf_field() ?>
    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
    <input type="hidden" name="statuses_json" value="" data-purchase-status-json="statuses_json">
    <input type="hidden" name="jp_stock_statuses_json" value="" data-purchase-status-json="jp_stock_statuses_json">
</form>
