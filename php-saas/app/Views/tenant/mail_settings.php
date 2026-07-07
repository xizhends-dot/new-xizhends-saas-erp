<?php
$accounts = $mail['accounts'] ?? [];
$folders = $mail['folders'] ?? [];
$selectedAccountId = (int) ($filters['account_id'] ?? 0);
$selectedAccount = null;
if ((int) ($_GET['new_account'] ?? 0) !== 1) {
    foreach ($accounts as $account) {
        if ((int) ($account['id'] ?? 0) === $selectedAccountId) {
            $selectedAccount = $account;
            break;
        }
    }
    if (!$selectedAccount && $accounts) {
        $selectedAccount = $accounts[0];
    }
}
$accountLabel = function (array $account): string {
    $label = trim((string) (($account['shop_name'] ?? '') ?: ($account['shop_dpqz'] ?? '')));
    return $label !== '' ? $label : (string) ($account['imap_user'] ?? ('账号#' . (int) ($account['id'] ?? 0)));
};
$folderLabel = fn (array $folder): string => (string) (($folder['display_name'] ?? '') ?: ($folder['imap_path'] ?? ''));
$settingsUrl = function (array $params = []) use ($tenantKey): string {
    return '/mail/settings?' . http_build_query(array_merge(['tenant' => $tenantKey], $params));
};
$selectedAccountId = (int) ($selectedAccount['id'] ?? 0);
$accountFolders = $selectedAccount
    ? array_values(array_filter($folders, fn (array $f): bool => (int) ($f['account_id'] ?? 0) === $selectedAccountId))
    : [];
$mailTab = 'settings';
?>

<div class="page-head">
    <div>
        <h1>邮箱设置 <span class="sub">账号管理 / IMAP 文件夹 / SMTP 发信</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn primary" href="<?= e($settingsUrl(['new_account' => 1])) ?>">新增邮箱</a>
    </div>
</div>

<?php require __DIR__ . '/partials/mail_tabs.php'; ?>
<?php if (($mail['message'] ?? '') !== ''): ?><div class="notice ok"><?= e($mail['message']) ?></div><?php endif; ?>
<?php if (($mail['error'] ?? '') !== ''): ?><div class="notice error"><?= e($mail['error']) ?></div><?php endif; ?>
<?php if (empty($mail['imapAvailable'])): ?><div class="notice error">当前 PHP 未启用 imap 扩展，账号配置可以保存，但文件夹读取、同步和正文懒加载不可用。</div><?php endif; ?>

