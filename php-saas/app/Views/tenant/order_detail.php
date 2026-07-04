<?php
$platformName = $platformNames[$order['platform'] ?? ''] ?? ($order['platform'] ?? '');
$returnUrl = $returnUrl ?? "/orders?tenant={$tenantKey}";
$statusOptions = array_values(array_filter(array_map('strval', is_array($statusOptions ?? null) ? $statusOptions : [])));
$statusOptionsFor = static function (mixed $current) use ($statusOptions): array {
    $current = trim((string) $current);
    if ($current !== '' && !in_array($current, $statusOptions, true)) {
        return array_merge([$current], $statusOptions);
    }

    return $statusOptions;
};
$sourceOptions = ['cn_purchase' => '国内采购', 'jp_stock' => '日本仓', 'pending' => '待定'];
$outOptions = ['待分配', '已分配', '已出库', '已发货'];
$canEditOrders = (bool) ($canEditOrders ?? false);
$canEditPurchase = (bool) ($canEditPurchase ?? false);
$canEditJp = (bool) ($canEditJp ?? false);
$canChangeSource = (bool) ($canChangeSource ?? false);
$canUploadImage = (bool) ($canUploadImage ?? false);
$canDeleteImage = (bool) ($canDeleteImage ?? false);
$canEditAnyItemField = $canEditOrders || $canEditPurchase || $canEditJp || $canChangeSource;
?>
<div class="page-head">
    <div>
        <h1>订单详情 <span class="sub"><?= e($platformName) ?> · <?= e($order['platform_order_id'] ?? '') ?></span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="<?= e($returnUrl) ?>">返回列表</a>
        <?php if ($canEditAnyItemField): ?><button class="btn primary" type="submit" form="item-save-<?= e((int) (($order['items'][0]['id'] ?? 0))) ?>">保存首个明细</button><?php endif; ?>
    </div>
</div>

<section class="panel detail-section">
    <div class="panel-head"><span>收件信息</span><span class="sub">平台原始订单资料</span></div>
    <div class="panel-body detail-grid">
        <div><span class="detail-lb">平台订单号</span><strong><?= e($order['platform_order_id'] ?? '') ?></strong></div>
        <div><span class="detail-lb">店铺</span><strong><?= e($order['store'] ?? '') ?></strong></div>
        <div><span class="detail-lb">下单时间</span><strong><?= e($order['order_date'] ?? '') ?></strong></div>
        <div><span class="detail-lb">订单状态</span><strong><?= e($order['status'] ?? '') ?></strong></div>
        <div><span class="detail-lb">收件人</span><strong><?= e($order['customer']['name'] ?? '') ?></strong></div>
        <div><span class="detail-lb">电话</span><strong><?= e($order['customer']['phone'] ?? '') ?></strong></div>
        <div><span class="detail-lb">邮编</span><strong><?= e($order['customer']['zip'] ?? '') ?></strong></div>
        <div><span class="detail-lb">邮箱</span><strong><?= e($order['customer']['mail'] ?? '') ?></strong></div>
        <div class="detail-wide"><span class="detail-lb">地址</span><strong><?= e($order['customer']['address'] ?? '') ?></strong></div>
    </div>
</section>

