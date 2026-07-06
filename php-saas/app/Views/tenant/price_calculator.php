<?php
/** @var array<string, mixed> $calculator */
$calculator = $calculator ?? ['defaults' => ['exchange_rate' => 0.048, 'shipping' => 40, 'deduction' => 70, 'exchange_rate_source' => '固定汇率'], 'rows' => [], 'summary' => []];
$defaults = (array) ($calculator['defaults'] ?? []);
$rows = (array) ($calculator['rows'] ?? []);
$summary = (array) ($calculator['summary'] ?? []);
$initialRows = $rows ?: [[
    'row_no' => 1,
    'name' => '',
    'cost' => 0,
    'shipping' => $defaults['shipping'] ?? 40,
    'deduction' => $defaults['deduction'] ?? 70,
    'exchange_rate' => $defaults['exchange_rate'] ?? 0.048,
    'sale_price' => 0,
    'profit' => 0,
    'profit_rate' => 0,
    'is_profitable' => true,
]];
$number = static fn (mixed $value, int $decimals = 2): string => number_format((float) $value, $decimals, '.', '');
?>
<div class="page-head">
    <div>
        <h1>核价计算器 <span class="sub">多行成本核算</span></h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="/settings?tenant=<?= e($tenantKey) ?>">利润设置</a>
        <a class="btn primary" href="/analytics/profit?tenant=<?= e($tenantKey) ?>">利润核算分析</a>
    </div>
</div>

<div class="notice">
    当前汇率 <?= e($number($defaults['exchange_rate'] ?? 0.048, 4)) ?>（<?= e($defaults['exchange_rate_source'] ?? '固定汇率') ?>），默认运费 ￥<?= e($number($defaults['shipping'] ?? 40, 2)) ?>，默认扣点 <?= e($number($defaults['deduction'] ?? 70, 0)) ?>%。
</div>

<div class="panel">
    <div class="panel-head"><span>核价明细</span><span class="sub">利润可编辑，修改后反推售价</span></div>
    <div class="panel-body">
        <div class="head-actions" style="justify-content:flex-start;margin-bottom:12px;">
            <button class="btn primary" type="button" onclick="addPriceRow()">添加商品</button>
            <button class="btn" type="button" onclick="clearPriceRows()">清空</button>
        </div>
        <table class="table" id="price-calculator-table">
            <thead><tr><th>操作</th><th>名称</th><th>成本</th><th>运费</th><th>扣点%</th><th>汇率</th><th>售价</th><th>利润</th><th>利润率</th></tr></thead>
            <tbody>
            <?php foreach ($initialRows as $index => $row): ?>
                <tr data-row="<?= e($index + 1) ?>">
                    <td><button class="btn" type="button" onclick="deletePriceRow(this)">删除</button></td>
                    <td><input type="text" data-field="name" value="<?= e($row['name'] ?? '') ?>" style="width:120px;"></td>
                    <td><input type="number" step="0.01" data-field="cost" value="<?= e($number($row['cost'] ?? 0)) ?>" oninput="calculatePriceRow(this)"></td>
                    <td><input type="number" step="0.01" data-field="shipping" value="<?= e($number($row['shipping'] ?? $defaults['shipping'] ?? 40)) ?>" oninput="calculatePriceRow(this)"></td>
                    <td><input type="number" step="1" data-field="deduction" value="<?= e($number($row['deduction'] ?? $defaults['deduction'] ?? 70, 0)) ?>" oninput="calculatePriceRow(this)"></td>
                    <td><input type="number" step="0.0001" data-field="exchange_rate" value="<?= e($number($row['exchange_rate'] ?? $defaults['exchange_rate'] ?? 0.048, 4)) ?>" oninput="calculatePriceRow(this)"></td>
                    <td><input type="number" step="1" data-field="sale_price" value="<?= e($number($row['sale_price'] ?? 0, 0)) ?>" oninput="calculatePriceRow(this)"></td>
                    <td><input type="number" step="0.01" data-field="profit" value="<?= e($number($row['profit'] ?? 0)) ?>" oninput="calculateSalePriceFromProfit(this)"></td>
                    <td><input type="text" data-field="profit_rate" value="<?= e($number($row['profit_rate'] ?? 0)) ?>%" readonly></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<section class="grid stats">
    <div class="stat"><div class="stat-label">总成本</div><div class="stat-value" id="pc-total-cost"><?= e($number($summary['total_cost'] ?? 0)) ?></div><div class="stat-sub">成本合计</div></div>
    <div class="stat"><div class="stat-label">总运费</div><div class="stat-value" id="pc-total-shipping"><?= e($number($summary['total_shipping'] ?? 0)) ?></div><div class="stat-sub">运费合计</div></div>
    <div class="stat"><div class="stat-label">总售价</div><div class="stat-value" id="pc-total-sale"><?= e($number($summary['total_sale_price'] ?? 0, 0)) ?></div><div class="stat-sub">日元</div></div>
    <div class="stat"><div class="stat-label">总利润</div><div class="stat-value" id="pc-total-profit"><?= e($number($summary['total_profit'] ?? 0)) ?></div><div class="stat-sub" id="pc-avg-rate"><?= e($number($summary['avg_profit_rate'] ?? 0)) ?>%</div></div>
</section>

<script>
const pcDefaults = {
    shipping: <?= e($number($defaults['shipping'] ?? 40)) ?>,
    deduction: <?= e($number($defaults['deduction'] ?? 70, 0)) ?>,
    exchangeRate: <?= e($number($defaults['exchange_rate'] ?? 0.048, 4)) ?>
};

