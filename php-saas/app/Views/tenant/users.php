<div class="page-head">
    <div>
        <h1>员工管理 <span class="sub">角色、权限与店铺范围</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/assignments?tenant=<?= e($tenantKey) ?>">店铺分配</a>
        <button class="btn primary" type="submit" form="user-add-form">新增员工</button>
    </div>
</div>

<div class="notice">租户管理员可以新增员工并授予权限；普通员工只有被授予“员工管理”权限后，才可以新增或修改员工。超管不直接管理租户内部员工日常权限。</div>

<div class="panel form-panel">
    <div class="panel-head"><span>新增员工</span><span class="sub">采购 / 客服 / 公司管理员</span></div>
    <div class="panel-body">
        <form id="user-add-form" class="form-grid" method="post" action="/users/add">
                <?= csrf_field() ?>
            <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
            <label><span>姓名</span><input name="name" placeholder="如 李四"></label>
            <label><span>登录名</span><input name="username" placeholder="如 lisi"></label>
            <label><span>角色</span><select name="role">
                <?php foreach (array_keys($rolePermissions) as $role): ?>
                    <option><?= e($role) ?></option>
                <?php endforeach; ?>
            </select></label>
            <label><span>状态</span><select name="status"><option value="active">启用</option><option value="disabled">停用</option></select></label>
            <label><span>临时密码</span><input name="password_reset" placeholder="新员工首登密码，可为空"></label>
            <label><span>首选入口</span><select name="preference_module">
                <option value="">默认首页</option>
                <option value="platform">平台订单</option>
                <option value="purchase">采购订单</option>
                <option value="jp">日本仓发货</option>
                <option value="mail">邮件中心</option>
                <option value="profit">利润分析</option>
            </select></label>
            <div class="wide">
                <span class="detail-lb">店铺范围</span>
                <div class="perm-grid">
                    <label class="perm-check"><input type="checkbox" name="stores[]" value="全部店铺" checked>全部店铺</label>
                    <?php foreach ($stores as $store): ?>
                        <label class="perm-check"><input type="checkbox" name="stores[]" value="<?= e($store['name']) ?>"><?= e($store['name']) ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="wide">
                <span class="detail-lb">附加权限</span>
                <div class="perm-grid">
                    <?php foreach (array_unique(array_merge(...array_values($rolePermissions))) as $permission): ?>
                        <label class="perm-check"><input type="checkbox" name="permissions[]" value="<?= e($permission) ?>"><?= e($permission) ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="notice wide">1688 API 配置不再按员工明文维护；请在租户系统设置中维护租户级配置文件。</div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><span>角色默认权限</span><span class="sub">员工未单独覆盖时按角色默认值生效</span></div>
    <div class="panel-body">
        <table class="table">
            <thead><tr><th>角色</th><th>默认权限</th></tr></thead>
            <tbody>
            <?php foreach ($rolePermissions as $role => $permissions): ?>
                <tr>
                    <td><strong><?= e($role) ?></strong></td>
                    <td><?php foreach ($permissions as $permission): ?><span class="tag blue"><?= e($permission) ?></span> <?php endforeach; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><span>员工列表</span><span class="sub"><?= e(count($users)) ?> 名员工</span></div>
    <div class="panel-body">
        <table class="table">
            <thead><tr><th>姓名</th><th>角色</th><th>首选入口</th><th>功能权限</th><th>店铺范围</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><strong><?= e($user['name']) ?></strong><div class="sub"><?= e($user['username']) ?></div></td>
                    <td><?= e($user['role']) ?></td>
                    <td><?= e($user['preference_module'] ?? '-') ?></td>
                    <td><?php foreach (($user['permissions'] ?? []) as $permission): ?><span class="tag blue"><?= e($permission) ?></span> <?php endforeach; ?></td>
                    <td><?= e(implode('、', $user['stores'] ?? [])) ?></td>
                    <td><span class="tag <?= ($user['status'] ?? '') === 'active' ? 'green' : 'gray' ?>"><?= ($user['status'] ?? '') === 'active' ? '启用' : '停用' ?></span></td>
                    <td><a class="btn" href="/users/edit?tenant=<?= e($tenantKey) ?>&id=<?= e($user['id']) ?>">编辑</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
