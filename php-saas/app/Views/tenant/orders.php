<?php
$viewLabels = ['platform' => '平台订单', 'purchase' => '采购订单', 'jp' => '日本仓发货'];
$activeTitle = $viewLabels[$orderView] ?? '平台订单';
$totalItems = array_sum(array_map(fn (array $order): int => count($order['items'] ?? []), $orders));
$resultTotal = $orderView === 'platform' ? count($orders) : $totalItems;
$resultUnit = $orderView === 'platform' ? '单' : '件';
$platformLabel = $platform ? ($platformNames[$platform] ?? $platform) : '全部平台';
$resultLabel = $orderView === 'jp' ? '件待发' : $resultUnit . '结果';
$filters = $filters ?? [];
$returnUrl = $_SERVER['REQUEST_URI'] ?? '/orders?tenant=' . $tenantKey;
$batchFormId = 'batch-' . $orderView;
$canEditOrders = (bool) ($canEditOrders ?? false);
$canEditPurchase = (bool) ($canEditPurchase ?? false);
$canEditJp = (bool) ($canEditJp ?? false);
$canChangeSource = (bool) ($canChangeSource ?? false);
$canBatchOperate = (bool) ($canBatchOperate ?? false);
$canBatchPurchase = (bool) ($canBatchPurchase ?? false);
$canBatchJp = (bool) ($canBatchJp ?? false);
$canSelectAll = match ($orderView) {
    'jp' => $canBatchJp,
    'purchase' => $canBatchPurchase,
    default => $canBatchOperate,
};
$canImportExport = (bool) ($canImportExport ?? false);
$canPlatformImportExport = (bool) ($canPlatformImportExport ?? false);
$canPurchaseImportExport = (bool) ($canPurchaseImportExport ?? false);
$canFinanceExport = (bool) ($canFinanceExport ?? false);
$canFullImportExport = (bool) ($canFullImportExport ?? ($canPlatformImportExport || $canFinanceExport));
$can1688Logistics = (bool) ($can1688Logistics ?? false);
$canExpressLogistics = (bool) ($canExpressLogistics ?? false);
$canJpLogistics = (bool) ($canJpLogistics ?? false);
$stores = is_array($stores ?? null) ? $stores : [];
$filterValue = static fn (string $key): string => (string) ($filters[$key] ?? '');
$platformSyncServices = is_array($platformSyncServices ?? null) ? $platformSyncServices : [];
$currentPlatform = (string) ($platform ?? '');
$platformSyncName = $currentPlatform !== '' ? (string) ($platformSyncServices[$currentPlatform] ?? '') : '';
$platformSyncStores = $platformSyncName !== '' ? array_values(array_filter($stores, static fn (array $store): bool => ($store['platform'] ?? '') === $currentPlatform)) : [];
$message = trim((string) ($_GET['message'] ?? ''));
$hiddenFilters = [
    'tenant' => $tenantKey,
    'view' => $orderView,
    'platform' => (string) ($platform ?? ''),
    'source' => (string) ($source ?? 'all'),
    'q' => $keyword,
];
foreach ($filters as $filterKey => $hiddenFilterValue) {
    if ($hiddenFilterValue !== '') {
        $hiddenFilters[$filterKey] = (string) $hiddenFilterValue;
    }
}

