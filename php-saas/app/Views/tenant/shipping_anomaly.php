<?php
/** @var array<string, mixed> $result */
$result = $result ?? [];
$filters = is_array($result['filters'] ?? null) ? $result['filters'] : [];
$summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
$pagination = is_array($result['pagination'] ?? null) ? $result['pagination'] : [];
$groups = is_array($result['groups'] ?? null) ? $result['groups'] : [];
$platforms = is_array($result['platforms'] ?? null) ? $result['platforms'] : [];
$stores = is_array($result['stores'] ?? null) ? $result['stores'] : [];
$csv = is_array($result['csv'] ?? null) ? $result['csv'] : ['rows' => []];
$currentPath = $currentPath ?? '/stats/shipping-anomaly';
$queryBase = [
    'tenant' => $tenantKey ?? '',
    'platform' => $filters['platform'] ?? '',
    'store' => $filters['store'] ?? '',
    'date_from' => $filters['date_from'] ?? '',
    'date_to' => $filters['date_to'] ?? '',
    'item_id' => $filters['item_id'] ?? '',
    'page_size' => $filters['page_size'] ?? 50,
];
$queryString = static fn (array $extra = []): string => http_build_query(array_merge($queryBase, $extra));
?>
<div class="page-head">
    <div>
        <h1>异常运费检测 <span class="sub">同商品同数量国际运费不一致</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/orders?tenant=<?= e($tenantKey) ?>&view=platform">返回订单</a>
        <?php if (!empty($csv['rows'])): ?>
            <a class="btn primary" href="<?= e($currentPath . '?' . $queryString(['export' => 'csv'])) ?>">导出 CSV</a>
        <?php endif; ?>
    </div>
</div>

<form class="filter" method="get" action="<?= e($currentPath) ?>">
    <input type="hidden" name="tenant" value="<?= e($tenantKey) ?>">
    <label class="fg">
        <span>平台</span>
        <select name="platform">
            <option value="">全部平台</option>
            <?php foreach ($platforms as $code => $name): ?>
                <option value="<?= e($code) ?>" <?= (string) ($filters['platform'] ?? '') === (string) $code ? 'selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="fg">
        <span>店铺</span>
        <select name="store">
            <option value="">全部店铺</option>
            <?php foreach ($stores as $store): ?>
                <?php $storeName = (string) ($store['name'] ?? ''); ?>
                <?php if ($storeName !== ''): ?>
                    <option value="<?= e($storeName) ?>" <?= (string) ($filters['store'] ?? '') === $storeName ? 'selected' : '' ?>><?= e($storeName) ?></option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="fg">
        <span>开始日期</span>
        <input type="date" name="date_from" value="<?= e($filters['date_from'] ?? '') ?>">
    </label>
    <label class="fg">
        <span>结束日期</span>
        <input type="date" name="date_to" value="<?= e($filters['date_to'] ?? '') ?>">
    </label>
    <label class="fg">
        <span>商品ID</span>
        <input type="text" name="item_id" value="<?= e($filters['item_id'] ?? '') ?>" placeholder="ItemId / lotNumber">
    </label>
    <label class="fg">
        <span>每页</span>
        <select name="page_size">
            <?php foreach ([50, 100, 200, 500] as $pageSize): ?>
                <option value="<?= e($pageSize) ?>" <?= (int) ($filters['page_size'] ?? 50) === $pageSize ? 'selected' : '' ?>><?= e($pageSize) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button class="btn primary" type="submit">查询</button>
    <a class="btn" href="<?= e($currentPath . '?tenant=' . rawurlencode((string) $tenantKey)) ?>">重置</a>
</form>

