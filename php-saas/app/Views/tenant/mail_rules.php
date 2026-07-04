<?php
$accounts = $mail['accounts'] ?? [];
$folders = $mail['folders'] ?? [];
$rules = $mail['rules'] ?? [];
$selectedRuleId = (int) ($_GET['rule_id'] ?? 0);
$selectedRule = null;
foreach ($rules as $rule) {
    if ((int) ($rule['id'] ?? 0) === $selectedRuleId) {
        $selectedRule = $rule;
        break;
    }
}
$accountLabel = function (array $account): string {
    $label = trim((string) (($account['shop_name'] ?? '') ?: ($account['shop_dpqz'] ?? '')));
    return $label !== '' ? $label : (string) ($account['imap_user'] ?? ('账号#' . (int) ($account['id'] ?? 0)));
};
$folderLabel = fn (array $folder): string => (string) (($folder['display_name'] ?? '') ?: ($folder['imap_path'] ?? ''));
$rulesUrl = function (array $params = []) use ($tenantKey): string {
    return '/mail/rules?' . http_build_query(array_merge(['tenant' => $tenantKey], $params));
};
$mailTab = 'rules';
?>

<div class="page-head">
    <div>
        <h1>过滤规则 <span class="sub">自动分类 / 移动文件夹 / 标记状态</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn primary" href="<?= e($rulesUrl()) ?>">新增规则</a>
    </div>
</div>

<?php require __DIR__ . '/partials/mail_tabs.php'; ?>
<?php if (($mail['message'] ?? '') !== ''): ?><div class="notice ok"><?= e($mail['message']) ?></div><?php endif; ?>
<?php if (($mail['error'] ?? '') !== ''): ?><div class="notice error"><?= e($mail['error']) ?></div><?php endif; ?>

