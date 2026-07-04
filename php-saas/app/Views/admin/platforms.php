<div class="page-head">
    <div>
        <h1>租户授权 <span class="sub">控制每家公司可使用的平台与系统功能</span></h1>
    </div>
    <form class="toolbar" method="get" action="/admin/platforms">
        <label class="fg">
            <span>选择租户</span>
            <select name="tenant" onchange="this.form.submit()">
                <?php foreach ($tenants as $tenant): ?>
                    <option value="<?= e($tenant['key']) ?>" <?= $selected === $tenant['key'] ? 'selected' : '' ?>>
                        <?= e($tenant['company_name']) ?>（<?= e($tenant['db_name']) ?>）
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
</div>

<section class="panel auth-section">
    <div class="panel-head">
        <span>订单平台</span>
        <span class="sub">平台关闭后，租户侧平台订单菜单不展示，也不能按该平台访问订单</span>
    </div>
    <div class="panel-body">
<div class="platform-grid">
    <?php foreach ($platforms as $platform): ?>
        <?php $state = $auth[$platform['code']] ?? ['enabled' => false, 'locked' => false]; ?>
        <div class="platform-card <?= ($state['locked'] ?? false) ? 'locked' : '' ?>">
            <div class="panel-head" style="padding:0 0 10px;border-bottom:1px solid #d8dee8;">
                <span><span class="dot" style="background: <?= e($platform['color']) ?>"></span> <?= e($platform['name']) ?></span>
                <span class="tag <?= ($state['enabled'] ?? false) ? 'green' : 'gray' ?>"><?= ($state['enabled'] ?? false) ? '已开通' : '未开通' ?></span>
            </div>
            <div class="switch-row">
                <span>访问开关</span>
                <form class="mini-form" method="post" action="/admin/platforms/toggle">
                <?= csrf_field() ?>
                    <input type="hidden" name="tenant" value="<?= e($selected) ?>">
                    <input type="hidden" name="platform" value="<?= e($platform['code']) ?>">
                    <input type="hidden" name="field" value="enabled">
                    <button class="btn admin" type="submit"><?= ($state['enabled'] ?? false) ? '关闭' : '开通' ?></button>
                </form>
            </div>
            <div class="switch-row">
                <span>锁定状态</span>
                <form class="mini-form" method="post" action="/admin/platforms/toggle">
                <?= csrf_field() ?>
                    <input type="hidden" name="tenant" value="<?= e($selected) ?>">
                    <input type="hidden" name="platform" value="<?= e($platform['code']) ?>">
                    <input type="hidden" name="field" value="locked">
                    <button class="btn" type="submit"><?= ($state['locked'] ?? false) ? '解锁' : '锁定' ?></button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>
    </div>
</section>

<section class="panel auth-section" id="tenant-features">
    <div class="panel-head">
        <span>系统功能</span>
        <span class="sub">超管关闭后，租户菜单隐藏，直接访问对应页面会拦截</span>
    </div>
    <div class="panel-body feature-auth-groups">
        <?php foreach ($featureGroups as $group): ?>
            <div class="feature-auth-group">
                <div class="feature-auth-head">
                    <strong><?= e($group['title']) ?></strong>
                    <span><?= e($group['desc']) ?></span>
                </div>
                <div class="feature-auth-list">
                    <?php foreach ($group['items'] as $item): ?>
                        <?php $enabled = (bool) ($featureAuth[$item['key']] ?? false); ?>
                        <div class="feature-auth-row <?= $enabled ? '' : 'off' ?>">
                            <div>
                                <strong><?= e($item['name']) ?></strong>
                                <span><?= e($item['desc']) ?></span>
                                <code><?= e($item['key']) ?></code>
                            </div>
                            <form class="mini-form" method="post" action="/admin/features/toggle">
                <?= csrf_field() ?>
                                <input type="hidden" name="tenant" value="<?= e($selected) ?>">
                                <input type="hidden" name="feature" value="<?= e($item['key']) ?>">
                                <button class="btn <?= $enabled ? 'admin' : '' ?>" type="submit"><?= $enabled ? '关闭' : '开通' ?></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
