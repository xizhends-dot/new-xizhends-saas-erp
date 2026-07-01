<div class="page-head">
    <div>
        <h1>操作日志 <span class="sub">订单级与子商品级审计</span></h1>
    </div>
    <div class="head-actions">
        <button class="btn" type="button">导出日志</button>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><span>最近操作</span><span class="sub"><?= e(count($logs)) ?> 条</span></div>
    <div class="panel-body">
        <table class="table">
            <thead><tr><th>时间</th><th>订单号</th><th>操作人</th><th>类型</th><th>字段</th><th>变更</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= e($log['time']) ?></td>
                    <td class="order-id"><?= e($log['order_no']) ?></td>
                    <td><?= e($log['user']) ?></td>
                    <td><?= e($log['action']) ?></td>
                    <td><?= e($log['field']) ?></td>
                    <td><?= e($log['change']) ?></td>
                    <td><?= e($log['ip']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
