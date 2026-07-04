<?php
$viewLabels = ['platform' => '平台订单', 'purchase' => '采购订单', 'jp' => '日本仓发货'];
$activeTitle = $viewLabels[$orderView] ?? '平台订单';
$totalItems = array_sum(array_map(fn (array $order): int => count($order['items'] ?? []), $orders));
$resultTotal = $orderView === 'platform' ? count($orders) : $totalItems;
$resultUnit = $orderView === 'platform' ? '单' : '件';
$advancedId = 'adv-' . $orderView;
$actionsPanelId = 'legacy-actions-' . $orderView;
$platformLabel = $platform ? ($platformNames[$platform] ?? $platform) : '全部平台';
$showRakutenReviewFilters = $orderView === 'platform' && $platform === 'r';
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
$quickExportType = match ($orderView) {
    'platform' => 'platform',
    'jp' => 'shipment',
    default => 'purchase',
};
$canQuickExport = match ($quickExportType) {
    'platform', 'shipment' => $canPlatformImportExport,
    default => $canPurchaseImportExport,
};
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
?>
<div class="order-page">
    <div class="page-head compact-head">
        <h1><?= e($activeTitle) ?> <span class="plat-tag"><?= e($orderView === 'jp' ? 'JP 仓库现货' : $platformLabel) ?></span></h1>
        <div class="head-actions">
            <?php if ($orderView === 'platform' && $platformSyncName !== '' && $canPlatformImportExport && $platformSyncStores): ?>
                <form class="inline-form" method="post" action="/orders/platform/sync">
                <?= csrf_field() ?>
                    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                    <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                    <input type="hidden" name="platform" value="<?= e($currentPlatform) ?>">
                    <input type="hidden" name="days" value="7">
                    <select name="store_id" aria-label="<?= e($platformSyncName . '店铺') ?>">
                        <?php foreach ($platformSyncStores as $store): ?>
                            <option value="<?= e($store['id'] ?? '') ?>"><?= e($store['name'] ?? $store['short'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn" type="submit"><?= e('同步' . $platformSyncName . ' API') ?></button>
                </form>
            <?php endif; ?>
            <?php if ($canQuickExport): ?>
                <button class="btn" type="submit" name="type" value="<?= e($quickExportType) ?>" form="order-export-form" aria-label="导出订单">导出</button>
                <?php if ($orderView === 'platform' && $canPlatformImportExport): ?>
                    <a class="btn" href="/import-export?tenant=<?= e($tenantKey) ?>" aria-label="导入订单">导入订单</a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($canEditOrders): ?><button class="btn btn-p" type="button"><?= e($orderView === 'jp' ? '+ 手动出库' : '+ 手动录入') ?></button><?php endif; ?>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="notice slim"><?= e($message) ?></div>
    <?php endif; ?>
    <?php foreach (($tenantNotices ?? []) as $notice): ?>
        <div class="notice slim">
            <strong><?= e($notice['title'] ?? '') ?></strong>
            <span class="sub"><?= e($notice['published_at'] ?? '') ?></span>
            <div><?= e($notice['body'] ?? '') ?></div>
        </div>
    <?php endforeach; ?>

    <form class="order-filter" method="get" action="/orders">
        <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
        <input type="hidden" name="view" value="<?= e($orderView) ?>">

        <div class="filter-grid">
            <?php if ($orderView === 'jp'): ?>
                <label class="fg">
                    <span class="lb">平台</span>
                    <select name="platform">
                        <option value="">全部平台</option>
                        <?php foreach ($platformNames as $code => $name): ?>
                            <option value="<?= e($code) ?>" <?= e($platform === $code ? 'selected' : '') ?>><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="fg">
                    <span class="lb">出库状态</span>
                    <select name="status">
                        <option value="">全部</option>
                        <option <?= e(($filters['status'] ?? '') === '待分配' ? 'selected' : '') ?>>待分配</option>
                        <option <?= e(($filters['status'] ?? '') === '已分配' ? 'selected' : '') ?>>已分配</option>
                        <option <?= e(($filters['status'] ?? '') === '已出库' ? 'selected' : '') ?>>已出库</option>
                        <option <?= e(($filters['status'] ?? '') === '已发货' ? 'selected' : '') ?>>已发货</option>
                    </select>
                </label>
                <label class="fg">
                    <span class="lb">发货员</span>
                    <select name="buyer">
                        <option value="">全部</option>
                        <option <?= e(($filters['buyer'] ?? '') === '李四' ? 'selected' : '') ?>>李四</option>
                        <option <?= e(($filters['buyer'] ?? '') === '王五' ? 'selected' : '') ?>>王五</option>
                        <option <?= e(($filters['buyer'] ?? '') === '赵六' ? 'selected' : '') ?>>赵六</option>
                    </select>
                </label>
                <label class="fg">
                    <span class="lb">日本仓ID</span>
                    <input type="text" name="q" value="<?= e($keyword) ?>" placeholder="订单号 / 日本仓ID">
                </label>
            <?php else: ?>
                <?php if ($orderView === 'purchase'): ?>
                    <label class="fg">
                        <span class="lb">平台</span>
                        <select name="platform">
                            <option value="">全部平台</option>
                            <?php foreach ($platformNames as $code => $name): ?>
                                <option value="<?= e($code) ?>" <?= e($platform === $code ? 'selected' : '') ?>><?= e($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
                <label class="fg">
                    <span class="lb">订单号</span>
                    <input type="text" name="order_no" value="<?= e($filters['order_no'] ?? $keyword) ?>" placeholder="订单号">
                </label>
                <label class="fg">
                    <span class="lb">1688订单号</span>
                    <input type="text" name="tabaono" value="<?= e($filters['tabaono'] ?? '') ?>" placeholder="1688订单号">
                </label>
                <label class="fg">
                    <span class="lb">采购状态</span>
                    <select name="status">
                        <option value="">— 待处理订单 —</option>
                        <option value="__ALL__" <?= e(($filters['status'] ?? '') === '__ALL__' ? 'selected' : '') ?>>全部订单</option>
                        <?php foreach ($statusOptions as $statusOption): ?>
                            <option value="<?= e($statusOption) ?>" <?= e(($filters['status'] ?? '') === $statusOption ? 'selected' : '') ?>><?= e($statusOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if ($orderView === 'platform'): ?>
                    <label class="fg">
                        <span class="lb">店铺</span>
                        <select name="store">
                            <option value="">全部店铺</option>
                            <?php foreach ($storeNames as $storeName): ?>
                                <option value="<?= e($storeName) ?>" <?= e($filterValue('store') === $storeName ? 'selected' : '') ?>><?= e($storeName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="fg">
                        <span class="lb">货源地</span>
                        <select name="source">
                            <option value="all" <?= e($source === 'all' ? 'selected' : '') ?>>全部货源地</option>
                            <option value="jp_stock" <?= e($source === 'jp_stock' ? 'selected' : '') ?>>日本仓</option>
                            <option value="cn_purchase" <?= e($source === 'cn_purchase' ? 'selected' : '') ?>>国内采购</option>
                            <option value="pending" <?= e($source === 'pending' ? 'selected' : '') ?>>待定</option>
                        </select>
                    </label>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="filter-adv" id="<?= e($advancedId) ?>">
            <div class="filter-adv-title">高级搜索</div>
            <div class="filter-grid">
                <?php if ($orderView === 'jp'): ?>
                    <label class="fg"><span class="lb">店铺</span><select name="store"><option value="">全部店铺</option><?php foreach ($storeNames as $storeName): ?><option value="<?= e($storeName) ?>" <?= e($filterValue('store') === $storeName ? 'selected' : '') ?>><?= e($storeName) ?></option><?php endforeach; ?></select></label>
                    <label class="fg"><span class="lb">仓位</span><input type="text" name="location" value="<?= e($filterValue('location')) ?>" placeholder="如 A-12-3"></label>
                    <label class="fg"><span class="lb">商品名</span><input type="text" name="item_id" value="<?= e($filters['item_id'] ?? '') ?>" placeholder="商品名 / 项目选择"></label>
                    <label class="fg"><span class="lb">国际运单号</span><input type="text" name="intl_ship_no" value="<?= e($filterValue('intl_ship_no')) ?>" placeholder="国际发货单号"></label>
                    <label class="fg"><span class="lb">物流公司</span><input type="text" name="carrier" value="<?= e($filterValue('carrier')) ?>" placeholder="物流公司"></label>
                    <label class="fg col2"><span class="lb">下单时间</span><span class="dwrap"><input type="date" name="date_from" value="<?= e($filters['date_from'] ?? '') ?>"><span>至</span><input type="date" name="date_to" value="<?= e($filters['date_to'] ?? '') ?>"></span></label>
                <?php elseif ($orderView === 'purchase'): ?>
                    <label class="fg"><span class="lb">国内运单号</span><input type="text" name="cn_ship_no" value="<?= e($filterValue('cn_ship_no')) ?>" placeholder="国内发货单号"></label>
                    <label class="fg"><span class="lb">国际运单号</span><input type="text" name="intl_ship_no" value="<?= e($filterValue('intl_ship_no')) ?>" placeholder="国际发货单号"></label>
                    <label class="fg"><span class="lb">ItemId</span><input type="text" name="item_id" value="<?= e($filters['item_id'] ?? '') ?>" placeholder="ItemId"></label>
                    <label class="fg"><span class="lb">lotNumber</span><input type="text" name="lot_number" value="<?= e($filterValue('lot_number')) ?>" placeholder="lotNumber"></label>
                    <label class="fg"><span class="lb">商品管理ID</span><input type="text" name="item_management_id" value="<?= e($filterValue('item_management_id')) ?>" placeholder="商品管理ID"></label>
                    <label class="fg"><span class="lb">商品名</span><input type="text" name="product_name" value="<?= e($filterValue('product_name')) ?>" placeholder="商品名 / 项目选择"></label>
                    <label class="fg"><span class="lb">采购链接</span><input type="text" name="purchase_link" value="<?= e($filterValue('purchase_link')) ?>" placeholder="1688 / 补货链接"></label>
                    <label class="fg"><span class="lb">订单备注</span><input type="text" name="comment" value="<?= e($filterValue('comment')) ?>" placeholder="订单备注"></label>
                    <label class="fg"><span class="lb">采购人</span><select name="buyer"><option value="">全部</option><option <?= e($selectedFilter('buyer', '王五')) ?>>王五</option><option <?= e($selectedFilter('buyer', '李四')) ?>>李四</option><option <?= e($selectedFilter('buyer', '赵六')) ?>>赵六</option></select></label>
                    <label class="fg"><span class="lb">物流公司</span><input type="text" name="carrier" value="<?= e($filterValue('carrier')) ?>" placeholder="物流公司"></label>
                    <label class="fg col2"><span class="lb">采购时间</span><span class="dwrap"><input type="date" name="date_from" value="<?= e($filters['date_from'] ?? '') ?>"><span>至</span><input type="date" name="date_to" value="<?= e($filters['date_to'] ?? '') ?>"></span></label>
                    <label class="fg"><span class="lb">每页</span><select name="page_size"><option value="100" <?= e($selectedFilter('page_size', '100')) ?>>100</option><option value="200" <?= e($filterValue('page_size') === '200' ? 'selected' : '') ?>>200</option><option value="500" <?= e($selectedFilter('page_size', '500')) ?>>500</option><option value="1000" <?= e($selectedFilter('page_size', '1000')) ?>>1000</option></select></label>
                <?php else: ?>
                    <label class="fg"><span class="lb">ItemId</span><input type="text" name="item_id" value="<?= e($filters['item_id'] ?? '') ?>" placeholder="ItemId"></label>
                    <label class="fg"><span class="lb">订单明细ID</span><input type="text" name="order_detail_id" value="<?= e($filterValue('order_detail_id')) ?>" placeholder="orderDetailId"></label>
                    <label class="fg"><span class="lb">lotNumber</span><input type="text" name="lot_number" value="<?= e($filterValue('lot_number')) ?>" placeholder="lotNumber"></label>
                    <label class="fg"><span class="lb">商品管理ID</span><input type="text" name="item_management_id" value="<?= e($filterValue('item_management_id')) ?>" placeholder="商品管理ID"></label>
                    <label class="fg ckline"><span class="lb">lotNumber为空</span><span class="ck"><input type="checkbox" name="lot_number_empty" value="1" <?= e($checkedFilter('lot_number_empty')) ?>> 空</span></label>
                    <label class="fg"><span class="lb">客人邮箱</span><input type="text" name="mail" value="<?= e($filters['mail'] ?? '') ?>" placeholder="邮箱"></label>
                    <label class="fg"><span class="lb">收件人</span><input type="text" name="customer_name" value="<?= e($filters['customer_name'] ?? '') ?>" placeholder="收件人姓名"></label>
                    <label class="fg"><span class="lb">客人电话</span><input type="text" name="phone" value="<?= e($filters['phone'] ?? '') ?>" placeholder="电话"></label>
                    <label class="fg"><span class="lb">片假名</span><input type="text" name="kana" value="<?= e($filterValue('kana')) ?>" placeholder="セイ メイ"></label>
                    <label class="fg"><span class="lb">支付方式</span><input type="text" name="pay_method" value="<?= e($filterValue('pay_method')) ?>" placeholder="支付方式"></label>
                    <label class="fg"><span class="lb">运送方式</span><input type="text" name="ship_method" value="<?= e($filterValue('ship_method')) ?>" placeholder="运送方式"></label>
                    <label class="fg"><span class="lb">国内运单号</span><input type="text" name="cn_ship_no" value="<?= e($filterValue('cn_ship_no')) ?>" placeholder="国内发货单号"></label>
                    <label class="fg"><span class="lb">国际运单号</span><input type="text" name="intl_ship_no" value="<?= e($filterValue('intl_ship_no')) ?>" placeholder="国际发货单号"></label>
                    <label class="fg ckline"><span class="lb">国际单号为空</span><span class="ck"><input type="checkbox" name="intl_ship_empty" value="1" <?= e($checkedFilter('intl_ship_empty')) ?>> 空</span></label>
                    <label class="fg"><span class="lb">采购人</span><select name="buyer"><option value="">全部</option><option <?= e($selectedFilter('buyer', '王五')) ?>>王五</option><option <?= e($selectedFilter('buyer', '李四')) ?>>李四</option><option <?= e($selectedFilter('buyer', '赵六')) ?>>赵六</option></select></label>
                    <label class="fg"><span class="lb">采购链接</span><input type="text" name="purchase_link" value="<?= e($filterValue('purchase_link')) ?>" placeholder="1688 / 补货链接"></label>
                    <label class="fg"><span class="lb">订单备注</span><input type="text" name="comment" value="<?= e($filterValue('comment')) ?>" placeholder="订单备注"></label>
                    <label class="fg"><span class="lb">采购备注</span><input type="text" name="purchase_comment" value="<?= e($filterValue('purchase_comment')) ?>" placeholder="采购备注"></label>
                    <label class="fg"><span class="lb">材质</span><input type="text" name="material" value="<?= e($filterValue('material')) ?>" placeholder="材质"></label>
                    <label class="fg"><span class="lb">国内签收地</span><input type="text" name="receipt_city" value="<?= e($filterValue('receipt_city')) ?>" placeholder="签收地"></label>
                    <?php if ($showRakutenReviewFilters): ?>
                        <label class="fg"><span class="lb">邀评状态</span><select name="review_invited"><option value="">全部</option><option value="1" <?= e($selectedFilter('review_invited', '1')) ?>>已邀评</option><option value="0" <?= e($selectedFilter('review_invited', '0')) ?>>未邀评</option></select></label>
                        <label class="fg"><span class="lb">评价状态</span><select name="reviewed"><option value="">全部</option><option value="1" <?= e($selectedFilter('reviewed', '1')) ?>>已评价</option><option value="0" <?= e($selectedFilter('reviewed', '0')) ?>>未评价</option></select></label>
                    <?php endif; ?>
                    <label class="fg col2"><span class="lb">导入时间</span><span class="dwrap"><input type="date" name="date_from" value="<?= e($filters['date_from'] ?? '') ?>"><span>至</span><input type="date" name="date_to" value="<?= e($filters['date_to'] ?? '') ?>"></span></label>
                    <label class="fg"><span class="lb">每页</span><select name="page_size"><option value="100" <?= e($selectedFilter('page_size', '100')) ?>>100</option><option value="200" <?= e($filterValue('page_size') === '200' ? 'selected' : '') ?>>200</option><option value="500" <?= e($selectedFilter('page_size', '500')) ?>>500</option><option value="1000" <?= e($selectedFilter('page_size', '1000')) ?>>1000</option></select></label>
                <?php endif; ?>
            </div>
            <div class="filter-foot adv-checks">
                <div class="filter-cks">
                    <label class="ck warn"><input type="checkbox" name="late_ship" value="1" <?= e(!empty($filters['late_ship']) ? 'checked' : '') ?>> 超时发货</label>
                    <?php if ($orderView !== 'purchase'): ?>
                        <label class="ck"><input type="checkbox" name="in_delivery" value="1" <?= e(!empty($filters['in_delivery']) ? 'checked' : '') ?>> 配達中</label>
                        <label class="ck"><input type="checkbox" name="delivered" value="1" <?= e(!empty($filters['delivered']) ? 'checked' : '') ?>> 配達完了</label>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="filter-foot">
            <button class="more-btn" type="button" data-adv="<?= e($advancedId) ?>">更多筛选 <span class="arr">▾</span></button>
            <div class="fsp"></div>
            <div class="fsum">共 <strong><?= e($resultTotal) ?></strong> <?= e($resultLabel) ?></div>
            <button class="btn btn-p search-btn" type="submit">搜索</button>
            <a class="btn reset-btn" href="/orders?tenant=<?= e($tenantKey) ?>&view=<?= e($orderView) ?><?= e($platform ? '&platform=' . (string) $platform : '') ?>">重置</a>
        </div>
    </form>

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
            <?php if ($canImportExport || $can1688Logistics || $canExpressLogistics || $canJpLogistics): ?>
                <button class="btn-xs action-panel-toggle" type="button" data-toggle-actions="<?= e($actionsPanelId) ?>" aria-expanded="false" aria-controls="<?= e($actionsPanelId) ?>">导出/物流</button>
                <span class="sep">|</span>
            <?php endif; ?>
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

    <?php if ($canImportExport || $can1688Logistics || $canExpressLogistics || $canJpLogistics): ?>
        <div class="legacy-actions-panel" id="<?= e($actionsPanelId) ?>">
            <?php if ($canImportExport): ?>
                <div class="legacy-actions-group">
                    <span class="legacy-actions-title">导出</span>
                    <div class="legacy-actions-list">
                        <?php if ($canPlatformImportExport): ?>
                            <button class="btn-xs" type="submit" name="type" value="platform" form="order-export-form">平台订单表</button>
                            <button class="btn-xs" type="submit" name="type" value="shipment" form="order-export-form">订单发货表</button>
                            <button class="btn-xs" type="submit" form="xizhen-delivery-form">西阵发货表</button>
                            <button class="btn-xs" type="submit" name="type" value="delivery_notice" form="order-export-form">发货通知表</button>
                        <?php endif; ?>
                        <?php if ($canFinanceExport): ?>
                            <button class="btn-xs" type="submit" name="type" value="finance" form="order-export-form">财务核算表</button>
                        <?php endif; ?>
                        <?php if ($canPurchaseImportExport): ?>
                            <button class="btn-xs" type="submit" name="type" value="purchase" form="order-export-form">待采购单</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($canBatchPurchase): ?>
                <div class="legacy-actions-group">
                    <span class="legacy-actions-title">发货流程</span>
                    <div class="legacy-actions-list">
                        <button class="btn-xs" type="submit" form="send-jp-form">批量已发日本</button>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($can1688Logistics || $canExpressLogistics || $canJpLogistics): ?>
                <div class="legacy-actions-group">
                    <span class="legacy-actions-title">物流</span>
                    <div class="legacy-actions-list">
                        <?php if ($can1688Logistics): ?>
                            <button class="btn-xs" type="submit" name="type" value="1688" form="order-logistics-form">更新1688物流</button>
                        <?php endif; ?>
                        <?php if ($canExpressLogistics): ?>
                            <button class="btn-xs" type="submit" name="type" value="express" form="order-logistics-form">更新TB/PDD物流</button>
                        <?php endif; ?>
                        <?php if ($canJpLogistics): ?>
                            <button class="btn-xs" type="submit" name="type" value="jp" form="order-logistics-form">更新国际物流</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

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
