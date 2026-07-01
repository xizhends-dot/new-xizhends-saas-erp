<?php
$notices = is_array($notices ?? null) ? $notices : [];
$draft = is_array($draft ?? null) ? $draft : [];
$targetRoles = is_array($targetRoles ?? null) ? $targetRoles : ['公司管理员', '采购', '客服', '品检'];
$errors = is_array($errors ?? null) ? $errors : [];
$message = (string) ($message ?? '');
$requirements = is_array($requirements ?? null) ? $requirements : [];
$canManageNotices = (bool) ($canManageNotices ?? false);
?>

<div class="page-head">
    <div>
        <h1>通知公告 <span class="sub">租户内公告、首页与订单页展示</span></h1>
    </div>
    <?php if ($canManageNotices): ?>
        <div class="head-actions">
            <button class="btn primary" type="submit" form="tenant-notice-form">保存公告</button>
        </div>
    <?php endif; ?>
</div>

<?php if ($message !== ''): ?>
    <div class="notice"><?= e($message) ?></div>
<?php endif; ?>
<?php if (isset($errors['form'])): ?>
    <div class="notice danger"><?= e($errors['form']) ?></div>
<?php endif; ?>

<?php if ($canManageNotices): ?>
    <div class="grid two-col">
        <div class="panel form-panel">
            <div class="panel-head"><span>发布公告</span><span class="sub">按角色定向，可置顶</span></div>
            <div class="panel-body">
                <form id="tenant-notice-form" class="form-grid" method="post" action="/notices/save">
                    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
                    <input type="hidden" name="id" value="<?= e($draft['id'] ?? '') ?>">

                    <label class="wide">
                        <span>公告标题</span>
                        <input name="title" maxlength="120" value="<?= e($draft['title'] ?? '') ?>" required>
                        <?php if (isset($errors['title'])): ?><small class="form-error"><?= e($errors['title']) ?></small><?php endif; ?>
                    </label>

                    <label>
                        <span>状态</span>
                        <select name="status">
                            <?php foreach (['published' => '发布', 'draft' => '草稿', 'archived' => '归档'] as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= ($draft['status'] ?? 'published') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <span>发布时间</span>
                        <input type="datetime-local" name="published_at" value="<?= e($draft['published_at_input'] ?? '') ?>">
                    </label>

                    <label>
                        <span>过期时间</span>
                        <input type="datetime-local" name="expired_at" value="<?= e($draft['expired_at_input'] ?? '') ?>">
                    </label>

                    <div class="wide">
                        <span class="detail-lb">展示位置</span>
                        <div class="perm-grid">
                            <label class="perm-check"><input type="checkbox" name="is_pinned" value="1" <?= !empty($draft['is_pinned']) ? 'checked' : '' ?>>置顶</label>
                            <label class="perm-check"><input type="checkbox" name="show_on_dashboard" value="1" <?= !array_key_exists('show_on_dashboard', $draft) || !empty($draft['show_on_dashboard']) ? 'checked' : '' ?>>首页展示</label>
                            <label class="perm-check"><input type="checkbox" name="show_on_orders" value="1" <?= !empty($draft['show_on_orders']) ? 'checked' : '' ?>>订单页展示</label>
                        </div>
                    </div>

                    <div class="wide">
                        <span class="detail-lb">可见角色</span>
                        <div class="perm-grid">
                            <?php foreach ($targetRoles as $role): ?>
                                <label class="perm-check"><input type="checkbox" name="target_roles[]" value="<?= e($role) ?>" <?= in_array($role, (array) ($draft['target_roles'] ?? []), true) ? 'checked' : '' ?>><?= e($role) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <label class="wide">
                        <span>公告内容</span>
                        <textarea name="body" rows="8" required><?= e($draft['body'] ?? '') ?></textarea>
                        <?php if (isset($errors['body'])): ?><small class="form-error"><?= e($errors['body']) ?></small><?php endif; ?>
                    </label>
                </form>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head"><span>持久化状态</span><span class="sub">Store 接口</span></div>
            <div class="panel-body">
                <?php if ($requirements): ?>
                    <ul class="plain-list">
                        <?php foreach ($requirements as $item): ?>
                            <li><?= e($item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="notice">公告已接入当前租户设置存储。</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head"><span>公告列表</span><span class="sub"><?= e(count($notices)) ?> 条</span></div>
    <div class="panel-body">
        <table class="table">
            <thead><tr><th>标题</th><?php if ($canManageNotices): ?><th>状态</th><th>位置</th><th>可见角色</th><?php endif; ?><th>发布时间</th><th>作者</th><?php if ($canManageNotices): ?><th>操作</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($notices as $notice): ?>
                <?php
                $noticeBody = (string) ($notice['body'] ?? '');
                $noticePreview = function_exists('mb_substr') ? mb_substr($noticeBody, 0, 80) : substr($noticeBody, 0, 80);
                ?>
                <tr>
                    <td>
                        <strong><?= e($notice['title'] ?? '') ?></strong>
                        <div class="sub"><?= e($noticePreview) ?></div>
                    </td>
                    <?php if ($canManageNotices): ?>
                        <td><span class="tag <?= ($notice['status'] ?? '') === 'published' ? 'green' : 'gray' ?>"><?= e($notice['status'] ?? '') ?></span></td>
                        <td>
                            <?php if (!empty($notice['is_pinned'])): ?><span class="tag blue">置顶</span><?php endif; ?>
                            <?php if (!empty($notice['show_on_dashboard'])): ?><span class="tag green">首页</span><?php endif; ?>
                            <?php if (!empty($notice['show_on_orders'])): ?><span class="tag blue">订单页</span><?php endif; ?>
                        </td>
                        <td><?= e(implode('、', (array) ($notice['target_roles'] ?? [])) ?: '全部') ?></td>
                    <?php endif; ?>
                    <td><?= e($notice['published_at'] ?? '') ?></td>
                    <td><?= e($notice['author_name'] ?? '-') ?></td>
                    <?php if ($canManageNotices): ?>
                        <td><a class="btn" href="/notices/edit?tenant=<?= e($tenantKey) ?>&id=<?= e($notice['id'] ?? 0) ?>">编辑</a></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$notices): ?>
                <tr><td colspan="<?= e($canManageNotices ? 7 : 3) ?>">暂无租户公告。</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