<section class="mail-settings-grid">
    <aside class="panel">
        <div class="panel-head"><span>邮箱账号</span><span class="sub"><?= e(count($accounts)) ?> 个账号</span></div>
        <div class="mail-account-manage-list">
            <?php if (!$accounts): ?>
                <div class="empty">暂无邮箱账号。点击右上角新增邮箱。</div>
            <?php endif; ?>
            <?php foreach ($accounts as $account): $aid = (int) ($account['id'] ?? 0); ?>
                <a class="mail-account-card <?= $selectedAccountId === $aid ? 'active' : '' ?>" href="<?= e($settingsUrl(['account_id' => $aid])) ?>">
                    <span>
                        <strong><?= e($accountLabel($account)) ?></strong>
                        <small><?= e($account['imap_user'] ?? '') ?></small>
                    </span>
                    <span class="tag <?= (int) ($account['enabled'] ?? 0) === 1 ? 'green' : 'gray' ?>"><?= (int) ($account['enabled'] ?? 0) === 1 ? '启用' : '停用' ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </aside>

    <section class="panel">
        <div class="panel-head"><span>账号配置</span><span class="sub"><?= $selectedAccount ? e('#' . $selectedAccountId) : '新增账号' ?></span></div>
        <div class="panel-body">
            <form class="config-form" method="post" action="/mail/accounts/save">
                <?= csrf_field() ?>
                <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                <input type="hidden" name="id" value="<?= e($selectedAccount['id'] ?? 0) ?>">
                <div class="form-row">
                    <label class="fg"><span>店铺缩写</span><input name="shop_dpqz" value="<?= e($selectedAccount['shop_dpqz'] ?? '') ?>"></label>
                    <label class="fg"><span>店铺名称</span><input name="shop_name" value="<?= e($selectedAccount['shop_name'] ?? '') ?>"></label>
                    <label class="fg"><span>关联平台</span><select name="platform">
                        <?php foreach (['' => '自动', 'r' => 'Rakuten', 'y' => 'Yahoo', 'w' => 'Wowma', 'm' => 'Mercari', 'q' => 'Qoo10', 'yp' => '雅虎拍卖'] as $code => $label): ?>
                            <option value="<?= e($code) ?>" <?= (string) ($selectedAccount['platform'] ?? '') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                </div>
                <div class="form-row">
                    <label class="fg"><span>IMAP 主机</span><input name="imap_host" value="<?= e($selectedAccount['imap_host'] ?? '') ?>"></label>
                    <label class="fg small"><span>端口</span><input name="imap_port" type="number" value="<?= e($selectedAccount['imap_port'] ?? 993) ?>"></label>
                    <label class="check-field"><input type="checkbox" name="imap_ssl" value="1" <?= (int) ($selectedAccount['imap_ssl'] ?? 1) === 1 ? 'checked' : '' ?>> IMAP SSL</label>
                </div>
                <div class="form-row">
                    <label class="fg"><span>IMAP 用户</span><input name="imap_user" value="<?= e($selectedAccount['imap_user'] ?? '') ?>"></label>
                    <label class="fg"><span>IMAP 密码</span><input name="imap_pass" type="password" placeholder="留空保持不变"></label>
                </div>
                <div class="form-row">
                    <label class="fg"><span>SMTP 主机</span><input name="smtp_host" value="<?= e($selectedAccount['smtp_host'] ?? '') ?>"></label>
                    <label class="fg small"><span>端口</span><input name="smtp_port" type="number" value="<?= e($selectedAccount['smtp_port'] ?? 465) ?>"></label>
                    <label class="fg small"><span>加密</span><select name="smtp_secure">
                        <?php foreach (['ssl' => 'SSL', 'tls' => 'STARTTLS', 'none' => '无'] as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= (string) ($selectedAccount['smtp_secure'] ?? 'ssl') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                </div>
                <div class="form-row">
                    <label class="fg"><span>SMTP 用户</span><input name="smtp_user" value="<?= e($selectedAccount['smtp_user'] ?? '') ?>" placeholder="留空使用 IMAP 用户"></label>
                    <label class="fg"><span>SMTP 密码</span><input name="smtp_pass" type="password" placeholder="留空使用原密码或 IMAP 密码"></label>
                    <label class="fg small"><span>Sent 文件夹</span><input name="sent_folder" value="<?= e($selectedAccount['sent_folder'] ?? 'Sent') ?>"></label>
                </div>
                <div class="form-row">
                    <label class="check-field"><input type="checkbox" name="enabled" value="1" <?= (int) ($selectedAccount['enabled'] ?? 1) === 1 ? 'checked' : '' ?>> 启用账号</label>
                    <label class="fg small"><span>排序</span><input name="sort" type="number" value="<?= e($selectedAccount['sort'] ?? 0) ?>"></label>
                </div>
                <div class="toolbar">
                    <button class="btn primary" type="submit">保存账号</button>
                    <a class="btn" href="<?= e($settingsUrl(['new_account' => 1])) ?>">清空为新增</a>
                </div>
            </form>
            <?php if ($selectedAccount): ?>
                <div class="toolbar account-tools">
                    <form method="post" action="/mail/folders/probe">
                <?= csrf_field() ?>
                        <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                        <input type="hidden" name="account_id" value="<?= e($selectedAccountId) ?>">
                        <button class="btn" type="submit">读取文件夹</button>
                    </form>
                    <form method="post" action="/mail/sync">
                <?= csrf_field() ?>
                        <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                        <input type="hidden" name="account_id" value="<?= e($selectedAccountId) ?>">
                        <input type="hidden" name="return" value="<?= e($settingsUrl(['account_id' => $selectedAccountId])) ?>">
                        <button class="btn" type="submit">同步此账号</button>
                    </form>
                    <form method="post" action="/mail/accounts/delete" onsubmit="return confirm('确认删除此邮箱账号及其本地邮件缓存？')">
                <?= csrf_field() ?>
                        <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                        <input type="hidden" name="account_id" value="<?= e($selectedAccountId) ?>">
                        <button class="btn danger" type="submit">删除账号</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </section>
</section>

<section class="panel mail-config-grid">
    <div class="panel-head"><span>文件夹同步</span><span class="sub">勾选需要进入邮件中心的文件夹</span></div>
    <div class="panel-body">
        <?php if (!$selectedAccount): ?>
            <div class="empty">请选择一个邮箱账号，或先保存新增账号。</div>
        <?php elseif (!$accountFolders): ?>
            <div class="empty">保存账号后点击“读取文件夹”。</div>
        <?php endif; ?>
        <?php foreach ($accountFolders as $folder): ?>
            <form class="folder-row" method="post" action="/mail/folders/save">
                <?= csrf_field() ?>
                <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                <input type="hidden" name="return" value="<?= e($settingsUrl(['account_id' => $selectedAccountId])) ?>">
                <input type="hidden" name="folder_id" value="<?= e($folder['id']) ?>">
                <span class="folder-path"><?= e($folder['imap_path'] ?? '') ?></span>
                <input name="display_name" value="<?= e($folder['display_name'] ?? '') ?>" placeholder="显示名">
                <select name="role">
                    <?php foreach (['inbox' => '收件箱', 'sent' => '送信箱', 'junk' => '垃圾', 'inquiry' => '问询', 'notice' => '通知', 'custom' => '自定义'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= (string) ($folder['role'] ?? 'custom') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="check-inline"><input type="checkbox" name="sync_enabled" value="1" <?= (int) ($folder['sync_enabled'] ?? 0) === 1 ? 'checked' : '' ?>> 同步</label>
                <input name="sort" type="number" value="<?= e($folder['sort'] ?? 0) ?>">
                <button class="btn" type="submit">保存</button>
            </form>
        <?php endforeach; ?>
    </div>
</section>
