<?php
$mailTab = $mailTab ?? 'summary';
$mailTabLink = fn (string $path): string => $path . '?tenant=' . rawurlencode((string) $tenantKey);
?>
<nav class="mail-tabs" aria-label="邮件功能导航">
    <a class="<?= $mailTab === 'summary' ? 'active' : '' ?>" href="<?= e($mailTabLink('/mail')) ?>">邮件汇总</a>
    <a class="<?= $mailTab === 'settings' ? 'active' : '' ?>" href="<?= e($mailTabLink('/mail/settings')) ?>">邮箱设置</a>
    <a class="<?= $mailTab === 'rules' ? 'active' : '' ?>" href="<?= e($mailTabLink('/mail/rules')) ?>">过滤规则</a>
</nav>