<section class="panel">
    <div class="panel-head"><span>规则列表</span><span class="sub">条件均为包含匹配，多个条件同时成立才命中</span></div>
    <div class="panel-body rules-layout">
        <div>
            <table class="table">
                <thead><tr><th>规则</th><th>条件</th><th>动作</th><th>状态</th><th></th></tr></thead>
                <tbody>
                <?php if (!$rules): ?><tr><td colspan="5" class="empty">暂无规则。</td></tr><?php endif; ?>
                <?php foreach ($rules as $rule): ?>
                    <tr>
                        <td><strong><?= e($rule['name'] ?? '') ?></strong><small>优先级 <?= e($rule['priority'] ?? 0) ?></small></td>
                        <td>
                            <?php if (($rule['match_from'] ?? '') !== ''): ?><span class="tag gray">发件人: <?= e($rule['match_from']) ?></span><?php endif; ?>
                            <?php if (($rule['match_subject'] ?? '') !== ''): ?><span class="tag gray">主题: <?= e($rule['match_subject']) ?></span><?php endif; ?>
                            <?php if (($rule['match_to'] ?? '') !== ''): ?><span class="tag gray">收件人: <?= e($rule['match_to']) ?></span><?php endif; ?>
                        </td>
                        <td>
                            <?php if (($rule['target_folder_name'] ?? '') !== ''): ?><span class="tag blue">移到 <?= e($rule['target_folder_name']) ?></span><?php endif; ?>
                            <?php if ((int) ($rule['mark_read'] ?? 0) === 1): ?><span class="tag green">标已读</span><?php endif; ?>
                            <?php if ((int) ($rule['mark_important'] ?? 0) === 1): ?><span class="tag green">标重要</span><?php endif; ?>
                        </td>
                        <td><?= (int) ($rule['enabled'] ?? 0) === 1 ? '<span class="tag green">启用</span>' : '<span class="tag gray">停用</span>' ?></td>
                        <td>
                            <a class="btn" href="<?= e($rulesUrl(['rule_id' => (int) ($rule['id'] ?? 0)])) ?>">编辑</a>
                            <form method="post" action="/mail/rules/delete" class="inline-form">
                <?= csrf_field() ?>
                                <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                                <input type="hidden" name="rule_id" value="<?= e($rule['id']) ?>">
                                <button class="btn danger" type="submit">删除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" action="/mail/rules/apply" class="mail-rule-apply">
                <?= csrf_field() ?>
                <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                <select name="account_id">
                    <option value="0">全部账号</option>
                    <?php foreach ($accounts as $account): $aid = (int) ($account['id'] ?? 0); ?>
                        <option value="<?= e($aid) ?>" <?= (int) ($filters['account_id'] ?? 0) === $aid ? 'selected' : '' ?>><?= e($accountLabel($account)) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="folder_id">
                    <option value="0">全部同步文件夹</option>
                    <?php foreach ($folders as $folder): if ((int) ($folder['sync_enabled'] ?? 0) !== 1) { continue; } $fid = (int) ($folder['id'] ?? 0); ?>
                        <option value="<?= e($fid) ?>" <?= (int) ($filters['folder_id'] ?? 0) === $fid ? 'selected' : '' ?>><?= e($folder['account_id']) ?> / <?= e($folderLabel($folder)) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn" type="submit">应用规则</button>
            </form>
        </div>
        <form class="rule-form" method="post" action="/mail/rules/save">
                <?= csrf_field() ?>
            <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
            <input type="hidden" name="id" value="<?= e($selectedRule['id'] ?? 0) ?>">
            <div class="form-section-title"><?= $selectedRule ? '编辑规则' : '新增规则' ?></div>
            <label class="fg"><span>规则名</span><input name="name" value="<?= e($selectedRule['name'] ?? '') ?>" required></label>
            <div class="reply-grid">
                <label class="fg"><span>优先级</span><input name="priority" type="number" value="<?= e($selectedRule['priority'] ?? 0) ?>"></label>
                <label class="check-field"><input type="checkbox" name="enabled" value="1" <?= (int) ($selectedRule['enabled'] ?? 1) === 1 ? 'checked' : '' ?>> 启用</label>
            </div>
            <label class="check-field"><input type="checkbox" name="apply_all" value="1" <?= (int) ($selectedRule['apply_all'] ?? 0) === 1 ? 'checked' : '' ?>> 全部账号</label>
            <div class="mail-checkbox-grid">
                <?php if (!$accounts): ?><span class="sub">暂无邮箱账号</span><?php endif; ?>
                <?php foreach ($accounts as $account): ?>
                    <label><input type="checkbox" name="account_ids[]" value="<?= e($account['id']) ?>" <?= in_array((int) ($account['id'] ?? 0), array_map('intval', (array) ($selectedRule['account_ids'] ?? [])), true) ? 'checked' : '' ?>> <?= e($accountLabel($account)) ?></label>
                <?php endforeach; ?>
            </div>
            <div class="mail-checkbox-grid">
                <?php foreach (['w' => 'Wowma', 'r' => 'Rakuten', 'y' => 'Yahoo', 'm' => 'Mercari', 'yp' => '雅虎拍卖'] as $code => $label): ?>
                    <label><input type="checkbox" name="platforms[]" value="<?= e($code) ?>" <?= in_array($code, explode(',', (string) ($selectedRule['platforms'] ?? '')), true) ? 'checked' : '' ?>> <?= e($label) ?></label>
                <?php endforeach; ?>
            </div>
            <label class="fg"><span>发件人包含</span><input name="match_from" value="<?= e($selectedRule['match_from'] ?? '') ?>"></label>
            <label class="fg"><span>主题包含</span><input name="match_subject" value="<?= e($selectedRule['match_subject'] ?? '') ?>"></label>
            <label class="fg"><span>收件人包含</span><input name="match_to" value="<?= e($selectedRule['match_to'] ?? '') ?>"></label>
            <label class="fg"><span>移动到文件夹名</span><input name="target_folder_name" value="<?= e($selectedRule['target_folder_name'] ?? '') ?>" placeholder="如 お問い合わせ"></label>
            <label class="check-field"><input type="checkbox" name="auto_create_folder" value="1" <?= (int) ($selectedRule['auto_create_folder'] ?? 1) === 1 ? 'checked' : '' ?>> 允许自动创建目标文件夹</label>
            <label class="check-field"><input type="checkbox" name="mark_read" value="1" <?= (int) ($selectedRule['mark_read'] ?? 0) === 1 ? 'checked' : '' ?>> 命中后标已读</label>
            <label class="check-field"><input type="checkbox" name="mark_important" value="1" <?= (int) ($selectedRule['mark_important'] ?? 0) === 1 ? 'checked' : '' ?>> 命中后标重要</label>
            <label class="check-field"><input type="checkbox" name="stop_on_match" value="1" <?= (int) ($selectedRule['stop_on_match'] ?? 1) === 1 ? 'checked' : '' ?>> 命中后停止后续规则</label>
            <div class="toolbar">
                <button class="btn primary" type="submit"><?= $selectedRule ? '保存规则' : '新增规则' ?></button>
                <?php if ($selectedRule): ?><a class="btn" href="<?= e($rulesUrl()) ?>">清空为新增</a><?php endif; ?>
            </div>
        </form>
    </div>
</section>
