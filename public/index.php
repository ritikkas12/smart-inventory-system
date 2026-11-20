<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin();

$pageTitle = 'Dashboard';
$db = getDB();

// 1. KPI Stats
// Total Products
$totalProducts = (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn();

// Total Inventory Valuation (sum of stock * unit_price)
$totalValue = (float)$db->query('SELECT COALESCE(SUM(stock * unit_price), 0) FROM products')->fetchColumn();

// Low Stock Items (stock <= reorder_point)
$lowStockCount = (int)$db->query('SELECT COUNT(*) FROM products WHERE stock <= reorder_point')->fetchColumn();

// Critical Stock Items (stock <= safety_stock)
$criticalCount = (int)$db->query('SELECT COUNT(*) FROM products WHERE stock <= safety_stock')->fetchColumn();

// Pending Purchase Orders (suggested or approved)
$pendingPOCount = (int)$db->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('suggested', 'approved')")->fetchColumn();

// 2. Low Stock Alerts List (top items furthest below reorder point)
$lowStockAlerts = $db->query('
    SELECT p.*, s.name AS supplier_name, s.lead_time_days
    FROM products p
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    WHERE p.stock <= p.reorder_point
    ORDER BY (p.stock - p.reorder_point) ASC, p.stock ASC
    LIMIT 8
')->fetchAll();

// 3. Products list for Forecast Chart selector
$productsForChart = $db->query('SELECT id, sku, name FROM products ORDER BY name ASC')->fetchAll();
$firstProductId = !empty($productsForChart) ? (int)$productsForChart[0]['id'] : 0;

// 4. Pending Purchase Orders List
$pendingPOs = $db->query("
    SELECT po.*, p.name AS product_name, p.sku, s.name AS supplier_name
    FROM purchase_orders po
    JOIN products p ON po.product_id = p.id
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    WHERE po.status IN ('suggested', 'approved')
    ORDER BY po.suggested_at DESC
    LIMIT 5
")->fetchAll();

// 5. Recent Stock Movements (last 7)
$recentMovements = $db->query('
    SELECT sm.*, p.name AS product_name, p.sku, u.username
    FROM stock_movements sm
    JOIN products p ON sm.product_id = p.id
    LEFT JOIN users u ON sm.user_id = u.id
    ORDER BY sm.moved_at DESC
    LIMIT 6
')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Page Header & Global Quick Actions -->
<div class="page-header">
    <div>
        <div class="header-status-pill">
            <span class="pulse-dot"></span>
            System Online &middot; Auto-Replenishment Engine Active
        </div>
        <h1 class="page-title">Executive Inventory Dashboard</h1>
        <p class="page-subtitle">Real-time stock analytics, demand forecasts, and automated purchase order pipeline.</p>
    </div>
    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
        <?php if (isAdmin()): ?>
        <form method="POST" action="purchase_orders.php" style="display:inline-block; margin: 0;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="run_auto_replenish">
            <button type="submit" class="btn btn-warning" title="Recalculate safety stock, ROP and generate suggested POs">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                Run Replenishment Engine
            </button>
        </form>
        <?php endif; ?>
        <a href="stock_movement.php" class="btn btn-primary">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Record Movement
        </a>
    </div>
</div>

<!-- Executive KPI Stat Cards -->
<div class="dashboard-grid">
    <!-- Card 1: Total SKUs -->
    <div class="stat-card">
        <div class="stat-top">
            <div class="stat-icon-wrap">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"></path><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"></path><path d="m3.3 7 8.7 5 8.7-5"></path><path d="M12 22V12"></path></svg>
            </div>
            <span class="stat-chip">Active Catalog</span>
        </div>
        <div>
            <div class="stat-val"><?= number_format($totalProducts) ?></div>
            <div class="stat-label">Total Managed SKUs</div>
        </div>
    </div>

    <!-- Card 2: Inventory Valuation -->
    <div class="stat-card success">
        <div class="stat-top">
            <div class="stat-icon-wrap">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
            </div>
            <span class="stat-chip" style="background: var(--success-light); color: var(--success-hover);">Asset Valuation</span>
        </div>
        <div>
            <div class="stat-val"><?= formatCurrency($totalValue) ?></div>
            <div class="stat-label">Total Stock Value</div>
        </div>
    </div>

    <!-- Card 3: Low Stock Alerts -->
    <div class="stat-card <?= $lowStockCount > 0 ? 'danger' : '' ?>">
        <div class="stat-top">
            <div class="stat-icon-wrap">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
            </div>
            <span class="stat-chip <?= $lowStockCount > 0 ? 'danger' : '' ?>">
                <?= $criticalCount > 0 ? "$criticalCount Critical" : 'Threshold Check' ?>
            </span>
        </div>
        <div>
            <div class="stat-val" style="<?= $lowStockCount > 0 ? 'color: var(--danger);' : '' ?>">
                <?= number_format($lowStockCount) ?>
            </div>
            <div class="stat-label">Items &le; Reorder Point</div>
        </div>
    </div>

    <!-- Card 4: Pending POs -->
    <div class="stat-card warning">
        <div class="stat-top">
            <div class="stat-icon-wrap">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
            </div>
            <span class="stat-chip" style="background: var(--warning-light); color: var(--warning-hover);">Order Pipeline</span>
        </div>
        <div>
            <div class="stat-val"><?= number_format($pendingPOCount) ?></div>
            <div class="stat-label">Pending Purchase Orders</div>
        </div>
    </div>
</div>

<!-- Priority Replenishment Warnings (Low Stock Table) -->
<div class="card" style="border-left: 4px solid var(--danger);">
    <div class="card-header-row">
        <div class="card-title">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
            <span>Priority Replenishment Queue</span>
            <?php if (!empty($lowStockAlerts)): ?>
                <span class="badge badge-danger"><?= count($lowStockAlerts) ?> SKUs Need Restock</span>
            <?php endif; ?>
        </div>
        <a href="products.php?stock_status=low" class="btn btn-sm btn-outline">View All Low Stock &rarr;</a>
    </div>

    <?php if (empty($lowStockAlerts)): ?>
        <div style="padding: 24px; text-align: center; color: var(--success); font-weight: 600;">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin: 0 auto 8px; display: block;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            All products are operating at healthy inventory levels above their reorder thresholds.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Product &amp; SKU</th>
                        <th>Stock Health / Progress</th>
                        <th>Current Units</th>
                        <th>Reorder Point</th>
                        <th>Safety Stock</th>
                        <th>Vendor</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lowStockAlerts as $item): ?>
                        <?php
                            $isOut = $item['stock'] <= 0;
                            $isCritical = $item['stock'] <= $item['safety_stock'];
                            $rop = max(1, (int)$item['reorder_point']);
                            $pct = max(0, min(100, round(((int)$item['stock'] / $rop) * 100)));
                            $meterClass = $isOut || $isCritical ? 'critical' : 'warning';
                        ?>
                        <tr class="<?= $isCritical ? 'highlight-danger' : 'highlight-warning' ?>">
                            <td>
                                <strong style="color: var(--dark);"><?= e($item['name']) ?></strong>
                                <div><span class="sku-pill"><?= e($item['sku']) ?></span></div>
                            </td>
                            <td>
                                <div class="stock-meter-wrap">
                                    <div class="stock-meter-labels">
                                        <span><?= $pct ?>% of ROP</span>
                                        <span><?= $item['stock'] ?> / <?= $rop ?></span>
                                    </div>
                                    <div class="stock-meter-track">
                                        <div class="stock-meter-fill <?= $meterClass ?>" style="width: <?= max(4, $pct) ?>%;"></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if ($isOut): ?>
                                    <span class="badge badge-danger"><span class="badge-dot"></span> 0 (Out of Stock)</span>
                                <?php elseif ($isCritical): ?>
                                    <span class="badge badge-danger"><span class="badge-dot"></span> <?= e($item['stock']) ?> (Critical)</span>
                                <?php else: ?>
                                    <span class="badge badge-warning"><span class="badge-dot"></span> <?= e($item['stock']) ?> (Low)</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= e($item['reorder_point']) ?> units</strong></td>
                            <td style="color: var(--muted);"><?= e($item['safety_stock']) ?> units</td>
                            <td>
                                <span style="font-weight: 600;"><?= e($item['supplier_name'] ?: 'None') ?></span>
                                <?php if ($item['lead_time_days']): ?>
                                    <div style="font-size: 0.75rem; color: var(--muted);"><?= e($item['lead_time_days']) ?>-day lead</div>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <a href="purchase_orders.php?prefill_product_id=<?= (int)$item['id'] ?>" class="btn btn-sm btn-primary">Draft PO</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Demand Forecasting Analytics Card -->
<div class="chart-card">
    <div class="chart-header-row">
        <div>
            <div class="card-title">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
                <span>Demand Forecasting Visualizer</span>
            </div>
            <div class="chart-meta-tags" style="margin-top: 6px;">
                <span class="chart-tag">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="10"></circle></svg>
                    Historical Baseline: 30-Day OUT
                </span>
                <span class="chart-tag">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" style="color: var(--warning);"><circle cx="12" cy="12" r="10"></circle></svg>
                    Forecast Horizon: 14 Days Ahead
                </span>
                <span class="chart-tag">
                    Model: Dual Moving Avg + Exponential Smoothing
                </span>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 10px;">
            <label for="productChartSelect" style="font-size: 0.85rem; font-weight: 700; color: var(--slate-600); white-space: nowrap;">Select SKU:</label>
            <select id="productChartSelect" class="form-control" style="width: auto; min-width: 260px;" onchange="loadForecastChart(this.value)">
                <?php foreach ($productsForChart as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $p['id'] == $firstProductId ? 'selected' : '' ?>>
                        <?= e($p['name']) ?> (<?= e($p['sku']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div id="forecastChartInfo" style="font-size: 0.825rem; color: var(--muted); margin-bottom: 12px; padding: 6px 12px; background: #f8fafc; border-radius: var(--radius-sm); border: 1px solid var(--border-light);">
        Loading forecast projections...
    </div>

    <div class="chart-container">
        <canvas id="forecastChart"></canvas>
    </div>
</div>

<!-- Two-Column Operational Row -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 24px;">
    <!-- Column 1: Pending Purchase Orders -->
    <div class="card">
        <div class="card-header-row">
            <div class="card-title">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"></path><path d="M3 6h18"></path><path d="M16 10a4 4 0 0 1-8 0"></path></svg>
                <span>Pending Purchase Orders</span>
            </div>
            <a href="purchase_orders.php" class="btn btn-sm btn-outline">All Orders &rarr;</a>
        </div>

        <?php if (empty($pendingPOs)): ?>
            <div style="padding: 32px 16px; text-align: center; color: var(--muted); font-size: 0.9rem;">
                No pending orders in pipeline.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>PO#</th>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Status</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingPOs as $po): ?>
                            <tr>
                                <td><strong>#<?= (int)$po['id'] ?></strong></td>
                                <td>
                                    <div style="font-weight: 700; color: var(--dark);"><?= e($po['product_name']) ?></div>
                                    <span class="sku-pill"><?= e($po['sku']) ?></span>
                                </td>
                                <td><strong><?= number_format((int)$po['quantity']) ?></strong> units</td>
                                <td>
                                    <?php if ($po['status'] === 'suggested'): ?>
                                        <span class="badge badge-warning"><span class="badge-dot"></span> Suggested</span>
                                    <?php elseif ($po['status'] === 'approved'): ?>
                                        <span class="badge badge-info"><span class="badge-dot"></span> Approved</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <a href="purchase_orders.php" class="btn btn-sm btn-secondary">Review</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Column 2: Recent Movement Audit Trail -->
    <div class="card">
        <div class="card-header-row">
            <div class="card-title">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path></svg>
                <span>Recent Movements Stream</span>
            </div>
            <a href="stock_movement.php" class="btn btn-sm btn-outline">Audit Log &rarr;</a>
        </div>

        <?php if (empty($recentMovements)): ?>
            <div style="padding: 32px 16px; text-align: center; color: var(--muted); font-size: 0.9rem;">
                No stock movements logged yet.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Product</th>
                            <th>Flow</th>
                            <th style="text-align: right;">Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentMovements as $mv): ?>
                            <tr>
                                <td style="font-size: 0.8rem; color: var(--muted); white-space: nowrap;">
                                    <?= formatDate($mv['moved_at'], 'd M, H:i') ?>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: var(--dark);"><?= e($mv['product_name']) ?></div>
                                    <span class="sku-pill"><?= e($mv['sku']) ?></span>
                                </td>
                                <td>
                                    <?php if ($mv['type'] === 'in'): ?>
                                        <span class="badge badge-success"><span class="badge-dot"></span> Stock IN</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger"><span class="badge-dot"></span> Stock OUT</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <strong style="font-size: 0.95rem; font-family: var(--font-mono); color: <?= $mv['type'] === 'in' ? 'var(--success)' : 'var(--danger)' ?>;">
                                        <?= $mv['type'] === 'in' ? '+' : '-' ?><?= number_format((int)$mv['quantity']) ?>
                                    </strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const firstId = <?= $firstProductId ?>;
    if (firstId > 0) {
        loadForecastChart(firstId);
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
