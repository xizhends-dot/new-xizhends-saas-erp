<?php
$customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
$items = array_values(array_filter((array) ($order['items'] ?? []), 'is_array'));
$attachments = is_array($attachments ?? null) ? $attachments : [];
?>
<section class="ajax-detail">
    <div class="detail-grid">
        <div>
            <h3><?= e($order['platform_order_id'] ?? '') ?></h3>
            <p class="sub"><?= e($order['store'] ?? '') ?> · <?= e($order['order_date'] ?? '') ?></p>
        </div>
        <div>
            <strong><?= e($customer['name'] ?? '') ?></strong>
            <p class="sub"><?= e($customer['phone'] ?? '') ?> · <?= e($customer['zip'] ?? '') ?></p>
            <p><?= e($customer['address'] ?? '') ?></p>
        </div>
    </div>

    <table class="table">
        <thead>
        <tr><th>商品</th><th>数量</th><th>采购</th><th>国内物流</th><th>国际物流</th><th>备注</th></tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td>
                    <strong><?= e(($item['item_code'] ?? '') ?: ($item['lot_number'] ?? '')) ?></strong>
                    <div class="sub"><?= e($item['title'] ?? '') ?></div>
                </td>
                <td><?= e($item['quantity'] ?? 1) ?></td>
                <td><?= e($item['purchase_status'] ?? '') ?><div class="sub"><?= e($item['buyer'] ?? '') ?></div></td>
                <td><?= e(trim((string) (($item['ship_company'] ?? '') . ' ' . ($item['ship_number'] ?? '')))) ?><div class="sub"><?= e($item['logistics'] ?? '') ?></div></td>
                <td><?= e($item['intl_number'] ?? '') ?><div class="sub"><?= e($item['intl_status'] ?? '') ?></div></td>
                <td><?= e(trim((string) (($item['comment'] ?? '') . ' ' . ($item['tranship_comment'] ?? '')))) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?>
            <tr><td colspan="6" class="sub">没有商品明细。</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($attachments): ?>
        <div class="attachment-strip">
            <?php foreach ($attachments as $attachment): ?>
                <a href="/<?= e($attachment['path'] ?? '') ?>" target="_blank" rel="noopener noreferrer"><?= e($attachment['title'] ?? '附件') ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
