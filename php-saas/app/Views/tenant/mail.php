<?php
$accounts = $mail['accounts'] ?? [];
$folders = $mail['folders'] ?? [];
$folderTree = $mail['folderTree'] ?? [];
$counts = $mail['counts'] ?? ['unread_map' => [], 'total_map' => [], 'total_unread' => 0, 'total_all' => 0];
$messages = $mail['messages'] ?? ['rows' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1];
$selected = $mail['selected'] ?? null;
$body = $mail['body'] ?? null;
$url = function (array $overrides = []) use ($tenantKey, $filters): string {
    $base = array_merge([
        'tenant' => $tenantKey,
        'account_id' => (int) ($filters['account_id'] ?? 0),
        'folder_id' => (int) ($filters['folder_id'] ?? 0),
        'unread' => !empty($filters['unread']) ? 1 : 0,
        'important' => !empty($filters['important']) ? 1 : 0,
        'q' => (string) ($filters['q'] ?? ''),
        'page' => (int) ($_GET['page'] ?? 1),
    ], $overrides);
    $base = array_filter($base, fn (mixed $value): bool => $value !== '' && $value !== 0 && $value !== false && $value !== null);
    return '/mail?' . http_build_query($base);
};
$accountLabel = function (array $account): string {
    $label = trim((string) (($account['shop_name'] ?? '') ?: ($account['shop_dpqz'] ?? '')));
    return $label !== '' ? $label : (string) ($account['imap_user'] ?? ('账号#' . (int) ($account['id'] ?? 0)));
};
$folderLabel = fn (array $folder): string => (string) (($folder['display_name'] ?? '') ?: ($folder['imap_path'] ?? ''));
$currentTitle = '收件箱汇总';
if ((int) ($filters['folder_id'] ?? 0) > 0) {
    foreach ($folderTree as $node) {
        foreach (($node['folders'] ?? []) as $folder) {
            if ((int) ($folder['id'] ?? 0) === (int) ($filters['folder_id'] ?? 0)) {
                $currentTitle = $accountLabel($node['account']) . ' / ' . $folderLabel($folder);
                break 2;
            }
        }
    }
} elseif ((int) ($filters['account_id'] ?? 0) > 0) {
    foreach ($accounts as $account) {
        if ((int) ($account['id'] ?? 0) === (int) ($filters['account_id'] ?? 0)) {
            $currentTitle = $accountLabel($account);
            break;
        }
    }
}
$returnUrl = $url(['page' => (int) ($messages['page'] ?? 1), 'message_id' => (int) ($selected['id'] ?? 0)]);
$mailTab = 'summary';
?>

<div class="page-head mail-summary-head">
    <div>
        <h1>客服邮件中心 <span class="sub">聚合收件箱 / 邮件预览 / 快速回复</span></h1>
    </div>
    <div class="head-actions">
        <form method="post" action="/mail/sync">
            <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
            <button class="btn primary" type="submit">立即同步</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/partials/mail_tabs.php'; ?>
<?php if (($mail['message'] ?? '') !== ''): ?><div class="notice ok"><?= e($mail['message']) ?></div><?php endif; ?>
<?php if (($mail['error'] ?? '') !== ''): ?><div class="notice error"><?= e($mail['error']) ?></div><?php endif; ?>
<?php if (empty($mail['imapAvailable'])): ?><div class="notice error">当前 PHP 未启用 imap 扩展，账号配置可以保存，但文件夹读取、同步和正文懒加载不可用。</div><?php endif; ?>