$statusOptions = array_values(array_filter(array_map('strval', is_array($statusOptions ?? null) ? $statusOptions : [])));
$statusOptionsFor = static function (mixed $current) use ($statusOptions): array {
    $current = trim((string) $current);
    if ($current !== '' && !in_array($current, $statusOptions, true)) {
        return array_merge([$current], $statusOptions);
    }

    return $statusOptions;
};
$filterFields = array_values(array_filter(
    is_array($filterFields ?? null) ? $filterFields : [],
    static fn (mixed $field): bool => is_array($field) && trim((string) ($field['key'] ?? '')) !== ''
));
$exportTools = array_values(array_filter(
    is_array($exportTools ?? null) ? $exportTools : [],
    static fn (mixed $tool): bool => is_array($tool) && !empty($tool['visibleWhen'])
));
$priceDefaults = is_array($priceDefaults ?? null) ? $priceDefaults : [];
$formatNumber = static fn (mixed $value, int $decimals = 2): string => number_format((float) $value, $decimals, '.', '');
$urlWithQuery = static function (string $path, array $params): string {
    $params = array_filter($params, static fn (mixed $value): bool => trim((string) $value) !== '');

    return $path . ($params ? '?' . http_build_query($params) : '');
};
$exportUrl = static fn (string $path, array $extra = []): string => $urlWithQuery($path, array_merge($hiddenFilters, $extra));
$fieldOptions = static function (array $field) use ($statusOptions, $orderView): array {
    $key = (string) ($field['key'] ?? '');
    if (($field['optionsKey'] ?? '') === 'statusOptions') {
        if ($orderView === 'jp') {
            return [
                ['value' => '待分配', 'label' => '待分配'],
                ['value' => '已分配', 'label' => '已分配'],
                ['value' => '已出库', 'label' => '已出库'],
                ['value' => '已发货', 'label' => '已发货'],
            ];
        }

        return array_map(static fn (string $status): array => ['value' => $status, 'label' => $status], $statusOptions);
    }
    if ($key === 'page_size') {
        return is_array($field['options'] ?? null) ? $field['options'] : [];
    }

    return [];
};
?>
<div class="order-page">
    <div class="page-head compact-head">
        <h1><?= e($activeTitle) ?> <span class="plat-tag"><?= e($orderView === 'jp' ? 'JP 仓库现货' : $platformLabel) ?></span></h1>
        <div class="head-actions">
            <?php if ($canEditOrders): ?><button class="btn btn-p" type="button"><?= e($orderView === 'jp' ? '+ 手动出库' : '+ 手动录入') ?></button><?php endif; ?>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="notice slim"><?= e($message) ?></div>
    <?php endif; ?>

    <section class="order-workbench-grid">
        <div class="order-workbench-panel order-search-panel">
            <div class="order-panel-head">
                <span>搜索</span>
                <span class="sub">当前 <?= e($platformLabel) ?> · <?= e($resultTotal) ?> <?= e($resultLabel) ?></span>
            </div>
            <form class="order-filter order-filter-modern" method="get" action="/orders">
                <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                <input type="hidden" name="view" value="<?= e($orderView) ?>">
                <input type="hidden" name="platform" value="<?= e((string) ($platform ?? '')) ?>">
                <input type="hidden" name="source" value="<?= e((string) ($source ?? 'all')) ?>">
                <div class="order-search-grid">
                    <?php foreach ($filterFields as $field): ?>
                        <?php
                        $key = (string) ($field['key'] ?? '');
                        $label = (string) ($field['label'] ?? $key);
                        $type = (string) ($field['type'] ?? 'text');
                        $value = $filterValue($key);
                        if ($key === 'order_no' && $value === '') {
                            $value = (string) $keyword;
                        }
                        if ($key === 'status' && $orderView === 'jp') {
                            $label = '出库状态';
                        }
                        ?>
                        <label class="fg order-search-field order-search-field-<?= e($key) ?>">
                            <span class="lb"><?= e($label) ?></span>
                            <?php if ($type === 'select'): ?>
                                <select name="<?= e($key) ?>">
                                    <?php if ($key === 'status'): ?>
                                        <option value=""><?= e($orderView === 'jp' ? '全部状态' : '— 待处理订单 —') ?></option>
                                        <?php if ($orderView !== 'jp'): ?>
                                            <option value="__ALL__" <?= e($value === '__ALL__' ? 'selected' : '') ?>>全部订单</option>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php foreach ($fieldOptions($field) as $option): ?>
                                        <?php
                                        $optionValue = (string) ($option['value'] ?? $option['label'] ?? '');
                                        $optionLabel = (string) ($option['label'] ?? $optionValue);
                                        ?>
                                        <option value="<?= e($optionValue) ?>" <?= e($value === $optionValue ? 'selected' : '') ?>><?= e($optionLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="<?= e($type === 'date' ? 'date' : 'text') ?>" name="<?= e($key) ?>" value="<?= e($value) ?>" placeholder="<?= e($label) ?>">
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="filter-foot order-filter-actions">
                    <div class="fsum">共 <strong><?= e($resultTotal) ?></strong> <?= e($resultLabel) ?></div>
                    <div class="fsp"></div>
                    <button class="btn btn-p search-btn" type="submit">搜索</button>
                    <a class="btn reset-btn" href="/orders?tenant=<?= e($tenantKey) ?>&view=<?= e($orderView) ?><?= e($platform ? '&platform=' . (string) $platform : '') ?>">重置</a>
                </div>
            </form>
        </div>

        <div class="order-workbench-panel order-info-panel">
            <div class="order-panel-head">
                <span>信息</span>
                <span class="sub">汇率与公告</span>
            </div>
            <div class="order-rate-box">
                <span class="order-rate-label">实时汇率</span>
                <strong><?= e($formatNumber($priceDefaults['exchange_rate'] ?? 0.048, 4)) ?></strong>
                <span><?= e((string) ($priceDefaults['exchange_rate_source'] ?? '固定汇率')) ?></span>
            </div>
            <div class="order-rate-meta">
                <span>默认运费 ￥<?= e($formatNumber($priceDefaults['shipping'] ?? 40, 2)) ?></span>
                <span>默认扣点 <?= e($formatNumber($priceDefaults['deduction'] ?? 70, 0)) ?>%</span>
            </div>
            <div class="order-notice-list">
                <?php if (empty($tenantNotices)): ?>
                    <div class="order-notice-empty">暂无通知公告</div>
                <?php endif; ?>
                <?php foreach (($tenantNotices ?? []) as $notice): ?>
                    <article class="order-notice-item">
                        <strong><?= e($notice['title'] ?? '') ?></strong>
                        <span><?= e($notice['published_at'] ?? '') ?></span>
                        <p><?= e($notice['body'] ?? '') ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="order-workbench-panel order-tools-panel">
            <div class="order-panel-head">
                <span>导出 / 物流</span>
                <span class="sub">常驻工具</span>
            </div>
            <div class="order-tool-list">
                <?php foreach ($exportTools as $tool): ?>
                    <?php $toolKey = (string) ($tool['key'] ?? ''); ?>
                    <?php if ($toolKey === 'sync_orders'): ?>
                        <?php if ($orderView === 'platform' && $platformSyncName !== '' && $platformSyncStores): ?>
                            <form class="order-tool-row order-tool-form" method="post" action="<?= e((string) ($tool['action'] ?? '/orders/platform/sync')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                                <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                                <input type="hidden" name="platform" value="<?= e($currentPlatform) ?>">
                                <select name="store_id" aria-label="<?= e($platformSyncName . '店铺') ?>">
                                    <?php foreach ($platformSyncStores as $store): ?>
                                        <option value="<?= e($store['id'] ?? '') ?>"><?= e($store['name'] ?? $store['short'] ?? '') ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="days" aria-label="同步天数">
                                    <?php foreach ([1, 3, 7, 15, 30] as $days): ?>
                                        <option value="<?= e($days) ?>" <?= e($days === 7 ? 'selected' : '') ?>><?= e($days) ?>天</option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="order-tool-button" type="submit"><?= e((string) ($tool['label'] ?? '同步订单')) ?></button>
                            </form>
                        <?php else: ?>
                            <div class="order-tool-row muted" title="请选择已接入 API 的平台和店铺后再同步。">
                                <button class="order-tool-button" type="button" disabled><?= e((string) ($tool['label'] ?? '同步订单')) ?></button>
                                <span>未选择可同步店铺</span>
                            </div>
                        <?php endif; ?>
                    <?php elseif ($toolKey === 'platform_orders_import' || $toolKey === 'shipping_import'): ?>
                        <a class="order-tool-row" href="<?= e($urlWithQuery((string) ($tool['action'] ?? '/import-export'), ['tenant' => $tenantKey, 'job' => (string) ($tool['job'] ?? '')])) ?>">
                            <span class="order-tool-button"><?= e((string) ($tool['label'] ?? '导入')) ?></span>
                            <span><?= e($toolKey === 'shipping_import' ? '进入导入导出中心上传运单文件' : '进入导入导出中心上传订单文件') ?></span>
                        </a>
                    <?php elseif ($toolKey === 'shipment_export'): ?>
                        <?php if ($canPlatformImportExport): ?>
                            <button class="order-tool-row" type="submit" name="type" value="<?= e((string) ($tool['type'] ?? 'shipment')) ?>" form="order-export-form">
                                <span class="order-tool-button"><?= e((string) ($tool['label'] ?? '发货表导出')) ?></span>
                                <span><?= e(!empty($tool['needsDateRange']) ? '按当前筛选范围导出' : '立即导出') ?></span>
                            </button>
                        <?php endif; ?>
                    <?php elseif ($toolKey === 'finance_export'): ?>
                        <a class="order-tool-row" href="<?= e($exportUrl((string) ($tool['action'] ?? '/import-export/finance-placeholder/export'))) ?>">
                            <span class="order-tool-button"><?= e((string) ($tool['label'] ?? '财务表导出')) ?></span>
                            <span><?= e(!empty($tool['needsDateRange']) ? '按当前筛选范围导出' : '立即导出') ?></span>
                        </a>
                    <?php elseif ($toolKey === 'customers_export'): ?>
                        <a class="order-tool-row" href="<?= e($exportUrl((string) ($tool['action'] ?? '/import-export/customers/export'))) ?>">
                            <span class="order-tool-button"><?= e((string) ($tool['label'] ?? '客户资料导出')) ?></span>
                            <span><?= e(!empty($tool['needsDateRange']) ? '按当前筛选范围导出' : '立即导出') ?></span>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php if ($canPlatformImportExport): ?>
                    <button class="order-tool-row" type="submit" name="type" value="platform" form="order-export-form"><span class="order-tool-button">平台订单表导出</span><span>当前筛选订单</span></button>
                    <button class="order-tool-row" type="submit" name="type" value="delivery_notice" form="order-export-form"><span class="order-tool-button">发货通知表导出</span><span>客户邮件与国际单号</span></button>
                    <button class="order-tool-row" type="submit" form="xizhen-delivery-form"><span class="order-tool-button">西阵发货表导出</span><span>当前平台发货格式</span></button>
                <?php endif; ?>
                <?php if ($canPurchaseImportExport): ?>
                    <button class="order-tool-row" type="submit" name="type" value="purchase" form="order-export-form"><span class="order-tool-button">待采购单导出</span><span>采购视图明细</span></button>
                <?php endif; ?>
                <?php if ($canBatchPurchase): ?>
                    <button class="order-tool-row" type="submit" form="send-jp-form"><span class="order-tool-button">批量已发日本</span><span>选中明细流转</span></button>
                <?php endif; ?>
                <?php if ($can1688Logistics): ?>
                    <button class="order-tool-row" type="submit" name="type" value="1688" form="order-logistics-form"><span class="order-tool-button">更新1688物流</span><span>当前筛选明细</span></button>
                <?php endif; ?>
                <?php if ($canExpressLogistics): ?>
                    <button class="order-tool-row" type="submit" name="type" value="express" form="order-logistics-form"><span class="order-tool-button">更新TB/PDD物流</span><span>当前筛选明细</span></button>
                <?php endif; ?>
                <?php if ($canJpLogistics): ?>
                    <button class="order-tool-row" type="submit" name="type" value="jp" form="order-logistics-form"><span class="order-tool-button">更新国际物流</span><span>当前筛选明细</span></button>
                <?php endif; ?>
                <?php if (!$exportTools && !$canPlatformImportExport && !$canPurchaseImportExport && !$canBatchPurchase && !$can1688Logistics && !$canExpressLogistics && !$canJpLogistics): ?>
                    <div class="order-tool-empty">当前账号没有可用导出或物流工具。</div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if ($canBatchOperate || $canBatchPurchase || $canBatchJp): ?>
        <form id="<?= e($batchFormId) ?>" method="post" action="/orders/batch" class="batch-form">
            <?= csrf_field() ?>
            <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
            <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
        </form>
    <?php endif; ?>
    <?php if ($canImportExport): ?>
        <form id="order-export-form" method="post" action="/orders/export" class="batch-form">
                <?= csrf_field() ?>
            <?php foreach ($hiddenFilters as $name => $value): ?>
                <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
            <?php endforeach; ?>
        </form>
        <form id="xizhen-delivery-form" method="post" action="/orders/xizhen-delivery/export" class="batch-form">
                <?= csrf_field() ?>
            <?php foreach ($hiddenFilters as $name => $value): ?>
                <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
            <?php endforeach; ?>
        </form>
    <?php endif; ?>
    <?php if ($canBatchPurchase): ?>
        <form id="send-jp-form" method="post" action="/orders/send-jp" class="batch-form">
                <?= csrf_field() ?>
            <?php foreach ($hiddenFilters as $name => $value): ?>
                <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
            <?php endforeach; ?>
            <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
        </form>
    <?php endif; ?>
    <?php if ($can1688Logistics || $canExpressLogistics || $canJpLogistics): ?>
        <form id="order-logistics-form" method="post" action="/orders/logistics/update" class="batch-form">
                <?= csrf_field() ?>
            <?php foreach ($hiddenFilters as $name => $value): ?>
                <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
            <?php endforeach; ?>
            <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
        </form>
    <?php endif; ?>

    <div class="tbar order-toolbar">
        <div class="tbar-count">已选择 <strong>0</strong> / <strong><?= e($resultTotal) ?></strong> <?= e($resultUnit) ?><?php if ($canSelectAll): ?><button class="select-all-btn" type="button" data-toggle-selection="all">全选</button><?php endif; ?></div>
        <div class="tbar-actions">
            <button class="tgl-all detail-toggle" type="button">展开详情</button>
            <?php if (($orderView === 'jp' && !$canBatchJp) || ($orderView !== 'jp' && !$canBatchPurchase)): ?>
                <span class="batch-label">当前账号没有批量操作权限</span>
            <?php elseif ($orderView === 'jp'): ?>
                <span class="batch-label">批量分配给</span>
                <select class="assign-sel" name="assignee" form="<?= e($batchFormId) ?>"><option value="">选择发货员</option><option>李四</option><option>王五</option><option>赵六</option></select>
                <button class="btn-xs" type="submit" name="batch_action" value="assign_jp" form="<?= e($batchFormId) ?>">分配</button>
                <button class="btn-xs" type="submit" name="batch_action" value="mark_out" form="<?= e($batchFormId) ?>">批量出库</button>
            <?php else: ?>
                <span class="batch-label">采购状态</span>
                <select class="assign-sel" name="purchase_status" form="<?= e($batchFormId) ?>">
                    <option value="">选择状态</option>
                    <?php foreach ($statusOptions as $statusOption): ?>
                        <option><?= e($statusOption) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn-xs" type="submit" name="batch_action" value="set_purchase_status" form="<?= e($batchFormId) ?>">批量改状态</button>
                <span class="batch-label">采购人</span>
                <select class="assign-sel" name="buyer" form="<?= e($batchFormId) ?>"><option value="">选择人员</option><option>王五</option><option>李四</option><option>赵六</option></select>
                <button class="btn-xs" type="submit" name="batch_action" value="assign_buyer" form="<?= e($batchFormId) ?>">批量分配</button>
                <?php if ($orderView === 'platform' && $canBatchOperate): ?><button class="btn-xs danger-text" type="submit" name="batch_action" value="delete_orders" form="<?= e($batchFormId) ?>">批量删除</button><?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$orders): ?>
        <div class="empty">当前视图没有符合条件的订单。</div>
    <?php endif; ?>

    <div class="order-list">
        <?php $seq = 1; ?>
        <?php foreach ($orders as $order): ?>
            <?php require BASE_PATH . '/app/Views/tenant/partials/order_block.php'; ?>
            <?php $seq++; ?>
        <?php endforeach; ?>
    </div>

    <div class="pager">
        <div class="pager-info">
            <span>每页</span>
            <select><option>50</option><option>100</option><option selected>200</option></select>
            <span>第 <?= count($orders) ? '1-' . e(count($orders)) : '0' ?> 条，共 <?= e(count($orders)) ?> 条</span>
        </div>
        <div class="pager-btns">
            <button class="pg" type="button" disabled>«</button>
            <button class="pg" type="button" disabled>‹</button>
            <button class="pg active" type="button">1</button>
            <button class="pg" type="button">›</button>
            <button class="pg" type="button">»</button>
        </div>
    </div>
</div>
