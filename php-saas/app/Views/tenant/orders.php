<?php
$viewLabels = ['platform' => '平台订单', 'purchase' => '采购订单', 'jp' => '日本仓发货'];
$activeTitle = $viewLabels[$orderView] ?? '平台订单';
$totalItems = array_sum(array_map(fn (array $order): int => count($order['items'] ?? []), $orders));
$resultTotal = $orderView === 'platform' ? count($orders) : $totalItems;
$resultUnit = $orderView === 'platform' ? '单' : '件';
$advancedId = 'adv-' . $orderView;
$platformLabel = $platform ? ($platformNames[$platform] ?? $platform) : '全部平台';
$resultLabel = $orderView === 'jp' ? '件待发' : $resultUnit . '结果';
$filters = $filters ?? [];
$currentRequestUri = $_SERVER['REQUEST_URI'] ?? '/orders?tenant=' . $tenantKey;
$returnUrlWithoutNotice = static function (string $url): string {
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }

    $path = (string) ($parts['path'] ?? '/orders');
    parse_str((string) ($parts['query'] ?? ''), $params);
    unset($params['sync_message'], $params['message']);
    $query = http_build_query($params);

    return $path . ($query !== '' ? '?' . $query : '');
};
$returnUrl = $returnUrlWithoutNotice($currentRequestUri);
$deleteChallenge = is_array($deleteChallenge ?? null) ? $deleteChallenge : ['question' => '', 'answer' => ''];
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
$storeNames = array_values(array_unique(array_filter(array_map(
    static fn (array $store): string => (string) (($store['name'] ?? '') ?: ($store['short'] ?? '')),
    $stores
), static fn (string $name): bool => $name !== '')));
$filterValue = static fn (string $key): string => (string) ($filters[$key] ?? '');
$selectedFilter = static fn (string $key, string $value): string => ((string) ($filters[$key] ?? '') === $value) ? 'selected' : '';
$checkedFilter = static fn (string $key): string => trim((string) ($filters[$key] ?? '')) !== '' ? 'checked' : '';
$platformSyncServices = is_array($platformSyncServices ?? null) ? $platformSyncServices : [];
$currentPlatform = (string) ($platform ?? '');
$platformSyncName = $currentPlatform !== '' ? (string) ($platformSyncServices[$currentPlatform] ?? '') : '';
$platformSyncStores = $platformSyncName !== '' ? array_values(array_filter($stores, static fn (array $store): bool => ($store['platform'] ?? '') === $currentPlatform)) : [];
$platformSyncModalTitle = $platformSyncName !== ''
    ? '批量同步 ' . $platformSyncName . ' 订单 — 精品【XIZHENDS】'
    : '批量同步平台订单 — 精品【XIZHENDS】';