<section class="kefu-mail-shell">
    <aside class="kefu-mail-sidebar">
        <div class="kefu-sb-head">
            <a class="<?= ((int) ($filters['account_id'] ?? 0) === 0 && (int) ($filters['folder_id'] ?? 0) === 0) ? 'active' : '' ?>" href="<?= e($url(['account_id' => 0, 'folder_id' => 0, 'page' => 1])) ?>">收件箱<?= ((int) ($filters['account_id'] ?? 0) === 0 && (int) ($filters['folder_id'] ?? 0) === 0) ? '（汇总中）' : '' ?></a>
            <a href="/mail/settings?tenant=<?= e($tenantKey) ?>" title="邮箱配置">设置</a>
        </div>
        <div class="kefu-folder-tree">
            <?php if (!$folderTree): ?>
                <div class="kefu-empty-side">还没有邮箱账号<br><a href="/mail/settings?tenant=<?= e($tenantKey) ?>">去配置</a></div>
            <?php endif; ?>
            <?php foreach ($folderTree as $node): $account = $node['account']; $accountUnread = 0; $accountTotal = 0; ?>
                <?php foreach (($node['folders'] ?? []) as $folder): $fid = (int) ($folder['id'] ?? 0); $accountUnread += (int) (($counts['unread_map'] ?? [])[$fid] ?? 0); $accountTotal += (int) (($counts['total_map'] ?? [])[$fid] ?? 0); endforeach; ?>
                <div class="kefu-sb-group">
                    <a class="kefu-sb-account <?= (int) ($filters['account_id'] ?? 0) === (int) ($account['id'] ?? 0) ? 'active' : '' ?>" href="<?= e($url(['account_id' => (int) ($account['id'] ?? 0), 'folder_id' => 0, 'page' => 1])) ?>">
                        <span><?= e($accountLabel($account)) ?><?= (int) ($account['enabled'] ?? 0) === 1 ? '' : '（停用）' ?></span>
                        <b><?= e($accountUnread) ?>/<?= e($accountTotal) ?></b>
                    </a>
                    <?php foreach (($node['folders'] ?? []) as $folder): if ((int) ($folder['sync_enabled'] ?? 0) !== 1) { continue; } $fid = (int) ($folder['id'] ?? 0); ?>
                        <a class="kefu-sb-folder <?= (int) ($filters['folder_id'] ?? 0) === $fid ? 'active' : '' ?>" href="<?= e($url(['account_id' => 0, 'folder_id' => $fid, 'page' => 1])) ?>">
                            <span><?= e($folderLabel($folder)) ?></span>
                            <b><?= e((int) (($counts['unread_map'] ?? [])[$fid] ?? 0)) ?>/<?= e((int) (($counts['total_map'] ?? [])[$fid] ?? 0)) ?></b>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </aside>

    <section class="kefu-mail-main">
        <div class="kefu-toolbar">
            <span class="kefu-title"><?= e($currentTitle) ?></span>
            <span class="kefu-count">共 <?= e((int) ($messages['total'] ?? 0)) ?> 封</span>
            <span class="kefu-spacer"></span>
            <form method="post" action="/mail/rules/apply">
                <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                <input type="hidden" name="account_id" value="<?= e($filters['account_id'] ?? 0) ?>">
                <input type="hidden" name="folder_id" value="<?= e($filters['folder_id'] ?? 0) ?>">
                <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                <button class="btn" type="submit">应用规则</button>
            </form>
            <form method="post" action="/mail/sync">
                <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                <input type="hidden" name="account_id" value="<?= e($filters['account_id'] ?? 0) ?>">
                <input type="hidden" name="folder_id" value="<?= e($filters['folder_id'] ?? 0) ?>">
                <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                <button class="btn" type="submit">刷新同步</button>
            </form>
            <form class="kefu-search" method="get" action="/mail">
                <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                <input type="hidden" name="account_id" value="<?= e($filters['account_id'] ?? 0) ?>">
                <input type="hidden" name="folder_id" value="<?= e($filters['folder_id'] ?? 0) ?>">
                <label><input type="checkbox" name="unread" value="1" <?= !empty($filters['unread']) ? 'checked' : '' ?> onchange="this.form.submit()"> 仅未读</label>
                <input type="text" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="搜索主题 / 发件人">
                <button class="btn primary" type="submit">搜索</button>
            </form>
        </div>

        <form id="mail-bulk-form" class="kefu-bulkbar" method="post" action="/mail/action">
            <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
            <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
            <span>已选 <b id="kefu-selected-count">0</b> 封：</span>
            <select name="action">
                <option value="read">标已读</option>
                <option value="unread">标未读</option>
                <option value="important">标重要</option>
                <option value="unimportant">取消重要</option>
                <option value="delete">软删除</option>
            </select>
            <button class="btn" type="submit">执行</button>
            <select name="target_folder_id">
                <option value="0">移动到...</option>
                <?php foreach ($folders as $folder): if ((int) ($folder['sync_enabled'] ?? 0) !== 1) { continue; } ?>
                    <option value="<?= e($folder['id']) ?>"><?= e($folder['account_id']) ?> / <?= e($folderLabel($folder)) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn" type="submit" formaction="/mail/move">移动</button>
        </form>

        <div class="kefu-list-area">
            <div class="kefu-list-head">
                <span class="h-ck"><input id="kefu-select-all" type="checkbox" aria-label="全选邮件"></span>
                <span class="h-dot"></span>
                <span class="h-star" data-mail-resize="star">星标<i class="kefu-col-resizer" title="拖动调整星标列宽"></i></span>
                <span class="h-from" data-mail-resize="from">差出人 / 宛先<i class="kefu-col-resizer" title="拖动调整发件人列宽"></i></span>
                <span class="h-subj">件名</span>
                <span class="h-tags" data-mail-resize="tags">标签<i class="kefu-col-resizer" title="拖动调整标签列宽"></i></span>
                <span class="h-date" data-mail-resize="date">日付<i class="kefu-col-resizer" title="拖动调整日期列宽"></i></span>
            </div>
            <div class="kefu-message-list">
                <?php if (!$messages['rows']): ?>
                    <div class="kefu-list-empty">没有邮件<br><span>配置邮箱并运行同步后，邮件会出现在这里。</span></div>
                <?php endif; ?>
                <?php foreach ($messages['rows'] as $row): $mid = (int) ($row['id'] ?? 0); ?>
                    <div class="kefu-mail-row <?= (int) ($row['is_read'] ?? 0) === 0 ? 'unread' : '' ?> <?= $selected && (int) ($selected['id'] ?? 0) === $mid ? 'cur' : '' ?>">
                        <input form="mail-bulk-form" class="kefu-row-check" type="checkbox" name="message_ids[]" value="<?= e($mid) ?>">
                        <span class="kefu-dot"></span>
                        <span class="kefu-star <?= (int) ($row['is_important'] ?? 0) === 1 ? 'on' : '' ?>"><?= (int) ($row['is_important'] ?? 0) === 1 ? '★' : '☆' ?></span>
                        <a class="kefu-row-main" href="<?= e($url(['message_id' => $mid])) ?>">
                            <span class="kefu-from" title="<?= e(($row['is_sent'] ?? false) ? ($row['to_addr'] ?? '') : ($row['from_addr'] ?? '')) ?>"><?= e(($row['is_sent'] ?? false) ? (($row['to_addr'] ?? '') ?: '(无收件人)') : (($row['from_name'] ?? '') ?: ($row['from_addr'] ?? '(无发件人)'))) ?></span>
                            <span class="kefu-subj"><?= e(($row['subject'] ?? '') !== '' ? $row['subject'] : '(无主题)') ?></span>
                            <span class="kefu-tags">
                                <?php if ((int) ($row['replied'] ?? 0) === 1): ?><span class="kefu-tag green">已回复</span><?php endif; ?>
                                <?php if ((int) ($row['has_attachment'] ?? 0) === 1): ?><span class="kefu-tag gold">附件</span><?php endif; ?>
                                <?php if (($row['account_shop_name'] ?? '') !== '' || ($row['account_email'] ?? '') !== ''): ?><span class="kefu-tag shop"><?= e(($row['account_shop_name'] ?? '') ?: ($row['account_email'] ?? '')) ?></span><?php endif; ?>
                                <?php if (($row['folder_name'] ?? '') !== ''): ?><span class="kefu-tag folder"><?= e($row['folder_name']) ?></span><?php endif; ?>
                            </span>
                            <span class="kefu-date"><?= e($row['mail_date'] ?? '') ?></span>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="kefu-pager">
                <?php $p = (int) ($messages['page'] ?? 1); $tp = (int) ($messages['total_pages'] ?? 1); ?>
                <a class="<?= $p <= 1 ? 'disabled' : '' ?>" href="<?= e($url(['page' => max(1, $p - 1)])) ?>">‹ 上一页</a>
                <span class="cur"><?= e($p) ?> / <?= e($tp) ?></span>
                <a class="<?= $p >= $tp ? 'disabled' : '' ?>" href="<?= e($url(['page' => min($tp, $p + 1)])) ?>">下一页 ›</a>
            </div>
        </div>

        <div class="kefu-preview-resizer"></div>
        <section class="kefu-preview-pane">
            <?php if (!$selected): ?>
                <div class="kefu-preview-empty">点击上方邮件查看内容</div>
            <?php else: ?>
                <div class="kefu-preview-head">
                    <div class="kefu-preview-actions">
                        <a href="<?= e($url(['message_id' => (int) ($selected['id'] ?? 0)])) ?>">刷新正文</a>
                    </div>
                    <h2><?= e($selected['subject'] ?? '(无主题)') ?></h2>
                    <p><b>发件人</b> <?= e(($selected['from_name'] ?? '') ?: ($selected['from_addr'] ?? '')) ?> &lt;<?= e($selected['from_addr'] ?? '') ?>&gt;</p>
                    <p><b>收件人</b> <?= e($selected['to_addr'] ?? '') ?></p>
                    <?php if (($body['cc_addr'] ?? '') !== ''): ?><p><b>抄送</b> <?= e($body['cc_addr']) ?></p><?php endif; ?>
                </div>
                <?php if (!empty($body['error'])): ?><div class="notice error"><?= e($body['error']) ?></div><?php endif; ?>
                <div class="kefu-preview-body">
                    <?php if (($body['body_html'] ?? '') !== ''): ?>
                        <iframe sandbox srcdoc="<?= e((string) $body['body_html']) ?>"></iframe>
                    <?php else: ?>
                        <div class="kefu-preview-text"><?= nl2br(e($body['body_text'] ?? '')) ?></div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($body['attachments'])): ?>
                    <div class="kefu-preview-atts">
                        <?php foreach ($body['attachments'] as $attachment): ?>
                            <span class="kefu-tag gold"><?= e(is_array($attachment) ? ($attachment['name'] ?? '附件') : $attachment) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form class="kefu-reply-form" method="post" action="/mail/reply">
                    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                    <input type="hidden" name="message_id" value="<?= e($selected['id'] ?? 0) ?>">
                    <label class="fg"><span>收件人</span><input name="to_addr" value="<?= e($selected['from_addr'] ?? '') ?>"></label>
                    <label class="fg"><span>主题</span><input name="subject" value="<?= e(str_starts_with((string) ($selected['subject'] ?? ''), 'Re:') ? (string) ($selected['subject'] ?? '') : ('Re: ' . (string) ($selected['subject'] ?? ''))) ?>"></label>
                    <div class="reply-grid">
                        <label class="fg"><span>抄送</span><input name="cc_addr"></label>
                        <label class="fg"><span>密送</span><input name="bcc_addr"></label>
                    </div>
                    <label class="fg"><span>回复正文</span><textarea name="body" required></textarea></label>
                    <button class="btn primary" type="submit">发送回复</button>
                </form>
            <?php endif; ?>
        </section>
    </section>
</section>

<div class="kefu-context-menu" id="kefu-mail-context" aria-hidden="true">
    <button type="button" data-mail-action="open">打开预览</button>
    <button type="button" data-mail-action="read">标为已读</button>
    <button type="button" data-mail-action="unread">标为未读</button>
    <button type="button" data-mail-action="important">标记重要</button>
    <button type="button" data-mail-action="unimportant">取消重要</button>
    <button type="button" data-mail-action="move">移动到...</button>
    <button type="button" data-mail-action="delete" class="danger">软删除</button>
</div>
