<?php
$byBuyer = [];
$bySupport = [];
foreach ($assignments as $assignment) {
    $byBuyer[(int) ($assignment['buyer_user_id'] ?? 0)][] = (int) ($assignment['support_user_id'] ?? 0);
    $bySupport[(int) ($assignment['support_user_id'] ?? 0)][] = (int) ($assignment['buyer_user_id'] ?? 0);
}
?>

<div class="page-head">
    <div>
        <h1>店铺分配 <span class="sub">采购与客服店铺关系</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/users?tenant=<?= e($tenantKey) ?>">返回员工</a>
    </div>
</div>

<div class="notice">这里承接旧系统 `ph_userlevel`。采购被分配到客服店铺后，后续订单查询、采购业绩统计、日本仓发货处理都应按这个关系过滤；当前开发模式默认全权限，但关系数据仍先完整建立。</div>

<div class="grid two-col">
    <div class="panel">
        <div class="panel-head"><span>按采购分配客服</span><span class="sub"><?= e(count($buyers)) ?> 人</span></div>
        <div class="panel-body assignment-list">
            <?php foreach ($buyers as $buyer): ?>
                <form class="assign-card" method="post" action="/assignments/save">
                <?= csrf_field() ?>
                    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                    <input type="hidden" name="mode" value="buyer">
                    <input type="hidden" name="buyer_user_id" value="<?= e($buyer['id']) ?>">
                    <div class="assign-title">
                        <strong><?= e($buyer['name'] ?? $buyer['username']) ?></strong>
                        <span class="tag blue"><?= e($buyer['role']) ?></span>
                    </div>
                    <div class="perm-grid">
                        <?php foreach ($supports as $support): ?>
                            <?php $checked = in_array((int) $support['id'], $byBuyer[(int) $buyer['id']] ?? [], true); ?>
                            <label class="perm-check"><input type="checkbox" name="support_user_ids[]" value="<?= e($support['id']) ?>" <?= $checked ? 'checked' : '' ?>><?= e($support['name'] ?? $support['username']) ?></label>
                        <?php endforeach; ?>
                    </div>
                    <div class="assign-actions"><button class="btn primary" type="submit">保存分配</button></div>
                </form>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head"><span>按客服店铺分配采购</span><span class="sub"><?= e(count($supports)) ?> 人</span></div>
        <div class="panel-body assignment-list">
            <?php foreach ($supports as $support): ?>
                <form class="assign-card" method="post" action="/assignments/save">
                <?= csrf_field() ?>
                    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                    <input type="hidden" name="mode" value="support">
                    <input type="hidden" name="support_user_id" value="<?= e($support['id']) ?>">
                    <div class="assign-title">
                        <strong><?= e($support['name'] ?? $support['username']) ?></strong>
                        <span class="sub"><?= e(implode('、', $support['stores'] ?? [])) ?></span>
                    </div>
                    <div class="perm-grid">
                        <?php foreach ($buyers as $buyer): ?>
                            <?php $checked = in_array((int) $buyer['id'], $bySupport[(int) $support['id']] ?? [], true); ?>
                            <label class="perm-check"><input type="checkbox" name="buyer_user_ids[]" value="<?= e($buyer['id']) ?>" <?= $checked ? 'checked' : '' ?>><?= e($buyer['name'] ?? $buyer['username']) ?> / <?= e($buyer['role']) ?></label>
                        <?php endforeach; ?>
                    </div>
                    <div class="assign-actions"><button class="btn primary" type="submit">保存分配</button></div>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="panel detail-section">
    <div class="panel-head"><span>当前分配记录</span><span class="sub"><?= e(count($assignments)) ?> 条</span></div>
    <div class="panel-body">
        <table class="table">
            <thead><tr><th>采购</th><th>客服</th><th>客服店铺范围</th><th>创建时间</th></tr></thead>
            <tbody>
            <?php foreach ($assignments as $assignment): ?>
                <tr>
                    <td><?= e($assignment['buyer_name'] ?? '') ?> <span class="tag gray"><?= e($assignment['buyer_role'] ?? '') ?></span></td>
                    <td><?= e($assignment['support_name'] ?? '') ?></td>
                    <td><?= e(implode('、', $assignment['support_stores'] ?? [])) ?></td>
                    <td><?= e($assignment['created_at'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
