<?php
$logId = 'logs-' . (int) $order['id'];
$customer = $order['customer'] ?? [];
$seq = $seq ?? 1;
$showA = $orderView === 'platform';
$hiddenB2Class = $orderView === 'platform' ? ' table-hidden' : '';
$hiddenCClass = $orderView === 'platform' ? ' table-hidden' : '';
$isRakuten = ($order['platform'] ?? '') === 'r';
$orderExtra = is_array($order['platform_extra'] ?? null) ? $order['platform_extra'] : [];
$importedAt = (string) (($order['imported_at'] ?? '') ?: ($order['order_date'] ?? ''));
$payMethod = (string) ($order['pay_method'] ?? '');
$shipMethod = (string) ($order['ship_method'] ?? '');
$orderStatus = (string) (($order['status'] ?? '') ?: ($orderExtra['OrderStatus'] ?? $orderExtra['orderStatus'] ?? '-'));
$payStatus = (string) (($order['pay_status'] ?? $order['payment_status'] ?? '') ?: ($orderExtra['PayStatus'] ?? $orderExtra['payStatus'] ?? '-'));
$payDate = (string) (($order['pay_date'] ?? $order['payment_date'] ?? '') ?: ($orderExtra['PayDate'] ?? $orderExtra['payDate'] ?? '-'));
$batchFormId = $batchFormId ?? ('batch-' . $orderView);
$detailUrl = '/orders/detail?tenant=' . rawurlencode((string) $tenantKey) . '&id=' . rawurlencode((string) ($order['id'] ?? '')) . '&return=' . rawurlencode((string) $returnUrl);
$statusOptions = array_values(array_filter(array_map('strval', is_array($statusOptions ?? null) ? $statusOptions : [])));
$jpStockStatusOptions = array_values(array_filter(array_map('strval', is_array($jpStockStatusOptions ?? null) ? $jpStockStatusOptions : [])));
$mergedStatusOptions = array_values(array_unique(array_merge($statusOptions, $jpStockStatusOptions)));
$statusOptionsForSource = static function (string $sourceType) use ($statusOptions, $jpStockStatusOptions, $mergedStatusOptions): array {
    return match ($sourceType) {
        'cn_purchase' => $statusOptions,
        'jp_stock' => $jpStockStatusOptions,
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
$purchaseStatusOptionsJson = json_encode([
    'cn_purchase' => $statusOptions,
    'pending' => $mergedStatusOptions,
    'all' => $mergedStatusOptions,
    'jp_stock' => $jpStockStatusOptions,
], $jsonFlags) ?: '{}';
$receiptCityOptions = array_values(array_filter(
    array_map('strval', is_array($receiptCityOptions ?? null) ? $receiptCityOptions : []),
    static fn (string $place): bool => trim($place) !== ''
));
$canEditOrders = (bool) ($canEditOrders ?? false);
$canEditPurchase = (bool) ($canEditPurchase ?? false);
$canEditJp = (bool) ($canEditJp ?? false);
$canChangeSource = (bool) ($canChangeSource ?? false);
$canBatchOperate = (bool) ($canBatchOperate ?? false);
$canBatchPurchase = (bool) ($canBatchPurchase ?? false);
$canBatchJp = (bool) ($canBatchJp ?? false);
$canUploadImage = (bool) ($canUploadImage ?? false);
$canSelectItem = ($orderView === 'purchase' && $canBatchPurchase) || ($orderView === 'jp' && $canBatchJp);
$canEditThisView = $canEditOrders || ($orderView === 'purchase' && $canEditPurchase) || ($orderView === 'jp' && $canEditJp) || ($orderView === 'platform' && ($canEditPurchase || $canEditJp || $canChangeSource));
$safeHttpUrl = static function (mixed $url): string {
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
    return in_array($scheme, ['http', 'https'], true) ? $url : '';
};
$moneyText = static function (mixed $value, string $empty = '-'): string {
    $raw = trim((string) $value);
    if ($raw === '') {
        return $empty;
    }

    return '￥' . number_format((float) $raw, 0);
};
$joinLines = static function (array $values): string {
    return implode(' / ', array_values(array_filter(array_map(
        static fn (mixed $value): string => trim((string) $value),
        $values
    ), static fn (string $value): bool => $value !== '')));
};
$shortText = static function (mixed $value, int $limit = 34): string {
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $limit, '...', 'UTF-8');
    }

    return $text;
};
$extraValue = static function (array $extra, array $keys): string {
    foreach ($keys as $key) {
        $value = $extra[$key] ?? null;
        if (is_scalar($value) && trim((string) $value) !== '') {
            return trim((string) $value);
        }
    }

    return '';
};
$storeByName = [];
$storeById = [];
$storesForPlatform = [];
foreach (is_array($stores ?? null) ? $stores : [] as $storeRow) {
    if (!is_array($storeRow)) {
        continue;
    }

    $storeNamesForRow = array_filter(array_map(
        static fn (mixed $value): string => trim((string) $value),
        [$storeRow['name'] ?? '', $storeRow['short'] ?? '']
    ), static fn (string $value): bool => $value !== '');
    foreach ($storeNamesForRow as $storeNameForRow) {
        $storeByName[$storeNameForRow] = $storeRow;
    }

    $storeId = (int) ($storeRow['id'] ?? 0);
    if ($storeId > 0) {
        $storeById[$storeId] = $storeRow;
    }

    $storePlatform = (string) ($storeRow['platform'] ?? '');
    if ($storePlatform !== '') {
        $storesForPlatform[$storePlatform][] = $storeRow;
    }
}
$storeMeta = $storeById[(int) ($order['store_id'] ?? 0)] ?? ($storeByName[(string) ($order['store'] ?? '')] ?? null);
if ($storeMeta === null && count($storesForPlatform[(string) ($order['platform'] ?? '')] ?? []) === 1) {
    $storeMeta = $storesForPlatform[(string) ($order['platform'] ?? '')][0];
}
$storeLegacyId = trim((string) ($storeMeta['legacy_dpid'] ?? ''));
$storeShort = trim((string) ($storeMeta['short'] ?? ''));
$storeLabel = $joinLines([
    $storeShort,
    $storeLegacyId !== '' ? '店铺番号 ' . $storeLegacyId : '',
]);
$productUrlFor = static function (array $order, array $item, string $legacyDpid) use ($extraValue, $safeHttpUrl): string {
    $itemExtra = is_array($item['platform_extra'] ?? null) ? $item['platform_extra'] : [];
    $url = $safeHttpUrl($extraValue($itemExtra, ['EntryPoint', 'entryPoint', 'product_url', 'url']));
    if ($url !== '') {
        return $url;
    }

    $platform = (string) ($order['platform'] ?? '');
    $itemCode = trim((string) (($item['item_code'] ?? '') ?: $extraValue($itemExtra, ['ItemId', 'itemCode'])));
    $lotNumber = trim((string) (($item['lot_number'] ?? '') ?: $extraValue($itemExtra, ['lotnumber'])));
    $encodedItem = rawurlencode($itemCode);
    $encodedLot = rawurlencode($lotNumber !== '' ? $lotNumber : $itemCode);

    return match ($platform) {
        'y' => $legacyDpid !== '' && $itemCode !== '' ? "https://store.shopping.yahoo.co.jp/{$legacyDpid}/{$encodedItem}.html" : '',
        'r' => $legacyDpid !== '' && $itemCode !== '' ? "https://item.rakuten.co.jp/{$legacyDpid}/{$encodedItem}/" : '',
        'w' => $encodedLot !== '' ? "https://wowma.jp/item/{$encodedLot}" : '',
        'm' => $encodedLot !== '' ? "https://jp.mercari.com/shops/product/{$encodedLot}" : '',
        'q' => $encodedLot !== '' ? "https://www.qoo10.jp/g/{$encodedLot}" : '',
        default => '',
    };
};