function pcNumber(input) {
    const value = parseFloat(input && input.value ? input.value : '0');
    return Number.isFinite(value) ? value : 0;
}

function pcField(row, field) {
    return row.querySelector('[data-field="' + field + '"]');
}

function calculatePriceRow(input) {
    const row = input.closest('tr');
    const cost = pcNumber(pcField(row, 'cost'));
    const shipping = pcNumber(pcField(row, 'shipping'));
    const deduction = pcNumber(pcField(row, 'deduction'));
    const rate = pcNumber(pcField(row, 'exchange_rate'));
    const salePrice = pcNumber(pcField(row, 'sale_price'));
    const actualIncome = salePrice * (deduction / 100) * rate;
    const profit = actualIncome - cost - shipping;
    const profitRate = rate > 0 && salePrice > 0 ? profit / rate / salePrice * 100 : 0;
    pcField(row, 'profit').value = profit.toFixed(2);
    pcField(row, 'profit_rate').value = profitRate.toFixed(2) + '%';
    pcField(row, 'profit').style.color = profit >= 0 ? '#15803d' : '#b91c1c';
    pcField(row, 'profit_rate').style.color = profit >= 0 ? '#15803d' : '#b91c1c';
    updatePriceSummary();
}

function calculateSalePriceFromProfit(input) {
    const row = input.closest('tr');
    const cost = pcNumber(pcField(row, 'cost'));
    const shipping = pcNumber(pcField(row, 'shipping'));
    const deduction = pcNumber(pcField(row, 'deduction'));
    const rate = pcNumber(pcField(row, 'exchange_rate'));
    const targetProfit = pcNumber(input);
    if (deduction > 0 && rate > 0) {
        pcField(row, 'sale_price').value = Math.ceil((targetProfit + cost + shipping) / (deduction / 100) / rate);
        calculatePriceRow(input);
    }
}

function addPriceRow() {
    const tbody = document.querySelector('#price-calculator-table tbody');
    const rowNo = tbody.querySelectorAll('tr').length + 1;
    const tr = document.createElement('tr');
    tr.dataset.row = String(rowNo);
    tr.innerHTML = '<td><button class="btn" type="button" onclick="deletePriceRow(this)">删除</button></td>' +
        '<td><input type="text" data-field="name" value="" style="width:120px;"></td>' +
        '<td><input type="number" step="0.01" data-field="cost" value="0.00" oninput="calculatePriceRow(this)"></td>' +
        '<td><input type="number" step="0.01" data-field="shipping" value="' + pcDefaults.shipping.toFixed(2) + '" oninput="calculatePriceRow(this)"></td>' +
        '<td><input type="number" step="1" data-field="deduction" value="' + pcDefaults.deduction.toFixed(0) + '" oninput="calculatePriceRow(this)"></td>' +
        '<td><input type="number" step="0.0001" data-field="exchange_rate" value="' + pcDefaults.exchangeRate.toFixed(4) + '" oninput="calculatePriceRow(this)"></td>' +
        '<td><input type="number" step="1" data-field="sale_price" value="0" oninput="calculatePriceRow(this)"></td>' +
        '<td><input type="number" step="0.01" data-field="profit" value="0.00" oninput="calculateSalePriceFromProfit(this)"></td>' +
        '<td><input type="text" data-field="profit_rate" value="0.00%" readonly></td>';
    tbody.appendChild(tr);
    updatePriceSummary();
}

function deletePriceRow(button) {
    button.closest('tr').remove();
    updatePriceSummary();
}

function clearPriceRows() {
    document.querySelector('#price-calculator-table tbody').innerHTML = '';
    addPriceRow();
}

function updatePriceSummary() {
    let totalCost = 0;
    let totalShipping = 0;
    let totalSale = 0;
    let totalProfit = 0;
    let weightedRate = 0;
    let saleForRate = 0;
    document.querySelectorAll('#price-calculator-table tbody tr').forEach(function(row) {
        const cost = pcNumber(pcField(row, 'cost'));
        const shipping = pcNumber(pcField(row, 'shipping'));
        const sale = pcNumber(pcField(row, 'sale_price'));
        const rate = pcNumber(pcField(row, 'exchange_rate'));
        totalCost += cost;
        totalShipping += shipping;
        totalSale += sale;
        totalProfit += pcNumber(pcField(row, 'profit'));
        if (sale > 0 && rate > 0) {
            weightedRate += sale * rate;
            saleForRate += sale;
        }
    });
    const avgRate = saleForRate > 0 ? weightedRate / saleForRate : 0;
    const avgProfitRate = avgRate > 0 && totalSale > 0 ? totalProfit / avgRate / totalSale * 100 : 0;
    document.getElementById('pc-total-cost').textContent = totalCost.toFixed(2);
    document.getElementById('pc-total-shipping').textContent = totalShipping.toFixed(2);
    document.getElementById('pc-total-sale').textContent = totalSale.toFixed(0);
    document.getElementById('pc-total-profit').textContent = totalProfit.toFixed(2);
    document.getElementById('pc-total-profit').style.color = totalProfit >= 0 ? '#15803d' : '#b91c1c';
    document.getElementById('pc-avg-rate').textContent = avgProfitRate.toFixed(2) + '%';
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('#price-calculator-table tbody tr input[data-field="sale_price"]').forEach(calculatePriceRow);
});
</script>
