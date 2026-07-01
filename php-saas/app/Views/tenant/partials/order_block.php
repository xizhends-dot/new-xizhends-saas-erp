<?php
$logId = 'logs-' . (int) $order['id'];
$customer = $order['customer'] ?? [];
$seq = $seq ?? 1;
$showA = $orderView === 'platform';
$hiddenB2Class = $orderView === 'platform' ? ' table-hidden' : '';
$hiddenCClass = $orderView === 'platform' ? ' table-hidden' : ' table-hidden';
$isRakuten = ($order['platform'] ?? '') === 'r';
$importedAt = (string) (($order['imported_at'] ?? '') ?: ($order['order_date'] ?? ''));
$payMethod = (string) ($order['pay_method'] ?? '');
$shipMethod = (string) ($order['ship_method'] ?? '');
$batchFormId = $batchFormId ?? ('batch-' . $orderView);
$detailUrl = '/orders/detail?tenant=' . rawurlencode((string) $tenantKey) . '&id=' . rawurlencode((string) ($order['id'] ?? '')) . '&return=' . rawurlencode((string) $returnUrl);
$statusOptions = $statusOptions ?? [];
$canEditOrders = (bool) ($canEditOrders ?? false);
$canEditPurchase = (bool) ($canEditPurchase ?? false);
$canEditJp = (bool) ($canEditJp ?? false);
$canChangeSource = (bool) ($canChangeSource ?? false);
$canBatchOperate = (bool) ($canBatchOperate ?? false);
$canBatchPurchase = (bool) ($canBatchPurchase ?? false);
$canBatchJp = (bool) ($canBatchJp ?? false);
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
?>
<article class="order-block">
    <?php if ($showA): ?>
        <table class="otable sec-a">
            <colgroup><?php for ($i = 0; $i < 15; $i++): ?><col class="c<?= e($i) ?>"><?php endfor; ?></colgroup>
            <thead>
            <tr>
                <th class="c0"><span class="seq-no"><?= e($seq) ?></span></th>
                <th class="c1" colspan="2">导入时间</th>
                <th class="c3">收件人</th>
                <th class="c4">假名</th>
                <th class="c5">地址</th>
                <th class="c6">邮编</th>
                <th class="c7">电话</th>
                <th class="c8">邮箱</th>
                <th class="c9">支付方式</th>
                <th class="c10">运送方式</th>
                <th class="c11"><?= $isRakuten ? '已邀评' : '' ?></th>
                <th class="c12"><?= $isRakuten ? '已评价' : '' ?></th>
                <th class="c13"></th>
                <th class="c14"></th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td><?php if ($canBatchOperate): ?><input class="order-check" type="checkbox" name="order_ids[]" value="<?= e($order['id']) ?>" form="<?= e($batchFormId) ?>" aria-label="选择订单"><?php else: ?><span class="seq-no"><?= e($seq) ?></span><?php endif; ?></td>
                <td colspan="2"><?= e($importedAt) ?></td>
                <td><?= e($customer['name'] ?? '') ?></td>
                <td><?= e($customer['kana'] ?? '') ?></td>
                <td title="<?= e($customer['address'] ?? '') ?>"><?= e($customer['address'] ?? '') ?></td>
                <td><?= e($customer['zip'] ?? '') ?></td>
                <td><?= e($customer['phone'] ?? '') ?></td>
                <td><?= e($customer['mail'] ?? '') ?></td>
                <td><?= e($payMethod) ?></td>
                <td><?= e($shipMethod) ?></td>
                <td><?php if ($isRakuten): ?><input type="checkbox" aria-label="已邀评" <?= !empty($order['review_invited']) ? 'checked' : '' ?> disabled><?php endif; ?></td>
                <td><?php if ($isRakuten): ?><input type="checkbox" aria-label="已评价" <?= !empty($order['reviewed']) ? 'checked' : '' ?> disabled><?php endif; ?></td>
                <td></td>
                <td></td>
            </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <table class="otable sec-b<?= !$showA ? ' with-seq-col' : '' ?>">
        <colgroup><?php for ($i = 0; $i < 15; $i++): ?><col class="c<?= e($i) ?>"><?php endfor; ?></colgroup>
        <thead>
        <tr>
            <?php if ($showA): ?>
                <th class="c0">图片</th>
                <th class="c1" colspan="2">订单ID</th>
            <?php else: ?>
                <th class="c0"><span class="seq-no"><?= e($seq) ?></span></th>
                <th class="c1">图片</th>
                <th class="c2">订单ID</th>
            <?php endif; ?>
            <th class="c3">订单时间</th>
            <th class="c4">货源地 / 采购状态</th>
            <th class="c5">ItemId</th>
            <th class="c6">日本仓ID</th>
            <th class="c7">商品属性</th>
            <th class="c8" colspan="2">项目选择</th>
            <th class="c10">数量</th>
            <th class="c11">单价</th>
            <th class="c12">运费/手续费</th>
            <th class="c13">请求金额</th>
            <th class="c14">操作</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($order['items'] as $itemIndex => $item): ?>
            <?php
            $itemSource = $item['source_type'] ?? 'pending';
            $unitPrice = (float) ($item['unit_price'] ?? $item['amount'] ?? 0);
            $shippingFee = (float) ($item['postage_price'] ?? 0);
            $payFee = (float) ($item['pay_charge'] ?? 0);
            $lineTotal = (float) ($item['line_total'] ?? (($unitPrice * max(1, (int) ($item['quantity'] ?? 1))) + $shippingFee + $payFee));
            $itemMeta = array_values(array_filter([
                (string) ($item['lot_number'] ?? ''),
                (string) ($item['item_management_id'] ?? ''),
                (string) ($item['order_detail_id'] ?? ''),
            ], static fn (string $value): bool => trim($value) !== ''));
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
                <td<?php if ($showA): ?> colspan="2"<?php endif; ?>><a href="<?= e($detailUrl) ?>" class="oid-link"><?= e($order['platform_order_id']) ?></a><span class="oid-sub"><?= e($order['store']) ?></span></td>
                <td><?= e($order['order_date']) ?></td>
                <td class="source-status-cell">
                    <?php if ($orderView === 'platform' && $canChangeSource): ?>
                        <form class="source-form compact-source auto-submit-source" method="post" action="/orders/source">
                            <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                            <input type="hidden" name="item_id" value="<?= e($item['id']) ?>">
                            <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                            <select class="src-sel" name="source" aria-label="货源地">
                                <option value="cn_purchase" <?= $itemSource === 'cn_purchase' ? 'selected' : '' ?>>国内采购</option>
                                <option value="jp_stock" <?= $itemSource === 'jp_stock' ? 'selected' : '' ?>>日本仓</option>
                                <option value="pending" <?= $itemSource === 'pending' ? 'selected' : '' ?>>待定</option>
                            </select>
                        </form>
                    <?php else: ?>
                        <span class="src-tag <?= e($sourceClass($itemSource)) ?>"><?= e($sourceLabel($itemSource)) ?></span>
                    <?php endif; ?>
                    <span class="status <?= e($statusClass((string) ($item['purchase_status'] ?? ''))) ?>"><?= e($item['purchase_status']) ?></span>
                </td>
                <td><?= e($item['item_code']) ?><?php if ($itemMeta): ?><span class="oid-sub"><?= e(implode(' / ', $itemMeta)) ?></span><?php endif; ?></td>
                <td><?= e($item['jp_warehouse_id']) ?></td>
                <td><?= e($item['option']) ?></td>
                <td colspan="2" title="<?= e($item['title']) ?>"><?= e($item['title']) ?></td>
                <td><span class="qty-val">×<?= e($item['quantity']) ?></span></td>
                <td><span class="price-val">￥<?= e(number_format($unitPrice, 0)) ?></span></td>
                <td>￥<?= e(number_format($shippingFee, 0)) ?>/￥<?= e(number_format($payFee, 0)) ?></td>
                <td><span class="price-val">￥<?= e(number_format($lineTotal, 0)) ?></span></td>
                <td class="op-cell">
                    <?php if ($canEditThisView): ?><button class="log-btn edit-drawer-btn" type="button" data-open-editor="editor-<?= e($item['id']) ?>">编辑</button><?php endif; ?>
                    <button class="log-btn" type="button" data-toggle-logs="<?= e($logId) ?>">日志</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <table class="otable sec-b<?= e($hiddenB2Class) ?>">
        <colgroup><?php for ($i = 0; $i < 15; $i++): ?><col class="c<?= e($i) ?>"><?php endfor; ?></colgroup>
        <thead>
        <?php if ($orderView === 'jp'): ?>
            <tr><th class="c0" colspan="2">出库状态</th><th class="c2">发货员</th><th class="c3">出库时间</th><th class="c4" colspan="2">仓位</th><th class="c6">订单备注</th><th class="c7">出库成本</th><th class="c8" colspan="2">出库单号</th><th class="c10">物流公司</th><th class="c11" colspan="4">国内运单号</th></tr>
        <?php else: ?>
            <tr><th class="c0" colspan="2">采购状态</th><th class="c2">采购人</th><th class="c3">采购时间</th><th class="c4" colspan="2">采购链接</th><th class="c6">订单备注</th><th class="c7">采购金额</th><th class="c8" colspan="2">1688订单号</th><th class="c10">物流公司</th><th class="c11" colspan="4">国内运单号</th></tr>
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
                    <?php $purchaseLink = $safeHttpUrl($item['purchase_link'] ?? ''); ?>
                    <td colspan="2"><?= e($item['purchase_status']) ?></td>
                    <td><?= e($item['buyer'] ?: '-') ?></td>
                    <td><?= e($item['purchase_time'] ?: '-') ?></td>
                    <td colspan="2">
                        <?php if ($purchaseLink !== ''): ?>
                            <a class="accent-link" href="<?= e($purchaseLink) ?>" target="_blank" rel="noopener noreferrer">1688 商品页</a>
                        <?php else: ?>
                            <?= e(($item['purchase_link'] ?? '') !== '' ? '链接协议不允许' : '-') ?>
                        <?php endif; ?>
                    </td>
                    <td><?= e(($item['comment'] ?? '') ?: '-') ?></td>
                    <td>￥<?= e(number_format((float) ($item['purchase_amount'] ?? $item['amount'] ?? 0), 0)) ?></td>
                    <td colspan="2"><?= e($item['tabaono'] ?: '-') ?></td>
                    <td><?= e($item['ship_company'] ?: '-') ?></td>
                    <td colspan="4"><?= e($item['ship_number'] ?: '-') ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <table class="otable sec-c<?= e($hiddenCClass) ?>">
        <colgroup><?php for ($i = 0; $i < 15; $i++): ?><col class="c<?= e($i) ?>"><?php endfor; ?></colgroup>
        <thead><tr><th class="c0" colspan="4">国际运单号</th><th class="c4" colspan="2">运单状态</th><th class="c6">运费</th><th class="c7">件数</th><th class="c8">重量</th><th class="c9" colspan="6"></th></tr></thead>
        <tbody>
        <?php foreach ($order['items'] as $item): ?>
            <?php
            $intlNumber = (string) ($item['intl_number'] ?? '');
            $intlStatus = (string) (($item['intl_status'] ?? '') ?: '待发货');
            $intlQty = (int) (($item['intl_qty'] ?? 0) ?: ($item['ship_quantity'] ?? 0) ?: ($item['quantity'] ?? 0));
            ?>
            <tr class="item-row"><td colspan="4"><?= e($intlNumber !== '' ? $intlNumber : '待生成') ?></td><td colspan="2"><span class="intl-pend"><?= e($intlStatus) ?></span></td><td>￥<?= e(number_format((float) ($item['intl_fee'] ?? 0), 0)) ?></td><td><?= e($intlQty) ?></td><td><?= e((string) (($item['intl_weight'] ?? 0) ?: ($item['weight'] ?? '-'))) ?></td><td colspan="6"></td></tr>
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
        <aside class="editor-drawer" id="editor-<?= e($item['id']) ?>" aria-hidden="true">
            <div class="drawer-head">
                <div>
                    <strong>编辑子商品</strong>
                    <div class="sub"><?= e($order['platform_order_id']) ?> · <?= e($item['item_code']) ?></div>
                </div>
                <button class="drawer-close" type="button" data-close-editor="editor-<?= e($item['id']) ?>" aria-label="关闭">×</button>
            </div>
            <form class="drawer-body" method="post" action="/orders/item/save">
                <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                <input type="hidden" name="order_id" value="<?= e($order['id']) ?>">
                <input type="hidden" name="item_id" value="<?= e($item['id']) ?>">
                <input type="hidden" name="return" value="<?= e($returnUrl) ?>">

                <div class="drawer-product">
                    <img src="<?= e($item['image']) ?>" alt="<?= e($item['title']) ?>">
                    <div>
                        <div class="drawer-title"><?= e($item['title']) ?></div>
                        <div class="sub"><?= e($item['option']) ?> · ×<?= e($item['quantity']) ?></div>
                    </div>
                </div>

                <?php if ($canEditOrders || $canChangeSource): ?>
                    <label><span>货源地</span><select name="source_type">
                        <option value="cn_purchase" <?= ($item['source_type'] ?? '') === 'cn_purchase' ? 'selected' : '' ?>>国内采购</option>
                        <option value="jp_stock" <?= ($item['source_type'] ?? '') === 'jp_stock' ? 'selected' : '' ?>>日本仓</option>
                        <option value="pending" <?= ($item['source_type'] ?? '') === 'pending' ? 'selected' : '' ?>>待定</option>
                    </select></label>
                <?php endif; ?>
                <?php if ($canEditOrders || $canEditPurchase): ?>
                <label><span>采购状态</span><select name="purchase_status">
                    <?php foreach ($statusOptions as $statusOption): ?>
                        <option <?= ($item['purchase_status'] ?? '') === $statusOption ? 'selected' : '' ?>><?= e($statusOption) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label><span>采购人</span><input name="buyer" value="<?= e($item['buyer'] ?? '') ?>"></label>
                <label><span>采购时间</span><input name="purchase_time" value="<?= e($item['purchase_time'] ?? '') ?>"></label>
                <label><span>1688订单号</span><input name="tabaono" value="<?= e($item['tabaono'] ?? '') ?>"></label>
                <label><span>采购金额</span><input name="amount" value="<?= e($item['amount'] ?? '') ?>"></label>
                <label><span>采购链接</span><input name="purchase_link" value="<?= e($item['purchase_link'] ?? '') ?>"></label>
                <label><span>物流公司</span><input name="ship_company" value="<?= e($item['ship_company'] ?? '') ?>"></label>
                <label><span>国内运单号</span><input name="ship_number" value="<?= e($item['ship_number'] ?? '') ?>"></label>
                <?php endif; ?>
                <?php if ($canEditOrders || $canEditJp): ?>
                <label><span>日本仓ID</span><input name="jp_warehouse_id" value="<?= e($item['jp_warehouse_id'] ?? '') ?>"></label>
                <label><span>发货员</span><input name="assignee" value="<?= e($item['assignee'] ?? '') ?>"></label>
                <label><span>出库状态</span><select name="out_status">
                    <?php foreach (['待分配', '已分配', '已出库', '已发货'] as $outOption): ?>
                        <option <?= ($item['out_status'] ?? '') === $outOption ? 'selected' : '' ?>><?= e($outOption) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <?php endif; ?>
                <div class="drawer-actions">
                    <a class="btn" href="<?= e($detailUrl) ?>">完整详情</a>
                    <button class="btn primary" type="submit">保存</button>
                </div>
            </form>
        </aside>
    <?php endforeach; ?>
    <?php endif; ?>
</article>