<section class="grid stats">
    <div class="stat">
        <div class="stat-label">异常组</div>
        <div class="stat-value"><?= e($summary['total_groups'] ?? 0) ?></div>
        <div class="stat-sub">按商品ID + 数量聚合</div>
    </div>
    <div class="stat">
        <div class="stat-label">明细行</div>
        <div class="stat-value"><?= e($summary['total_rows'] ?? 0) ?></div>
        <div class="stat-sub">仅异常组内订单商品</div>
    </div>
    <div class="stat">
        <div class="stat-label">CSV 行</div>
        <div class="stat-value"><?= e(count($csv['rows'] ?? [])) ?></div>
        <div class="stat-sub">供控制器直接导出</div>
    </div>
    <div class="stat">
        <div class="stat-label">页码</div>
        <div class="stat-value"><?= e(($pagination['page'] ?? 1) . '/' . ($pagination['total_pages'] ?? 1)) ?></div>
        <div class="stat-sub"><?= e($pagination['page_size'] ?? 50) ?> 组/页</div>
    </div>
</section>

<?php if (!$groups): ?>
    <div class="empty">
        <strong>未发现异常运费</strong>
        <span>当前筛选范围内没有同商品同数量但国际运费不一致的记录。</span>
    </div>
<?php else: ?>
    <?php foreach ($groups as $group): ?>
        <section class="panel">
            <div class="panel-head">
                <span>
                    商品ID <?= e($group['item_id'] ?? '') ?>
                    <span class="sub">数量 <?= e($group['quantity'] ?? '') ?>，<?= e($group['order_count'] ?? 0) ?> 行，<?= e($group['fee_types'] ?? 0) ?> 种运费</span>
                </span>
                <span>
                    <?php foreach (($group['fee_values'] ?? []) as $fee): ?>
                        <span class="tag red"><?= e($fee) ?></span>
                    <?php endforeach; ?>
                </span>
            </div>
            <div class="panel-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th>图片</th>
                            <th>订单号</th>
                            <th>订单时间</th>
                            <th>平台</th>
                            <th>店铺</th>
                            <th>商品属性</th>
                            <th>国际运单号</th>
                            <th>国际运费</th>
                            <th>字段</th>
                            <th>重量</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($group['rows'] ?? []) as $row): ?>
                        <tr>
                            <td>
                                <?php if (trim((string) ($row['image'] ?? '')) !== ''): ?>
                                    <a href="<?= e($row['image']) ?>" target="_blank" rel="noopener noreferrer">查看</a>
                                <?php else: ?>
                                    <span class="sub">无图</span>
                                <?php endif; ?>
                            </td>
                            <td class="order-id"><?= e($row['order_no'] ?? '') ?></td>
                            <td><?= e($row['order_date'] ?? '') ?></td>
                            <td><?= e($row['platform_name'] ?? $row['platform'] ?? '') ?></td>
                            <td><?= e($row['store'] ?? '') ?></td>
                            <td><?= e($row['item_option'] ?? '') ?></td>
                            <td><?= e($row['shipnumber'] ?? '') ?></td>
                            <td><span class="tag red"><?= e($row['fee_value'] ?? '') ?></span></td>
                            <td><?= e($row['fee_source'] ?? '') ?></td>
                            <td><?= e($row['weight'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>

    <?php if ((int) ($pagination['total_pages'] ?? 1) > 1): ?>
        <div class="toolbar" style="justify-content:center;margin-top:12px;">
            <?php if ((int) ($pagination['page'] ?? 1) > 1): ?>
                <a class="btn" href="<?= e($currentPath . '?' . $queryString(['page' => 1])) ?>">首页</a>
                <a class="btn" href="<?= e($currentPath . '?' . $queryString(['page' => (int) ($pagination['page'] ?? 1) - 1])) ?>">上一页</a>
            <?php endif; ?>
            <span class="tag gray">第 <?= e($pagination['page'] ?? 1) ?> / <?= e($pagination['total_pages'] ?? 1) ?> 页</span>
            <?php if ((int) ($pagination['page'] ?? 1) < (int) ($pagination['total_pages'] ?? 1)): ?>
                <a class="btn" href="<?= e($currentPath . '?' . $queryString(['page' => (int) ($pagination['page'] ?? 1) + 1])) ?>">下一页</a>
                <a class="btn" href="<?= e($currentPath . '?' . $queryString(['page' => $pagination['total_pages'] ?? 1])) ?>">末页</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