<section class="panel detail-section">
    <div class="panel-head"><span>订单附件 / 图片</span><span class="sub">承接旧 ph_img、上传图片、采购凭证与客服截图</span></div>
    <div class="panel-body">
        <?php if ($canUploadImage): ?>
        <form class="form-grid attachment-form" method="post" action="/orders/attachments/add">
                <?= csrf_field() ?>
            <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
            <input type="hidden" name="order_id" value="<?= e($order['id']) ?>">
            <label><span>类型</span><select name="type">
                <?php foreach (['订单图片', '采购凭证', '客服截图', '日本仓发货照片', '附件'] as $type): ?>
                    <option><?= e($type) ?></option>
                <?php endforeach; ?>
            </select></label>
            <label><span>关联子商品</span><select name="order_item_id">
                <option value="0">整单附件</option>
                <?php foreach ($order['items'] ?? [] as $item): ?>
                    <option value="<?= e($item['id']) ?>"><?= e(($item['item_code'] ?? '') . ' ' . ($item['title'] ?? '')) ?></option>
                <?php endforeach; ?>
            </select></label>
            <label><span>标题</span><input name="title" placeholder="如 售后沟通截图 / 采购凭证"></label>
            <label><span>上传人</span><input name="uploaded_by" value="租户管理员"></label>
            <label class="wide"><span>图片/附件路径</span><input name="path" placeholder="storage/tenants/<?= e($tenantKey) ?>/images/uploads/<?= e($order['id']) ?>/xxx.jpg"></label>
            <label><span>来源</span><input name="source" value="手工登记"></label>
            <label><span>大小</span><input name="size" placeholder="如 320KB"></label>
            <div class="form-submit"><button class="btn primary" type="submit">登记附件</button></div>
        </form>

        <form class="form-grid attachment-form" method="post" action="/orders/images/upload" enctype="multipart/form-data">
                <?= csrf_field() ?>
            <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
            <input type="hidden" name="order_id" value="<?= e($order['id']) ?>">
            <input type="hidden" name="kind" value="attachment">
            <label><span>附件类型</span><select name="type">
                <?php foreach (['订单图片', '采购凭证', '客服截图', '日本仓发货照片', '附件'] as $type): ?>
                    <option><?= e($type) ?></option>
                <?php endforeach; ?>
            </select></label>
            <label><span>关联子商品</span><select name="item_id">
                <option value="0">整单附件</option>
                <?php foreach ($order['items'] ?? [] as $item): ?>
                    <option value="<?= e($item['id']) ?>"><?= e(($item['item_code'] ?? '') . ' ' . ($item['title'] ?? '')) ?></option>
                <?php endforeach; ?>
            </select></label>
            <label><span>标题</span><input name="title" placeholder="如 采购凭证 / 客服截图"></label>
            <label><span>上传人</span><input name="uploaded_by" value="租户管理员"></label>
            <label class="wide"><span>选择图片</span><input type="file" name="image" accept="image/*"></label>
            <label class="wide"><span>粘贴图片</span><textarea name="base64_image" placeholder="粘贴 base64 图片数据"></textarea></label>
            <div class="form-submit"><button class="btn primary" type="submit">上传附件</button></div>
        </form>
        <?php else: ?>
            <div class="setting-muted">当前账号没有图片上传权限，只能查看已有附件。</div>
        <?php endif; ?>

        <table class="table attachment-table">
            <thead><tr><th>类型</th><th>标题</th><th>路径</th><th>来源</th><th>上传人</th><th>时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach (($attachments ?? []) as $attachment): ?>
                <tr>
                    <td><span class="tag blue"><?= e($attachment['type'] ?? '') ?></span></td>
                    <td><?= e($attachment['title'] ?? '') ?></td>
                    <td><code><?= e($attachment['path'] ?? '') ?></code></td>
                    <td><?= e($attachment['source'] ?? '') ?></td>
                    <td><?= e($attachment['uploaded_by'] ?? '') ?></td>
                    <td><?= e($attachment['created_at'] ?? '') ?></td>
                    <td>
                        <?php if ($canDeleteImage): ?>
                        <form class="mini-form" method="post" action="/orders/attachments/delete">
                <?= csrf_field() ?>
                            <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                            <input type="hidden" name="order_id" value="<?= e($order['id']) ?>">
                            <input type="hidden" name="attachment_id" value="<?= e($attachment['id']) ?>">
                            <button class="btn danger" type="submit">删除</button>
                        </form>
                        <?php else: ?>
                            <span class="sub">无删除权限</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($attachments)): ?>
                <tr><td colspan="7" class="sub">暂无附件。</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php foreach ($order['items'] ?? [] as $index => $item): ?>
    <?php $formId = 'item-save-' . (int) $item['id']; ?>
    <section class="panel detail-section">
        <div class="panel-head">
            <span>子商品 #<?= e($index + 1) ?> · <?= e($item['item_code'] ?? '') ?></span>
            <span class="sub"><?= e($item['title'] ?? '') ?></span>
        </div>
        <div class="panel-body">
            <?php if ($canEditAnyItemField): ?>
            <form id="<?= e($formId) ?>" class="detail-form" method="post" action="/orders/item/save">
                <?= csrf_field() ?>
                <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                <input type="hidden" name="order_id" value="<?= e($order['id']) ?>">
                <input type="hidden" name="item_id" value="<?= e($item['id']) ?>">
                <input type="hidden" name="return" value="/orders/detail?tenant=<?= e($tenantKey) ?>&id=<?= e($order['id']) ?>&return=<?= e(urlencode($returnUrl)) ?>">

                <div class="detail-item-head">
                    <img class="detail-img" src="<?= e($item['image'] ?? '') ?>" alt="<?= e($item['title'] ?? '') ?>">
                    <div>
                        <div class="detail-title"><?= e($item['title'] ?? '') ?></div>
                        <div class="sub"><?= e($item['option'] ?? '') ?> · 数量 ×<?= e($item['quantity'] ?? 0) ?></div>
                    </div>
                </div>

                <?php if ($canUploadImage): ?>
                <div class="image-tools">
                    <?php foreach (['main' => '主图', 'sku' => 'SKU图'] as $kind => $label): ?>
                        <form class="image-upload-form" method="post" action="/orders/images/upload" enctype="multipart/form-data">
                <?= csrf_field() ?>
                            <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                            <input type="hidden" name="order_id" value="<?= e($order['id']) ?>">
                            <input type="hidden" name="item_id" value="<?= e($item['id']) ?>">
                            <input type="hidden" name="kind" value="<?= e($kind) ?>">
                            <div class="detail-lb"><?= e($label) ?>替换</div>
                            <input type="file" name="image" accept="image/*">
                            <textarea name="base64_image" placeholder="也可粘贴 base64 图片数据"></textarea>
                            <button class="btn" type="submit">上传<?= e($label) ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="detail-form-grid">
                    <?php if ($canEditOrders || $canChangeSource): ?>
                        <label><span>货源地</span><select name="source_type">
                            <?php foreach ($sourceOptions as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= ($item['source_type'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select></label>
                    <?php endif; ?>
                    <?php if ($canEditOrders || $canEditPurchase): ?>
                    <label><span>采购状态</span><select name="purchase_status">
                        <?php foreach ($statusOptionsFor($item['purchase_status'] ?? '') as $status): ?>
                            <option <?= ($item['purchase_status'] ?? '') === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label><span>采购人</span><input name="buyer" value="<?= e($item['buyer'] ?? '') ?>"></label>
                    <label><span>采购时间</span><input name="purchase_time" value="<?= e($item['purchase_time'] ?? '') ?>" placeholder="YYYY-MM-DD HH:MM"></label>
                    <label><span>1688订单号</span><input name="tabaono" value="<?= e($item['tabaono'] ?? '') ?>"></label>
                    <label class="detail-wide"><span>历史1688单号</span><input name="caigou_ordernums" value="<?= e($item['caigou_ordernums'] ?? '') ?>"></label>
                    <label class="detail-wide"><span>采购链接</span><textarea name="purchase_link"><?= e($item['purchase_link'] ?? '') ?></textarea></label>
                    <label class="detail-wide"><span>补货链接</span><textarea name="buhuo_link"><?= e($item['buhuo_link'] ?? '') ?></textarea></label>
                    <label><span>采购金额</span><input name="amount" value="<?= e($item['amount'] ?? '') ?>"></label>
                    <label><span>cnamount</span><input name="cn_amount" value="<?= e($item['cn_amount'] ?? '') ?>"></label>
                    <label><span>comamount</span><input name="com_amount" value="<?= e($item['com_amount'] ?? '') ?>"></label>
                    <label><span>材质</span><input name="material" value="<?= e($item['material'] ?? '') ?>"></label>
                    <label><span>重量</span><input name="weight" value="<?= e($item['weight'] ?? '') ?>"></label>
                    <label class="detail-wide"><span>托运备注</span><input name="tranship_comment" value="<?= e($item['tranship_comment'] ?? '') ?>"></label>
                    <label class="detail-wide"><span>中文属性备注</span><textarea name="chinese_option"><?= e($item['chinese_option'] ?? '') ?></textarea></label>
                    <label class="detail-wide"><span>订单备注</span><textarea name="comment"><?= e($item['comment'] ?? '') ?></textarea></label>
                    <label><span>物流公司</span><input name="ship_company" value="<?= e($item['ship_company'] ?? '') ?>"></label>
                    <label><span>国内运单号</span><input name="ship_number" value="<?= e($item['ship_number'] ?? '') ?>"></label>
                    <label><span>物流状态</span><input name="logistics" value="<?= e($item['logistics'] ?? '') ?>"></label>
                    <label class="detail-wide"><span>物流轨迹</span><textarea name="logistic_trace"><?= e($item['logistic_trace'] ?? '') ?></textarea></label>
                    <?php endif; ?>
                    <?php if ($canEditOrders || $canEditJp): ?>
                    <label><span>日本仓ID</span><input name="jp_warehouse_id" value="<?= e($item['jp_warehouse_id'] ?? '') ?>"></label>
                    <label><span>发货员</span><input name="assignee" value="<?= e($item['assignee'] ?? '') ?>"></label>
                    <label><span>出库状态</span><select name="out_status">
                        <?php foreach ($outOptions as $status): ?>
                            <option <?= ($item['out_status'] ?? '') === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label><span>国际运单号</span><input name="intl_number" value="<?= e($item['intl_number'] ?? '') ?>"></label>
                    <label><span>国际运费</span><input name="intl_fee" value="<?= e(($item['intl_fee'] ?? 0) ?: ($item['com_amount'] ?? '')) ?>"></label>
                    <label><span>件数</span><input name="intl_qty" value="<?= e(($item['intl_qty'] ?? 0) ?: ($item['ship_quantity'] ?? '')) ?>"></label>
                    <label><span>国际重量</span><input name="intl_weight" value="<?= e(($item['intl_weight'] ?? 0) ?: ($item['weight'] ?? '')) ?>"></label>
                    <label class="detail-wide"><span>国际备注</span><input name="intl_comment" value="<?= e($item['intl_comment'] ?? '') ?>"></label>
                    <?php endif; ?>
                </div>
                <div class="detail-actions">
                    <button class="btn primary" type="submit">保存明细</button>
                </div>
            </form>
            <?php else: ?>
                <div class="detail-item-head">
                    <img class="detail-img" src="<?= e($item['image'] ?? '') ?>" alt="<?= e($item['title'] ?? '') ?>">
                    <div>
                        <div class="detail-title"><?= e($item['title'] ?? '') ?></div>
                        <div class="sub"><?= e($item['option'] ?? '') ?> · 数量 ×<?= e($item['quantity'] ?? 0) ?> · 当前账号没有订单编辑权限</div>
                    </div>
                </div>
                <div class="detail-grid">
                    <div><span class="detail-lb">采购状态</span><strong><?= e($item['purchase_status'] ?? '') ?></strong></div>
                    <div><span class="detail-lb">采购人</span><strong><?= e($item['buyer'] ?? '') ?></strong></div>
                    <div><span class="detail-lb">1688订单号</span><strong><?= e($item['tabaono'] ?? '') ?></strong></div>
                    <div><span class="detail-lb">材质</span><strong><?= e($item['material'] ?? '') ?></strong></div>
                    <div><span class="detail-lb">重量</span><strong><?= e(($item['intl_weight'] ?? 0) ?: ($item['weight'] ?? '')) ?></strong></div>
                    <div><span class="detail-lb">国际运费</span><strong><?= e(($item['intl_fee'] ?? 0) ?: ($item['com_amount'] ?? '')) ?></strong></div>
                    <div class="detail-wide"><span class="detail-lb">采购链接</span><strong><?= e($item['purchase_link'] ?? '') ?></strong></div>
                    <div class="detail-wide"><span class="detail-lb">托运备注</span><strong><?= e($item['tranship_comment'] ?? '') ?></strong></div>
                </div>
            <?php endif; ?>

            <?php $statusLogs = array_values(array_filter($item['logs'] ?? [], fn (array $log): bool => ($log['field'] ?? '') === 'purchase_status')); ?>
            <div class="detail-log">
                <div class="detail-log-title">状态流转历史</div>
                <table class="log-table">
                    <thead><tr><th>时间</th><th>操作人</th><th>来源</th><th>旧状态</th><th>新状态</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($statusLogs as $log): ?>
                        <tr>
                            <td><?= e($log['time'] ?? '') ?></td>
                            <td><?= e($log['user'] ?? '') ?></td>
                            <td><?= e($log['action'] ?? '') ?></td>
                            <td><span class="log-old"><?= e($log['old'] ?? '') ?></span></td>
                            <td><span class="log-new"><?= e($log['new'] ?? '') ?></span></td>
                            <td><?= e($log['ip'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$statusLogs): ?>
                        <tr><td colspan="6" class="sub">暂无状态流转记录。</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="detail-log">
                <div class="detail-log-title">最近日志</div>
                <table class="log-table">
                    <thead><tr><th>时间</th><th>操作人</th><th>操作</th><th>字段</th><th>详情</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach (($item['logs'] ?? []) as $log): ?>
                        <tr>
                            <td><?= e($log['time'] ?? '') ?></td>
                            <td><?= e($log['user'] ?? '') ?></td>
                            <td><?= e($log['action'] ?? '') ?></td>
                            <td><?= e($log['field'] ?? '') ?></td>
                            <td><span class="log-old"><?= e($log['old'] ?? '') ?></span><span class="log-arrow">→</span><span class="log-new"><?= e($log['new'] ?? '') ?></span></td>
                            <td><?= e($log['ip'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
<?php endforeach; ?>