$message = trim((string) ($_GET['message'] ?? ''));
$syncMessage = !empty($_GET['sync_message']) ? $message : '';
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
$jpStockStatusOptions = array_values(array_filter(array_map('strval', is_array($jpStockStatusOptions ?? null) ? $jpStockStatusOptions : [])));
$mergedStatusOptions = array_values(array_unique(array_merge($statusOptions, $jpStockStatusOptions)));
$groupedStatusOptions = [
    ['value' => '', 'label' => '---国内采购---', 'disabled' => true],
    ...array_map(static fn (string $status): array => ['value' => $status, 'label' => $status], $statusOptions),
    ['value' => '', 'label' => '---日本仓---', 'disabled' => true],
    ...array_map(static fn (string $status): array => ['value' => $status, 'label' => $status], $jpStockStatusOptions),
];
$statusOptionsForSource = static function (string $sourceType) use ($statusOptions, $jpStockStatusOptions, $mergedStatusOptions): array {
    return match ($sourceType) {
        'jp_stock' => $jpStockStatusOptions,
        'cn_purchase', 'pending' => $statusOptions,
        default => $mergedStatusOptions,
    };
};
$statusOptionsFor = static function (mixed $current, string $sourceType = 'pending') use ($statusOptionsForSource): array {
    $options = $statusOptionsForSource($sourceType);
    $current = trim((string) $current);
    if ($current !== '' && !in_array($current, $options, true)) {
        return array_merge([$current], $options);
    }

    return $options;
};
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$statusFilterOptionsJson = json_encode([
    'all' => [
        ['label' => '---国内采购---', 'disabled' => true],
        ...array_map(static fn (string $status): array => ['value' => $status, 'label' => $status], $statusOptions),
        ['label' => '---日本仓---', 'disabled' => true],
        ...array_map(static fn (string $status): array => ['value' => $status, 'label' => $status], $jpStockStatusOptions),
    ],
    'cn_purchase' => $statusOptions,
    'pending' => $statusOptions,
    'jp_stock' => $jpStockStatusOptions,
], $jsonFlags) ?: '{}';
$filterFields = array_values(array_filter(
    is_array($filterFields ?? null) ? $filterFields : [],
    static fn (mixed $field): bool => is_array($field) && trim((string) ($field['key'] ?? '')) !== ''
));
$receiptCityOptions = array_values(array_filter(array_map('strval', is_array($receiptCityOptions ?? null) ? $receiptCityOptions : []), static fn (string $place): bool => trim($place) !== ''));
$visibleForView = static function (array $field) use ($orderView): bool {
    $views = is_array($field['views'] ?? null) ? $field['views'] : ['platform', 'purchase', 'jp'];

    return in_array($orderView, array_map('strval', $views), true);
};
$pageSizeFields = array_values(array_filter($filterFields, static fn (array $field): bool => ($field['key'] ?? '') === 'page_size' && $visibleForView($field)));
$pageSizeField = $pageSizeFields[0] ?? null;
$basicFields = array_values(array_filter($filterFields, static fn (array $field): bool => ($field['section'] ?? 'basic') === 'basic' && ($field['key'] ?? '') !== 'page_size' && $visibleForView($field)));
$dateRangeFields = array_values(array_filter($filterFields, static fn (array $field): bool => ($field['key'] ?? '') === 'date_range' && $visibleForView($field)));
$dateRangeField = $dateRangeFields[0] ?? null;
$advancedFields = array_values(array_filter($filterFields, static fn (array $field): bool => ($field['section'] ?? 'basic') === 'advanced' && ($field['key'] ?? '') !== 'date_range' && $visibleForView($field)));
$flagFields = array_values(array_filter($filterFields, static fn (array $field): bool => ($field['section'] ?? 'basic') === 'flags' && $visibleForView($field)));
$exportTools = array_values(array_filter(
    is_array($exportTools ?? null) ? $exportTools : [],
    static fn (mixed $tool): bool => is_array($tool) && !empty($tool['visibleWhen'])
));
$syncTool = null;
$toolGroups = ['primary' => [], 'more' => []];
foreach ($exportTools as $tool) {
    $toolKey = (string) ($tool['key'] ?? '');
    if ($toolKey === 'sync_orders') {
        $syncTool = $tool;
        continue;
    }
    $group = (string) ($tool['group'] ?? 'primary');
    if (array_key_exists($group, $toolGroups)) {
        $toolGroups[$group][] = $tool;
    }
}
$urlWithQuery = static function (string $path, array $params): string {
    $params = array_filter($params, static fn (mixed $value): bool => trim((string) $value) !== '');

    return $path . ($params ? '?' . http_build_query($params) : '');
};
$exportUrl = static fn (string $path, array $extra = []): string => $urlWithQuery($path, array_merge($hiddenFilters, $extra));
$fieldOptions = static function (array $field) use ($statusOptionsForSource, $source, $orderView, $storeNames, $receiptCityOptions, $groupedStatusOptions): array {
    $key = (string) ($field['key'] ?? '');
    if (($field['optionsKey'] ?? '') === 'statusOptions') {
        if ($orderView === 'jp') {
            return array_map(static fn (string $status): array => ['value' => $status, 'label' => $status], $statusOptionsForSource('jp_stock'));
        }
        if ($orderView === 'platform' && (string) ($source ?? 'all') === 'all') {
            return $groupedStatusOptions;
        }

        return array_map(static fn (string $status): array => ['value' => $status, 'label' => $status], $statusOptionsForSource((string) ($source ?? 'all')));
    }
    if (($field['optionsKey'] ?? '') === 'storeNames') {
        return array_map(static fn (string $name): array => ['value' => $name, 'label' => $name], $storeNames);
    }
    if (($field['optionsKey'] ?? '') === 'receiptCityOptions') {
        return array_map(static fn (string $place): array => ['value' => $place, 'label' => $place], $receiptCityOptions);
    }
    if (is_array($field['options'] ?? null)) {
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
        if ($key === 'source') {
            $sourceOrder = ['all' => 0, 'cn_purchase' => 1, 'jp_stock' => 2, 'pending' => 3];
            usort($options, static function (array $left, array $right) use ($sourceOrder): int {
                $leftValue = (string) ($left['value'] ?? $left['label'] ?? '');
                $rightValue = (string) ($right['value'] ?? $right['label'] ?? '');
                return ($sourceOrder[$leftValue] ?? 99) <=> ($sourceOrder[$rightValue] ?? 99);
            });
        }
        return $options;
    }

    return [];
};
$fieldCurrentValue = static function (array $field) use ($filterValue, $keyword, $source): string {
    $key = (string) ($field['key'] ?? '');
    if ($key === 'order_no') {
        $value = $filterValue($key);
        return $value !== '' ? $value : (string) $keyword;
    }
    if ($key === 'source') {
        return (string) $source;
    }

    return $filterValue($key);
};
$renderFilterField = static function (array $field, string $extraClass = '') use ($fieldOptions, $fieldCurrentValue, $statusFilterOptionsJson, $orderView, $source, $checkedFilter): void {
    $key = (string) ($field['key'] ?? '');
    $name = (string) ($field['name'] ?? $key);
    $label = (string) ($field['label'] ?? $key);
    $type = (string) ($field['type'] ?? 'text');
    $value = $fieldCurrentValue($field);
    if ($key === 'buyer' && $orderView === 'jp') {
        $label = '发货员';
    }
    $selectAttrs = '';
    if ($key === 'status' && $orderView === 'platform') {
        $selectAttrs = ' data-order-status-filter data-status-options="' . e($statusFilterOptionsJson) . '"';
    }
    if ($key === 'source' && $orderView === 'platform') {
        $selectAttrs = ' data-order-source-filter';
    }
    $class = trim('fg order-search-field order-search-field-' . $key . ($type === 'date_range' ? ' order-date-range-field' : '') . ' ' . $extraClass);
    ?>
    <label class="<?= e($class) ?>">
        <span class="lb"><?= e($label) ?></span>
        <?php if ($type === 'select'): ?>
            <select name="<?= e($name) ?>"<?= $selectAttrs ?>>
                <?php if ($key === 'status'): ?>
                    <option value=""><?= e($orderView === 'jp' ? '全部状态' : '— 待处理订单 —') ?></option>
                    <?php if ($orderView !== 'jp'): ?>
                        <option value="__ALL__" <?= e($value === '__ALL__' ? 'selected' : '') ?>>全部订单</option>
                    <?php endif; ?>
                <?php elseif (in_array($key, ['store', 'buyer'], true)): ?>
                    <option value="">全部</option>
                <?php elseif (!in_array($key, ['page_size', 'source'], true)): ?>
                    <option value="">全部</option>
                <?php endif; ?>
                <?php
                $options = $fieldOptions($field);
                $optionValues = array_map(static fn (array $option): string => (string) ($option['value'] ?? $option['label'] ?? ''), array_filter($options, static fn (array $option): bool => empty($option['disabled'])));
                if ($value !== '' && $value !== '__ALL__' && !in_array($value, $optionValues, true)) {
                    $options[] = ['value' => $value, 'label' => $value];
                }
                ?>
                <?php foreach ($options as $option): ?>
                    <?php
                    $optionValue = (string) ($option['value'] ?? $option['label'] ?? '');
                    $optionLabel = (string) ($option['label'] ?? $optionValue);
                    ?>
                    <option value="<?= e($optionValue) ?>" <?= !empty($option['disabled']) ? 'disabled' : e($value === $optionValue ? 'selected' : '') ?>><?= e($optionLabel) ?></option>
                <?php endforeach; ?>
            </select>
        <?php elseif ($type === 'checkbox'): ?>
            <span class="ck"><input type="checkbox" name="<?= e($name) ?>" value="<?= e($name === 'kong' ? 'no' : '1') ?>" <?= e($checkedFilter($key)) ?>> <?= e($label === '超时发货' ? '勾选' : '空') ?></span>
        <?php elseif ($type === 'date_range'): ?>
            <?php
            $fromName = $orderView === 'platform' ? (string) ($field['from'] ?? 'date_from') : 'date_from';
            $toName = $orderView === 'platform' ? (string) ($field['to'] ?? 'date_to') : 'date_to';
            $fromValueKey = $orderView === 'platform' ? 'order_date_from' : 'date_from';
            $toValueKey = $orderView === 'platform' ? 'order_date_to' : 'date_to';
            ?>
            <span class="order-date-range-control">
                <input class="order-date-input" type="date" name="<?= e($fromName) ?>" value="<?= e($fieldCurrentValue(['key' => $fromValueKey])) ?>">
                <span class="order-date-range-sep">至</span>
                <input class="order-date-input" type="date" name="<?= e($toName) ?>" value="<?= e($fieldCurrentValue(['key' => $toValueKey])) ?>">
            </span>
        <?php else: ?>
            <input type="<?= e($type === 'date' ? 'date' : 'text') ?>" name="<?= e($name) ?>" value="<?= e($value) ?>" placeholder="<?= e($label) ?>">
        <?php endif; ?>
    </label>
    <?php
};
$renderTool = static function (array $tool) use ($tenantKey, $urlWithQuery, $exportUrl): void {
    $toolKey = (string) ($tool['key'] ?? '');
    $label = (string) ($tool['label'] ?? '工具');
    $method = (string) ($tool['method'] ?? 'get');
    $action = (string) ($tool['action'] ?? '#');
    $params = is_array($tool['params'] ?? null) ? $tool['params'] : [];
    $desc = !empty($tool['needsDateRange']) ? '按当前筛选范围' : '进入处理页面';
    if (in_array($toolKey, ['platform_orders_import', 'purchase_import'], true)) {
        $href = $urlWithQuery($action, ['tenant' => $tenantKey, 'job' => (string) ($tool['job'] ?? '')]);
        ?>
        <a class="order-tool-action" href="<?= e($href) ?>"><span><?= e($label) ?></span><em><?= e($toolKey === 'purchase_import' ? '上传采购表' : '上传订单文件') ?></em></a>
        <?php
        return;
    }
    if ($toolKey === 'shipping_import') {
        $href = $urlWithQuery($action, ['tenant' => $tenantKey, 'job' => (string) ($tool['job'] ?? '')]);
        ?>
        <a class="order-tool-action" href="<?= e($href) ?>"><span><?= e($label) ?></span><em>上传运单文件</em></a>
        <?php
        return;
    }
    if ($toolKey === 'export_template') {
        $href = $urlWithQuery($action, ['tenant' => $tenantKey]);
        ?>
        <a class="order-tool-action" href="<?= e($href) ?>"><span><?= e($label) ?></span><em>编辑导出字段</em></a>
        <?php
        return;
    }
    if ($method === 'post' && $action === '/orders/xizhen-delivery/export') {
        ?>
        <button class="order-tool-action" type="submit" form="xizhen-delivery-form"><span><?= e($label) ?></span><em><?= e($desc) ?></em></button>
        <?php
        return;
    }
    if ($method === 'post' && $action === '/orders/images/download') {
        ?>
        <button class="order-tool-action" type="submit" form="product-image-download-form"><span><?= e($label) ?></span><em>补齐缺失主图</em></button>
        <?php
        return;
    }
    if ($method === 'post') {
        ?>
        <button class="order-tool-action" type="submit" name="type" value="<?= e((string) ($tool['type'] ?? '')) ?>" form="order-export-form"><span><?= e($label) ?></span><em><?= e($desc) ?></em></button>
        <?php
        return;
    }
    ?>
    <a class="order-tool-action" href="<?= e($exportUrl($action, $params)) ?>"><span><?= e($label) ?></span><em><?= e($desc) ?></em></a>
    <?php
};
?>
<div class="order-page"<?= $syncMessage !== '' ? ' data-sync-message="' . e($syncMessage) . '"' : '' ?> data-sync-title="<?= e($platformSyncModalTitle) ?>">
    <div class="page-head compact-head">
        <h1><?= e($activeTitle) ?> <span class="plat-tag"><?= e($orderView === 'jp' ? 'JP 仓库现货' : $platformLabel) ?></span></h1>
    </div>

    <?php if ($message !== ''): ?>
        <div class="notice slim"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if (!empty($tenantNotices)): ?>
        <div class="order-notice-strip">
            <?php foreach (($tenantNotices ?? []) as $notice): ?>
                <article class="order-notice-item">
                    <strong><?= e($notice['title'] ?? '') ?></strong>
                    <span><?= e($notice['published_at'] ?? '') ?></span>
                    <p><?= e($notice['body'] ?? '') ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="order-workbench-grid">
        <div class="order-workbench-panel order-search-panel">
            <div class="order-panel-head">
                <span>搜索</span>
                <span class="sub">当前 <?= e($platformLabel) ?></span>
            </div>
            <form class="order-filter order-filter-modern" method="get" action="/orders">
                <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                <input type="hidden" name="view" value="<?= e($orderView) ?>">
                <input type="hidden" name="platform" value="<?= e((string) ($platform ?? '')) ?>">
                <?php if (!in_array('source', array_column($basicFields, 'key'), true)): ?>
                    <input type="hidden" name="source" value="<?= e((string) ($source ?? 'all')) ?>">
                <?php endif; ?>

                <div class="order-search-summary">
                    <div class="fsum">共 <strong><?= e($resultTotal) ?></strong> <?= e($resultLabel) ?></div>
                    <?php if (is_array($pageSizeField)): ?>
                        <?php $renderFilterField($pageSizeField, 'order-page-size-field'); ?>
                    <?php endif; ?>
                </div>

                <div class="order-search-grid">
                    <?php foreach ($basicFields as $field): ?>
                        <?php $renderFilterField($field); ?>
                    <?php endforeach; ?>
                </div>

                <?php if ($advancedFields || $flagFields): ?>
                    <div class="filter-adv" id="<?= e($advancedId) ?>">
                        <div class="filter-adv-title">更多筛选</div>
                        <?php if ($advancedFields || is_array($dateRangeField)): ?>
                            <div class="order-search-grid">
                                <?php foreach ($advancedFields as $field): ?>
                                    <?php $renderFilterField($field); ?>
                                <?php endforeach; ?>
                                <?php if (is_array($dateRangeField)): ?>
                                    <?php $renderFilterField($dateRangeField); ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($flagFields): ?>
                            <div class="filter-foot adv-checks">
                                <div class="filter-cks">
                                    <?php foreach ($flagFields as $field): ?>
                                        <?php
                                        $key = (string) ($field['key'] ?? '');
                                        $label = (string) ($field['label'] ?? $key);
                                        ?>
                                        <label class="ck <?= $key === 'late_ship' ? 'warn' : '' ?>"><input type="checkbox" name="<?= e((string) ($field['name'] ?? $key)) ?>" value="1" <?= e($checkedFilter($key)) ?>> <?= e($label) ?></label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="filter-foot order-filter-actions">
                    <?php if ($advancedFields || $flagFields): ?>
                        <button class="more-btn" type="button" data-adv="<?= e($advancedId) ?>">更多筛选 <span class="arr">▾</span></button>
                    <?php endif; ?>
                    <div class="fsp"></div>
                    <button class="btn btn-p search-btn" type="submit">搜索</button>
                    <a class="btn reset-btn" href="/orders?tenant=<?= e($tenantKey) ?>&view=<?= e($orderView) ?><?= e($platform ? '&platform=' . (string) $platform : '') ?>">重置</a>
                </div>
            </form>
        </div>

        <div class="order-workbench-panel order-tools-panel">
            <div class="order-panel-head">
                <span>同步 / 导入导出</span>
                <span class="sub">常驻工具</span>
            </div>
            <div class="order-tool-list">
                <?php if ($syncTool !== null): ?>
                    <?php if ($orderView === 'platform' && $platformSyncName !== '' && $platformSyncStores): ?>
                        <form class="order-tool-row order-tool-form order-tool-sync" method="post" action="<?= e((string) ($syncTool['action'] ?? '/orders/platform/sync')) ?>" data-sync-modal-title="<?= e($platformSyncModalTitle) ?>" <?= $currentPlatform === 'y' ? 'data-confirm="Yahoo 平台订单同步需要在指定 IP 环境下执行。确认当前网络符合要求后再继续同步？"' : '' ?>>
                            <?= csrf_field() ?>
                            <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                            <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                            <input type="hidden" name="platform" value="<?= e($currentPlatform) ?>">
                            <select name="store_id" aria-label="<?= e($platformSyncName . '店铺') ?>">
                                <?php foreach ($platformSyncStores as $store): ?>
                                    <option value="<?= e($store['id'] ?? '') ?>"><?= e($store['name'] ?? $store['short'] ?? '') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="order-tool-button" type="submit"><?= e((string) ($syncTool['label'] ?? '同步订单')) ?></button>
                        </form>
                    <?php else: ?>
                        <div class="order-tool-row muted" title="请选择已接入 API 的平台和店铺后再同步。">
                            <button class="order-tool-button" type="button" disabled><?= e((string) ($syncTool['label'] ?? '同步订单')) ?></button>
                            <span>未选择可同步店铺</span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php foreach (array_chunk($toolGroups['primary'], 2) as $tools): ?>
                    <div class="order-tool-pair">
                        <?php foreach ($tools as $index => $tool): ?>
                            <?php $renderTool($tool); ?>
                            <?php if ($index === 0 && count($tools) > 1): ?><span class="order-tool-sep">|</span><?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <?php if ($toolGroups['more']): ?>
                    <details class="order-tool-more">
                        <summary><span><?= e('更多导出 ▾') ?></span><em><?= e('低频导出') ?></em></summary>
                        <div class="order-tool-more-list">
                            <?php foreach (array_chunk($toolGroups['more'], 2) as $tools): ?>
                                <div class="order-tool-pair">
                                    <?php foreach ($tools as $index => $tool): ?>
                                        <?php $renderTool($tool); ?>
                                        <?php if ($index === 0 && count($tools) > 1): ?><span class="order-tool-sep">|</span><?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>

                <?php if ($syncTool === null && !$toolGroups['primary'] && !$toolGroups['more']): ?>
                    <div class="order-tool-empty">当前账号没有可用同步、导入或导出工具。</div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if ($canBatchOperate || $canBatchPurchase || $canBatchJp): ?>
        <form id="<?= e($batchFormId) ?>" method="post" action="/orders/batch" class="batch-form">
            <?= csrf_field() ?>
            <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
            <input type="hidden" name="view" value="<?= e($orderView) ?>">
            <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
            <input type="hidden" name="delete_challenge_answer" value="">
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
    <form id="product-image-download-form" method="post" action="/orders/images/download" class="batch-form">
        <?= csrf_field() ?>
        <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
        <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
        <input type="hidden" name="platform" value="<?= e((string) ($platform ?? '')) ?>">
    </form>
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
        <div class="tbar-count">已选择 <strong>0</strong>/<strong><?= e($resultTotal) ?></strong><?= e($resultUnit) ?><?php if ($canSelectAll): ?><button class="select-all-btn" type="button" data-toggle-selection="all">全选</button><?php endif; ?></div>
        <div class="tbar-actions">
            <span class="sep">|</span>
            <button class="tgl-all detail-toggle" type="button">展开详情</button>
            <?php if ($can1688Logistics || $canExpressLogistics || $canJpLogistics): ?>
                <span class="sep">|</span>
            <?php endif; ?>
            <?php if ($can1688Logistics): ?>
                <button class="btn-xs" type="submit" name="type" value="1688" form="order-logistics-form">更新1688物流</button>
            <?php endif; ?>
            <?php if ($canExpressLogistics): ?>
                <button class="btn-xs" type="submit" name="type" value="express" form="order-logistics-form">更新TB/PDD物流</button>
            <?php endif; ?>
            <?php if ($canJpLogistics): ?>
                <button class="btn-xs" type="submit" name="type" value="jp" form="order-logistics-form">更新国际物流</button>
            <?php endif; ?>
            <?php if (($orderView === 'jp' && !$canBatchJp) || ($orderView !== 'jp' && !$canBatchPurchase)): ?>
                <span class="sep">|</span>
                <span class="batch-label">当前账号没有批量操作权限</span>
            <?php elseif ($orderView === 'jp'): ?>
                <span class="sep">|</span>
                <span class="batch-label">采购状态</span>
                <select class="assign-sel" name="purchase_status" form="<?= e($batchFormId) ?>">
                    <option value="">选择状态</option>
                    <?php foreach ($jpStockStatusOptions as $statusOption): ?>
                        <option><?= e($statusOption) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn-xs" type="submit" name="batch_action" value="set_purchase_status" form="<?= e($batchFormId) ?>">批量改状态</button>
                <span class="batch-label">批量分配给</span>
                <select class="assign-sel" name="assignee" form="<?= e($batchFormId) ?>"><option value="">选择发货员</option><option>李四</option><option>王五</option><option>赵六</option></select>
                <button class="btn-xs" type="submit" name="batch_action" value="assign_jp" form="<?= e($batchFormId) ?>">分配</button>
                <button class="btn-xs" type="submit" name="batch_action" value="mark_out" form="<?= e($batchFormId) ?>">批量出库</button>
            <?php else: ?>
                <?php if ($orderView === 'platform' && $canChangeSource): ?>
                    <span class="sep">|</span>
                    <select class="assign-sel" name="source" form="<?= e($batchFormId) ?>">
                        <option value="">选择货源</option>
                        <option value="cn_purchase">国内采购</option>
                        <option value="jp_stock">日本仓</option>
                    </select>
                    <button class="btn-xs batch-setting-btn" type="submit" name="batch_action" value="set_source" form="<?= e($batchFormId) ?>">货源地设置</button>
                <?php endif; ?>
                <span class="sep">|</span>
                <select class="assign-sel" name="purchase_status" form="<?= e($batchFormId) ?>">
                    <option value="">选择状态</option>
                    <?php if ($orderView === 'platform'): ?>
                        <option value="" disabled>---国内采购---</option>
                        <?php foreach ($statusOptions as $statusOption): ?>
                            <option><?= e($statusOption) ?></option>
                        <?php endforeach; ?>
                        <option value="" disabled>---日本仓---</option>
                        <?php foreach ($jpStockStatusOptions as $statusOption): ?>
                            <option><?= e($statusOption) ?></option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php foreach ($statusOptions as $statusOption): ?>
                            <option><?= e($statusOption) ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <button class="btn-xs batch-setting-btn" type="submit" name="batch_action" value="set_purchase_status" form="<?= e($batchFormId) ?>">采购状态设置</button>
                <?php if ($orderView === 'platform' && $canBatchOperate): ?><span class="sep">|</span><button class="btn-xs danger-text" type="submit" name="batch_action" value="delete_orders" form="<?= e($batchFormId) ?>" data-batch-delete-button data-delete-challenge="<?= e((string) ($deleteChallenge['question'] ?? '')) ?>">批量删除</button><?php endif; ?>
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
