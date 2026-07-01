<div class="page-head">
    <div>
        <h1>全局搜索 <span class="sub">跨平台订单、客户、商品、1688 号</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/orders?tenant=<?= e($tenantKey) ?>&view=platform">返回订单</a>
    </div>
</div>

<form class="filter filter-wide" method="get" action="/search">
    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
    <label class="fg">
        <span>关键词</span>
        <input name="q" value="<?= e($keyword) ?>" placeholder="订单号 / 客户 / 商品 / 1688 订单号">
    </label>
    <div class="fg"><span>操作</span><button class="btn primary" type="submit">搜索</button></div>
</form>

<div class="panel">
    <div class="panel-head">
        <span>搜索结果</span>
        <span class="sub"><?= e(count($results)) ?> 条</span>
    </div>
    <div class="panel-body">
        <?php if ($keyword === ''): ?>
            <div class="empty">请输入关键词开始检索。</div>
        <?php elseif (!$results): ?>
            <div class="empty">没有找到匹配订单。</div>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>平台</th><th>订单号</th><th>店铺</th><th>客户</th><th>状态</th><th>子商品</th><th>金额</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($results as $row): ?>
                    <tr>
                        <td><?= e($row['platform']) ?></td>
                        <td class="order-id"><?= e($row['order_no']) ?></td>
                        <td><?= e($row['store']) ?></td>
                        <td><?= e($row['customer']) ?></td>
                        <td><span class="tag gray"><?= e($row['status']) ?></span></td>
                        <td><?= e($row['items']) ?></td>
                        <td>￥<?= e(number_format((float) $row['amount'], 0)) ?></td>
                        <td><a class="btn" href="/orders?tenant=<?= e($tenantKey) ?>&view=platform&q=<?= e($row['order_no']) ?>">查看</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