$sourceLabel = static fn (string $source): string => match ($source) {
    'cn_purchase' => '国内采购',
    'jp_stock' => '日本仓',
    default => '待定',
};
$sourceClass = static fn (string $source): string => match ($source) {
    'cn_purchase' => 'cn',
    'jp_stock' => 'jp',
    default => 'pending',
};
$statusClass = static fn (string $status): string => str_contains($status, '已') ? 's-buying' : (str_contains($status, '发货') ? 's-shipping' : 's-pending');
$outStatusClass = static fn (string $status): string => match ($status) {
    '已分配' => 'assigned',
    '已出库' => 'out',
    '已发货' => 'sent',
    default => 'wait',
};
$canPriceQuote = \Xizhen\Core\Permission::hasAny($currentUser ?? null, ['订单查看', '订单编辑']);
?>
<article class="order-block">
    <?php if ($showA): ?>
        <table class="otable sec-a order-info-table">
            <colgroup><?php for ($i = 0; $i < 15; $i++): ?><col class="c<?= e($i) ?>"><?php endfor; ?></colgroup>
            <thead>
            <tr>
                <th class="c0"><span class="seq-no"><?= e($seq) ?></span></th>
                <th class="c1" colspan="2">导入时间</th>
                <th class="c3">客人姓名/片假名</th>
                <th class="c4" colspan="4">地址</th>
                <th class="c6">邮编</th>
                <th class="c7">电话</th>
                <th class="c8">邮箱</th>
                <th class="c9">支付方式</th>
                <th class="c10">运送方式</th>
                <th class="c11">订单状态</th>
                <th class="c14">邀评/评价</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td><?php if ($canBatchOperate): ?><input class="order-check" type="checkbox" name="order_ids[]" value="<?= e($order['id']) ?>" form="<?= e($batchFormId) ?>" aria-label="选择订单"><?php else: ?><span class="seq-no"><?= e($seq) ?></span><?php endif; ?></td>
                <td colspan="2"><?= e($importedAt) ?></td>
                <td class="stack-cell">
                    <span class="stack-main"><?= e($customer['name'] ?? '') ?></span>
                    <?php if (trim((string) ($customer['kana'] ?? '')) !== ''): ?><span class="oid-sub"><?= e($customer['kana']) ?></span><?php endif; ?>
                </td>
                <td colspan="4" title="<?= e($customer['address'] ?? '') ?>"><?= e($customer['address'] ?? '') ?></td>
                <td><?= e($customer['zip'] ?? '') ?></td>
                <td><?= e($customer['phone'] ?? '') ?></td>
                <td><?= e($customer['mail'] ?? '') ?></td>
                <td><?= e($payMethod) ?></td>
                <td><?= e($shipMethod) ?></td>
                <td><span class="order-state-tag"><?= e($orderStatus) ?></span></td>
                <td>
                    <span class="review-tags">
                        <span class="<?= !empty($order['review_invited']) ? 'on' : '' ?>">邀</span>
                        <span class="<?= !empty($order['reviewed']) ? 'on' : '' ?>">评</span>
                    </span>
                </td>
            </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <table class="otable sec-b item-info-table<?= !$showA ? ' with-seq-col' : '' ?>">
        <colgroup><?php for ($i = 0; $i < 15; $i++): ?><col class="c<?= e($i) ?>"><?php endfor; ?></colgroup>
        <thead>
        <tr>
            <?php if ($showA): ?>
                <th class="c0">图片</th>
                <th class="c1" colspan="2">订单ID / 店铺</th>
            <?php else: ?>
                <th class="c0"><span class="seq-no"><?= e($seq) ?></span></th>
                <th class="c1">图片</th>
                <th class="c2">订单ID / 店铺</th>
            <?php endif; ?>
            <th class="c3">订单时间 / 明细ID</th>
            <th class="c4">货源地 / 采购状态</th>
            <th class="c5">ItemId / lotNumber</th>
            <th class="c6">日本仓ID / 管理ID</th>
            <th class="c7">商品属性</th>
            <th class="c8" colspan="2">商品标题 / 项目选择</th>
            <th class="c10">数量</th>
            <th class="c11">单价</th>
            <th class="c12">邮费/手续费</th>
            <th class="c13">总价/请求金额</th>
            <th class="c14">操作</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($order['items'] as $itemIndex => $item): ?>
            <?php
            $itemExtra = is_array($item['platform_extra'] ?? null) ? $item['platform_extra'] : [];
            $itemSource = $item['source_type'] ?? 'pending';
            $unitPrice = (float) ($item['unit_price'] ?? $item['amount'] ?? 0);
            $shippingFee = (float) ($item['postage_price'] ?? 0);
            $payFee = (float) ($item['pay_charge'] ?? 0);
            $totalAmountRaw = trim((string) ($item['line_total'] ?? ''));
            if ($totalAmountRaw === '') {
                $totalAmountRaw = $extraValue($itemExtra, ['TotalPrice', 'totalPrice']);
            }
            $requestAmountRaw = $extraValue($itemExtra, ['requestPrice']);
            $displayAmountRaw = $totalAmountRaw !== '' ? $totalAmountRaw : $requestAmountRaw;
            $displayAmountLabel = $totalAmountRaw !== '' ? '总价' : ($requestAmountRaw !== '' ? '请求金额' : '总价');
            $lineTotal = (float) ($displayAmountRaw !== '' ? $displayAmountRaw : (($unitPrice * max(1, (int) ($item['quantity'] ?? 1))) + $shippingFee + $payFee));
            $quoteSalePrice = $unitPrice > 0 ? ($unitPrice + $shippingFee) : $lineTotal;
            $quoteCost = (float) (($item['amount'] ?? 0) ?: ($item['purchase_amount'] ?? 0) ?: ($item['cn_amount'] ?? 0));
            $productUrl = $productUrlFor($order, $item, $storeLegacyId);
            $shopLine = $joinLines([
                (string) ($order['store'] ?? ''),
                $storeLabel,
            ]);
            $shippingRequestMeta = $joinLines([
                $extraValue($itemExtra, ['ShipNotes', 'deliveryRequest1']),
                $extraValue($itemExtra, ['ShipRequestDate', 'deliveryRequest2']),
                $extraValue($itemExtra, ['ShipRequestTime']),
            ]);
            $commissionMeta = $joinLines([
                $extraValue($itemExtra, ['itemOptionCommission1']),
                $extraValue($itemExtra, ['itemOptionCommission2']),
                $extraValue($itemExtra, ['itemOptionCommission3']),
                $extraValue($itemExtra, ['itemOptionCommission4']),
                $extraValue($itemExtra, ['itemOptionCommission5']),
            ]);
            $itemDetailMeta = $joinLines([
                (string) ($item['order_detail_id'] ?? ''),
                (string) ($item['line_id'] ?? ''),
            ]);
            $itemCodeMeta = $joinLines([
                (string) ($item['lot_number'] ?? ''),
            ]);
            $warehouseMeta = $joinLines([
                (string) ($item['item_management_id'] ?? ''),
            ]);
            $optionMeta = $joinLines([
                (string) ($item['option'] ?? ''),
                (string) ($item['chinese_option'] ?? ''),
                $commissionMeta,
            ]);
            ?>
            <tr class="item-row">
                <?php if (!$showA): ?>
                    <td class="seq-cell">
                        <?php if ($canSelectItem): ?>
                            <input class="item-check" type="checkbox" name="item_ids[]" value="<?= e($item['id']) ?>" form="<?= e($batchFormId) ?>" aria-label="选择商品 <?= e($item['item_code'] ?: $item['id']) ?>">
                        <?php endif; ?>
                    </td>
                <?php endif; ?>
                <td class="img-cell">
                    <a class="order-image-link" href="<?= e($item['image']) ?>" target="_blank" rel="noopener noreferrer" data-preview-src="<?= e($item['image']) ?>">
                        <img class="order-img" src="<?= e($item['image']) ?>" alt="<?= e($item['title']) ?>">
                    </a>
                </td>
                <td<?php if ($showA): ?> colspan="2"<?php endif; ?> class="stack-cell">
                    <a href="<?= e($detailUrl) ?>" class="oid-link"><?= e($order['platform_order_id']) ?></a>
                    <?php if ($shopLine !== ''): ?><span class="oid-sub"><?= e($shopLine) ?></span><?php endif; ?>
                </td>
                <td class="stack-cell">
                    <span class="stack-main"><?= e($order['order_date']) ?></span>
                    <?php if ($itemDetailMeta !== ''): ?><span class="oid-sub">明细 <?= e($itemDetailMeta) ?></span><?php endif; ?>
                    <?php if ($shippingRequestMeta !== ''): ?><span class="oid-sub" title="<?= e($shippingRequestMeta) ?>">发货要求 <?= e($shortText($shippingRequestMeta, 32)) ?></span><?php endif; ?>
                </td>
                <td class="source-status-cell">
                    <span class="src-tag <?= e($sourceClass($itemSource)) ?>"><?= e($sourceLabel($itemSource)) ?></span>
                    <span class="status <?= e($statusClass((string) ($item['purchase_status'] ?? ''))) ?>"><?= e($item['purchase_status']) ?></span>
                </td>
                <td class="stack-cell">
                    <?php if ($productUrl !== ''): ?>
                        <a class="stack-main accent-link" href="<?= e($productUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($item['item_code']) ?></a>
                    <?php else: ?>
                        <span class="stack-main"><?= e($item['item_code']) ?></span>
                    <?php endif; ?>
                    <?php if ($itemCodeMeta !== ''): ?><span class="oid-sub"><?= e($itemCodeMeta) ?></span><?php endif; ?>
                </td>
                <td class="stack-cell">
                    <span class="stack-main"><?= e($item['jp_warehouse_id']) ?></span>
                    <?php if ($warehouseMeta !== ''): ?><span class="oid-sub"><?= e($warehouseMeta) ?></span><?php endif; ?>
                </td>
                <td><?= e($item['option']) ?></td>
                <td colspan="2" class="stack-cell product-title-cell" title="<?= e($item['title']) ?>">
                    <span class="stack-main"><?= e($item['title']) ?></span>
                    <?php if ($optionMeta !== ''): ?><span class="oid-sub"><?= e($optionMeta) ?></span><?php endif; ?>
                </td>
                <td><span class="qty-val">×<?= e($item['quantity']) ?></span></td>
                <td><span class="price-val<?php if ($canPriceQuote): ?> price-quote-trigger<?php endif; ?>"<?php if ($canPriceQuote): ?> tabindex="0" role="button" aria-label="打开核价浮层" title="悬停核价" data-price-quote-trigger data-item-id="<?= e($item['id']) ?>" data-sale-price="<?= e($quoteSalePrice) ?>" data-shipping="<?= e(($item['com_amount'] ?? 0) ?: '') ?>" data-cost="<?= e($quoteCost) ?>"<?php endif; ?>>￥<?= e(number_format($unitPrice, 0)) ?></span></td>
                <td>￥<?= e(number_format($shippingFee, 0)) ?>/￥<?= e(number_format($payFee, 0)) ?></td>
                <td>
                    <span class="price-val">￥<?= e(number_format($lineTotal, 0)) ?></span>
                    <span class="oid-sub"><?= e($displayAmountLabel) ?></span>
                </td>
                <td class="op-cell">
                    <?php if ($canEditThisView): ?><button class="log-btn edit-drawer-btn" type="button" data-open-editor="editor-<?= e($item['id']) ?>">编辑</button><?php endif; ?>
                    <button class="log-btn" type="button" data-toggle-logs="<?= e($logId) ?>">日志</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <table class="otable sec-b purchase-info-table<?= e($hiddenB2Class) ?>">
        <colgroup><?php for ($i = 0; $i < 15; $i++): ?><col class="c<?= e($i) ?>"><?php endfor; ?></colgroup>
        <thead>
        <?php if ($orderView === 'jp'): ?>
            <tr><th class="c0" colspan="2">出库状态</th><th class="c2">发货员</th><th class="c3">出库时间</th><th class="c4" colspan="2">仓位</th><th class="c6">订单备注</th><th class="c7">出库成本</th><th class="c8" colspan="2">出库单号</th><th class="c10">物流公司</th><th class="c11" colspan="4">国内运单号</th></tr>
        <?php else: ?>
            <tr><th class="c0" colspan="2">采购人</th><th class="c2" colspan="2">采购时间</th><th class="c4" colspan="2">采购链接</th><th class="c6" colspan="3">订单备注</th><th class="c9">采购金额</th><th class="c10" colspan="2">1688订单号</th><th class="c12">物流公司</th><th class="c13" colspan="2">国内运单号 / 签收地</th></tr>
        <?php endif; ?>
        </thead>
        <tbody>
        <?php foreach ($order['items'] as $item): ?>
            <tr class="item-row">
                <?php if ($orderView === 'jp'): ?>
                    <?php $outStatus = (string) ($item['out_status'] ?? '待分配'); ?>
                    <td colspan="2"><span class="out-st <?= e($outStatusClass($outStatus)) ?>"><?= e($outStatus) ?></span></td>
                    <td><?= e($item['assignee'] ?: '未分配') ?></td>
                    <td><?= e(($item['out_time'] ?? '') ?: '-') ?></td>
                    <td colspan="2"><span class="jp-note"><?= e(($item['location'] ?? '') ?: ($item['jp_warehouse_id'] ?: '-')) ?></span></td>
                    <td><?= e(($item['comment'] ?? '') ?: '-') ?></td>
                    <td>￥<?= e(number_format((float) ($item['out_cost'] ?? 0), 0)) ?></td>
                    <td colspan="2"><?= e(($item['out_no'] ?? '') ?: ('OUT-' . (string) $item['id'])) ?></td>
                    <td><?= e($item['ship_company'] ?: '-') ?></td>
                    <td colspan="4"><?= e($item['ship_number'] ?: '-') ?></td>
                <?php else: ?>
                    <?php
                    $purchaseLink = $safeHttpUrl($item['purchase_link'] ?? '');
                    $noteText = $joinLines([
                        $item['comment'] ?? '',
                        $item['tranship_comment'] ?? '',
                        $item['chinese_option'] ?? '',
                    ]);
                    $domesticShipMeta = $joinLines([
                        $item['ship_number'] ?? '',
                        $item['receipt_city'] ?? '',
                    ]);
                    $domesticLogisticsUrl = trim((string) ($item['tabaono'] ?? '')) !== ''
                        ? '/logistics/1688?tenant=' . rawurlencode((string) $tenantKey) . '&q=' . rawurlencode((string) ($item['tabaono'] ?? ''))
                        : '/logistics/express?tenant=' . rawurlencode((string) $tenantKey) . '&q=' . rawurlencode((string) ($item['ship_number'] ?? ''));
                    $caigouNumbers = $joinLines([
                        $item['tabaono'] ?? '',
                        $item['caigou_ordernums'] ?? '',
                    ]);
                    ?>
                    <td colspan="2"><?= e($item['buyer'] ?: '-') ?></td>
                    <td colspan="2"><?= e($item['purchase_time'] ?: '-') ?></td>
                    <td colspan="2">
                        <?php if ($purchaseLink !== ''): ?>
                            <a class="accent-link" href="<?= e($purchaseLink) ?>" target="_blank" rel="noopener noreferrer">1688 商品页</a>
                        <?php else: ?>
                            <?= e(($item['purchase_link'] ?? '') !== '' ? '链接协议不允许' : '-') ?>
                        <?php endif; ?>
                    </td>
                    <td colspan="3" title="<?= e($noteText) ?>"><?= e($noteText !== '' ? $noteText : '-') ?></td>
                    <td><?= e($moneyText($item['purchase_amount'] ?? $item['amount'] ?? '')) ?></td>
                    <td colspan="2" title="<?= e($caigouNumbers) ?>"><?= e($caigouNumbers !== '' ? $caigouNumbers : '-') ?></td>
                    <td><?= e($item['ship_company'] ?: '-') ?></td>
                    <td colspan="2" class="stack-cell">
                        <span class="stack-main"><?= e($domesticShipMeta !== '' ? $domesticShipMeta : '-') ?></span>
                        <?php if (trim((string) ($item['ship_number'] ?? '')) !== '' || trim((string) ($item['tabaono'] ?? '')) !== ''): ?><a class="oid-sub accent-link" href="<?= e($domesticLogisticsUrl) ?>">查看货运</a><?php endif; ?>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <table class="otable sec-c<?= e($hiddenCClass) ?>">
        <colgroup><?php for ($i = 0; $i < 15; $i++): ?><col class="c<?= e($i) ?>"><?php endfor; ?></colgroup>
        <thead><tr><th class="c0" colspan="4">国际运单号</th><th class="c4" colspan="2">国际运单状态</th><th class="c6">国际运费</th><th class="c7">件数</th><th class="c8">产品重量</th><th class="c9">利润(RMB)</th><th class="c10" colspan="5">国际备注</th></tr></thead>
        <tbody>
        <?php foreach ($order['items'] as $item): ?>
            <?php
            $intlNumber = (string) ($item['intl_number'] ?? '');
            $intlStatus = (string) (($item['intl_status'] ?? '') ?: '待发货');
            $intlQty = (int) (($item['intl_qty'] ?? 0) ?: ($item['ship_quantity'] ?? 0) ?: ($item['quantity'] ?? 0));
            $intlFee = ($item['intl_fee'] ?? 0) ?: ($item['com_amount'] ?? 0);
            $intlCompletedAt = trim((string) ($item['jpship_completed_at'] ?? ''));
            $intlStatusMeta = $intlCompletedAt !== '' ? $intlStatus . ' / ' . $intlCompletedAt : $intlStatus;
            $intlTrackingUrl = $intlNumber !== ''
                ? '/logistics/jpyd-check?tenant=' . rawurlencode((string) $tenantKey) . '&number=' . rawurlencode($intlNumber)
                : '';
            $intlNote = $joinLines([
                $item['intl_comment'] ?? '',
                $item['tranship_comment'] ?? '',
            ]);
            ?>
            <tr class="item-row"><td colspan="4" class="stack-cell"><span class="stack-main"><?= e($intlNumber !== '' ? $intlNumber : '待生成') ?></span><?php if ($intlTrackingUrl !== ''): ?><a class="oid-sub accent-link" href="<?= e($intlTrackingUrl) ?>" target="_blank" rel="noopener noreferrer">查看国际物流状态</a><?php endif; ?></td><td colspan="2"><span class="intl-pend" title="<?= e($intlStatusMeta) ?>"><?= e($intlStatusMeta) ?></span></td><td><?= e($moneyText($intlFee)) ?></td><td><?= e($intlQty) ?></td><td><?= e((string) (($item['intl_weight'] ?? 0) ?: ($item['weight'] ?? '-'))) ?></td><td><?= e($moneyText($item['cn_amount'] ?? '')) ?></td><td colspan="5" title="<?= e($intlNote) ?>"><?= e($intlNote !== '' ? $intlNote : '-') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="log-panel" id="<?= e($logId) ?>">
        <div class="log-inner">
            <h4>操作日志</h4>
            <table class="log-table">
                <thead><tr><th style="width:105px">时间</th><th style="width:55px">操作人</th><th style="width:95px">操作类型</th><th>变更字段</th><th>变更详情</th><th style="width:110px">IP</th></tr></thead>
                <tbody>
                <?php foreach ($order['items'] as $item): ?>
                    <?php foreach ($item['logs'] ?? [] as $log): ?>
                        <tr>
                            <td class="log-time"><?= e($log['time']) ?></td>
                            <td class="log-user"><?= e($log['user']) ?></td>
                            <td><?= e($log['action']) ?></td>
                            <td><?= e($log['field']) ?></td>
                            <td><span class="log-old"><?= e($log['old']) ?></span><span class="log-arrow">→</span><span class="log-new"><?= e($log['new']) ?></span></td>
                            <td class="log-ip"><?= e($log['ip']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($canEditThisView): ?>
    <?php foreach ($order['items'] as $item): ?>
        <?php
        $itemExtra = is_array($item['platform_extra'] ?? null) ? $item['platform_extra'] : [];
        $entryPoint = $extraValue($itemExtra, ['EntryPoint', 'entryPoint', 'product_url', 'url']);
        $shipName = (string) (($customer['name'] ?? '') ?: $extraValue($orderExtra, ['ShipName', 'senderName']));
        $shipAddress1 = (string) (($customer['address'] ?? '') ?: $extraValue($orderExtra, ['ShipAddress1', 'senderAddress', 'shipping_address_1']));
        $shipAddress2 = $extraValue($orderExtra, ['ShipAddress2', 'shipping_address_2']);
        $shipCity = $extraValue($orderExtra, ['ShipCity', 'shipping_city']);
        $shipPrefecture = $extraValue($orderExtra, ['ShipPrefecture', 'shipping_state']);
        $shipZipCode = (string) (($customer['zip'] ?? '') ?: $extraValue($orderExtra, ['ShipZipCode', 'senderZipCode', 'shipping_postal_code']));
        $shipPhoneNumber = (string) (($customer['phone'] ?? '') ?: $extraValue($orderExtra, ['ShipPhoneNumber', 'senderPhoneNumber1']));
        $drawerStatusSource = $orderView === 'platform' ? 'all' : (string) ($item['source_type'] ?? 'pending');
        $currentReceiptCity = trim((string) ($item['receipt_city'] ?? ''));
        $receiptCityChoices = $receiptCityOptions;
        if ($currentReceiptCity !== '' && !in_array($currentReceiptCity, $receiptCityChoices, true)) {
            array_unshift($receiptCityChoices, $currentReceiptCity);
        }
        $mainImage = trim((string) ($item['image'] ?? ''));
        $skuImage = trim((string) ($item['sku_image'] ?? ''));
        $mainImageFormId = 'drawer-image-main-' . (int) $item['id'];
        $skuImageFormId = 'drawer-image-sku-' . (int) $item['id'];
        ?>
        <aside class="editor-drawer" id="editor-<?= e($item['id']) ?>" aria-hidden="true">
            <div class="drawer-head">
                <div>
                    <strong>编辑订单</strong>
                    <div class="sub"><?= e($order['platform_order_id']) ?> · <?= e($item['item_code']) ?></div>
                </div>
                <button class="drawer-close" type="button" data-close-editor="editor-<?= e($item['id']) ?>" aria-label="关闭">×</button>
            </div>
            <?php if ($canUploadImage): ?>
                <?php foreach (['main' => $mainImageFormId, 'sku' => $skuImageFormId] as $imageKind => $imageFormId): ?>
                    <form id="<?= e($imageFormId) ?>" class="drawer-image-upload-form" method="post" action="/orders/images/upload" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                        <input type="hidden" name="order_id" value="<?= e($order['id']) ?>">
                        <input type="hidden" name="item_id" value="<?= e($item['id']) ?>">
                        <input type="hidden" name="kind" value="<?= e($imageKind) ?>">
                        <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
            <form class="drawer-body" method="post" action="/orders/item/save">
                <?= csrf_field() ?>
                <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                <input type="hidden" name="order_id" value="<?= e($order['id']) ?>">
                <input type="hidden" name="item_id" value="<?= e($item['id']) ?>">
                <input type="hidden" name="return" value="<?= e($returnUrl) ?>">

                <div class="drawer-section">
                    <div class="drawer-section-title">编辑订单</div>
                    <div class="drawer-form-group"><label>OrderId：</label><input type="text" value="<?= e($order['platform_order_id']) ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>Id：</label><input type="text" value="<?= e($order['id']) ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>LineId：</label><input type="text" value="<?= e($item['line_id'] ?? '') ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>ItemId：</label><input type="text" value="<?= e($item['item_code'] ?? '') ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>Quantity：</label><input type="text" value="<?= e($item['quantity'] ?? '') ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>SubCodeOption：</label><input type="text" value="<?= e($item['option'] ?? '') ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>OrderTime：</label><input type="text" value="<?= e($order['order_date'] ?? '') ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>OrderStatus：</label><input type="text" value="<?= e($orderStatus) ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>EntryPoint：</label><input type="text" value="<?= e($entryPoint) ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>ShipName：</label><input type="text" value="<?= e($shipName) ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>ShipAddress1：</label><input type="text" value="<?= e($shipAddress1) ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>ShipAddress2：</label><input type="text" value="<?= e($shipAddress2) ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>ShipCity：</label><input type="text" value="<?= e($shipCity) ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>ShipPrefecture：</label><input type="text" value="<?= e($shipPrefecture) ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>ShipZipCode：</label><input type="text" value="<?= e($shipZipCode) ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>ShipPhoneNumber：</label><input type="text" value="<?= e($shipPhoneNumber) ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>ShipRequestDate：</label><input type="text" value="<?= e($extraValue($orderExtra, ['ShipRequestDate', 'deliveryRequest2'])) ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>ShipRequestTime：</label><input type="text" value="<?= e($extraValue($orderExtra, ['ShipRequestTime'])) ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>ShipNotes：</label><input type="text" value="<?= e($extraValue($itemExtra, ['ShipNotes', 'deliveryRequest1'])) ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>BillMailAddress：</label><input type="text" value="<?= e($customer['mail'] ?? '') ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>PayMethodName：</label><input type="text" value="<?= e($payMethod) ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>产品单价：</label><input type="text" value="<?= e((string) ($item['unit_price'] ?? '')) ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>产品邮费：</label><input type="text" value="<?= e((string) ($item['postage_price'] ?? '')) ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>手续费：</label><input type="text" value="<?= e((string) ($item['pay_charge'] ?? '')) ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>TotalPrice：</label><input type="text" value="<?= e((string) ($item['line_total'] ?? '')) ?>" readonly class="readonly"></div>
                </div>

                <?php if ($canEditOrders || $canEditPurchase): ?>
                <div class="drawer-section">
                    <div class="drawer-section-title">运单信息</div>
                    <div class="drawer-form-group">
                        <label>订单产品图：</label>
                        <div class="image-upload-area">
                            <?php if ($mainImage !== ''): ?>
                                <img class="preview-image" src="<?= e($mainImage) ?>" alt="<?= e($item['title']) ?>">
                            <?php else: ?>
                                <div class="drawer-image-empty">暂无图片</div>
                            <?php endif; ?>
                            <?php if ($canUploadImage): ?>
                                <div class="drawer-image-controls">
                                    <input form="<?= e($mainImageFormId) ?>" type="file" name="image" accept="image/*">
                                    <textarea form="<?= e($mainImageFormId) ?>" name="base64_image" rows="2" placeholder="也可粘贴 base64 图片数据"></textarea>
                                    <button form="<?= e($mainImageFormId) ?>" class="btn btn-xs" type="submit">上传订单产品图</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="drawer-form-group">
                        <label>SKU产品图：</label>
                        <div class="image-upload-area">
                            <?php if ($skuImage !== ''): ?>
                                <img class="preview-image" src="<?= e($skuImage) ?>" alt="SKU产品图">
                                <div class="drawer-image-path"><?= e($skuImage) ?></div>
                            <?php else: ?>
                                <div class="drawer-image-empty">暂无图片</div>
                            <?php endif; ?>
                            <?php if ($canUploadImage): ?>
                                <div class="drawer-image-controls">
                                    <input form="<?= e($skuImageFormId) ?>" type="file" name="image" accept="image/*">
                                    <textarea form="<?= e($skuImageFormId) ?>" name="base64_image" rows="2" placeholder="也可粘贴 base64 图片数据"></textarea>
                                    <button form="<?= e($skuImageFormId) ?>" class="btn btn-xs" type="submit">上传SKU产品图</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="drawer-form-group"><label>1688订单号：</label><input type="text" name="tabaono" value="<?= e($item['tabaono'] ?? '') ?>"></div>
                    <div class="drawer-form-group"><label>历史1688单号：</label><input type="text" name="caigou_ordernums" value="<?= e($item['caigou_ordernums'] ?? '') ?>"></div>
                    <div class="drawer-form-group"><label>采购人：</label><input type="text" name="buyer" value="<?= e($item['buyer'] ?? '') ?>"></div>
                    <div class="drawer-form-group"><label>采购链接：</label><textarea name="purchase_link" rows="2"><?= e($item['purchase_link'] ?? '') ?></textarea></div>
                    <div class="drawer-form-group"><label>物流公司：</label><input type="text" name="ship_company" value="<?= e($item['ship_company'] ?? '') ?>"></div>
                    <div class="drawer-form-group"><label>国内运单号：</label><input type="text" name="ship_number" value="<?= e($item['ship_number'] ?? '') ?>"></div>
                    <div class="drawer-form-group"><label>物流状态：</label><input type="text" name="logistics" value="<?= e($item['logistics'] ?? '') ?>" readonly class="readonly"></div>
                    <div class="drawer-form-group"><label>物流轨迹：</label><textarea name="logistic_trace" rows="3" readonly class="readonly"><?= e($item['logistic_trace'] ?? '') ?></textarea></div>
                    <div class="drawer-form-group"><label>物流签收地：</label><select name="receipt_city">
                        <option value="">---请选择---</option>
                        <?php foreach ($receiptCityChoices as $city): ?>
                            <option value="<?= e($city) ?>" <?= $currentReceiptCity === $city ? 'selected' : '' ?>><?= e($city) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                    <div class="drawer-form-group"><label>comamount：</label><input type="text" name="com_amount" value="<?= e($item['com_amount'] ?? '') ?>"></div>
                    <div class="drawer-form-group"><label>件数：</label><input type="text" name="ship_quantity" value="<?= e($item['ship_quantity'] ?? '') ?>"></div>
                    <div class="drawer-form-group"><label>重量：</label><input type="text" name="weight" value="<?= e($item['weight'] ?? '') ?>"></div>
                    <div class="drawer-form-group"><label class="label-red">材质：</label><input type="text" name="material" value="<?= e($item['material'] ?? '') ?>"></div>
                    <div class="drawer-form-group"><label class="label-red">托运备注：</label><input type="text" name="tranship_comment" value="<?= e($item['tranship_comment'] ?? '') ?>"></div>
                    <div class="drawer-form-group">
                        <label class="label-red">中文属性备注：</label>
                        <div class="drawer-field-stack">
                            <textarea name="chinese_option" rows="2"><?= e($item['chinese_option'] ?? '') ?></textarea>
                            <div class="drawer-readonly-note">
                                <div><strong>商品数量：</strong><?= e($item['quantity'] ?? '-') ?></div>
                                <div><strong>日语商品属性：</strong><?= e($item['option'] ?? '-') ?></div>
                                <div><strong>項目・選択肢：</strong><?= e($extraValue($itemExtra, ['selectedChoice', 'SubCodeOption']) ?: '-') ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="drawer-form-group"><label>采购金额：</label><input type="text" name="amount" value="<?= e($item['amount'] ?? '') ?>"></div>
                    <div class="drawer-form-group"><label>cnamount：</label><input type="text" name="cn_amount" value="<?= e($item['cn_amount'] ?? '') ?>"></div>
                    <div class="drawer-form-group"><label>采购状态：</label><select name="purchase_status" data-source-status-target data-status-options="<?= e($purchaseStatusOptionsJson) ?>">
                        <?php foreach ($statusOptionsFor($item['purchase_status'] ?? '', $drawerStatusSource) as $statusOption): ?>
                            <option <?= ($item['purchase_status'] ?? '') === $statusOption ? 'selected' : '' ?>><?= e($statusOption) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                    <div class="drawer-form-group"><label>采购时间：</label><input type="text" name="purchase_time" value="<?= e($item['purchase_time'] ?? '') ?>" placeholder="YYYY-MM-DD HH:MM"></div>
                    <div class="drawer-form-group"><label>补货链接：</label><textarea name="buhuo_link" rows="2"><?= e($item['buhuo_link'] ?? '') ?></textarea></div>
                    <div class="drawer-form-group"><label>订单备注：</label><textarea name="comment" rows="3"><?= e($item['comment'] ?? '') ?></textarea></div>
                </div>
                <?php endif; ?>
                <?php if ($canEditOrders || $canEditJp): ?>
                <div class="drawer-section">
                    <div class="drawer-section-title">日本仓 / 国际物流</div>
                    <div class="drawer-form-group"><label>日本仓ID：</label><input type="text" name="jp_warehouse_id" value="<?= e($item['jp_warehouse_id'] ?? '') ?>"></div>
                    <div class="drawer-form-group"><label>发货员：</label><input type="text" name="assignee" value="<?= e($item['assignee'] ?? '') ?>"></div>
                    <div class="drawer-form-group"><label>出库状态：</label><select name="out_status">
                        <?php foreach (['待分配', '已分配', '已出库', '已发货'] as $outOption): ?>
                            <option <?= ($item['out_status'] ?? '') === $outOption ? 'selected' : '' ?>><?= e($outOption) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                    <div class="drawer-form-group"><label>国际运单号：</label><input type="text" name="intl_number" value="<?= e($item['intl_number'] ?? '') ?>"></div>
                    <div class="drawer-form-group"><label>国际运费：</label><input type="text" name="intl_fee" value="<?= e(($item['intl_fee'] ?? 0) ?: ($item['com_amount'] ?? '')) ?>"></div>
                    <div class="drawer-form-group"><label>件数：</label><input type="text" name="intl_qty" value="<?= e(($item['intl_qty'] ?? 0) ?: ($item['ship_quantity'] ?? '')) ?>"></div>
                    <div class="drawer-form-group"><label>国际重量：</label><input type="text" name="intl_weight" value="<?= e(($item['intl_weight'] ?? 0) ?: ($item['weight'] ?? '')) ?>"></div>
                    <div class="drawer-form-group"><label>国际备注：</label><input type="text" name="intl_comment" value="<?= e($item['intl_comment'] ?? '') ?>"></div>
                </div>
                <?php endif; ?>
                <div class="drawer-actions">
                    <button class="btn" type="button" data-close-editor="editor-<?= e($item['id']) ?>">取消</button>
                    <a class="btn" href="<?= e($detailUrl) ?>">完整详情</a>
                    <button class="btn primary" type="submit">保存</button>
                </div>
            </form>
        </aside>
    <?php endforeach; ?>
    <?php endif; ?>
</article>
