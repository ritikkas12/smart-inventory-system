<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
requireAdmin();

$pageTitle = 'Reports & Inventory Analytics';

$db = getDB();

// -------------------------------------------------------------------------
// 1. REPORT: DEAD STOCK ANALYSIS (Last 60 Days)
// -------------------------------------------------------------------------
$deadStockQuery = $db->query('
    SELECT
        p.id, p.sku, p.name, p.category, p.stock, p.unit_price,
        (p.stock * p.unit_price) AS capital_tied_up,
        COALESCE(SUM(CASE WHEN sm.type = "out" AND sm.moved_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) THEN sm.quantity ELSE 0 END), 0) AS out_qty_60d,
        MAX(CASE WHEN sm.type = "out" THEN sm.moved_at ELSE NULL END) AS last_out_date
    FROM products p
    LEFT JOIN stock_movements sm ON p.id = sm.product_id
    GROUP BY p.id
    ORDER BY out_qty_60d ASC, capital_tied_up DESC
');
$deadStockData = $deadStockQuery->fetchAll();

$totalDeadCapital = 0;
$totalDeadItems = 0;
$totalSlowItems = 0;

foreach ($deadStockData as $row) {
    if ((int)$row['out_qty_60d'] === 0 && (int)$row['stock'] > 0) {
        $totalDeadItems++;
        $totalDeadCapital += (float)$row['capital_tied_up'];
    } elseif ((int)$row['out_qty_60d'] < 5 && (int)$row['stock'] > 0) {
        $totalSlowItems++;
    }
}

// -------------------------------------------------------------------------
// 2. REPORT: ABC ANALYSIS (Last 90 Days Revenue Contribution)
// -------------------------------------------------------------------------
$abcQuery = $db->query('
    SELECT
        p.id, p.sku, p.name, p.category, p.unit_price, p.stock,
        COALESCE(SUM(CASE WHEN sm.type = "out" AND sm.moved_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN sm.quantity ELSE 0 END), 0) AS total_out_90d
    FROM products p
    LEFT JOIN stock_movements sm ON p.id = sm.product_id
    GROUP BY p.id
');
$rawAbc = $abcQuery->fetchAll();

// Calculate total value per product
$totalSystemValue = 0;
$processedAbc = [];

foreach ($rawAbc as $item) {
    $val = (float)$item['total_out_90d'] * (float)$item['unit_price'];
    $totalSystemValue += $val;
    $item['revenue_contribution'] = $val;
    $processedAbc[] = $item;
}

// Sort descending by value
usort($processedAbc, function ($a, $b) {
    return $b['revenue_contribution'] <=> $a['revenue_contribution'];
});

// Calculate cumulative and assign Class A, B, C
$runningCumulative = 0;
$classACount = 0; $classAVal = 0;
$classBCount = 0; $classBVal = 0;
$classCCount = 0; $classCVal = 0;

foreach ($processedAbc as &$p) {
    $pctOfTotal = $totalSystemValue > 0 ? ($p['revenue_contribution'] / $totalSystemValue) * 100 : 0;
    $runningCumulative += $pctOfTotal;

    $p['pct_of_total'] = $pctOfTotal;
    $p['cumulative_pct'] = min(100.0, $runningCumulative);

    if ($p['revenue_contribution'] <= 0) {
        $p['abc_class'] = 'C';
        $classCCount++;
        $classCVal += $p['revenue_contribution'];
    } elseif ($p['cumulative_pct'] <= 80.01) {
        $p['abc_class'] = 'A';
        $classACount++;
        $classAVal += $p['revenue_contribution'];
    } elseif ($p['cumulative_pct'] <= 95.01) {
        $p['abc_class'] = 'B';
        $classBCount++;
        $classBVal += $p['revenue_contribution'];
    } else {
        $p['abc_class'] = 'C';
        $classCCount++;
        $classCVal += $p['revenue_contribution'];
    }
}
unset($p);

// -------------------------------------------------------------------------
// 3. REPORT: FORECAST ACCURACY (MAPE)
// -------------------------------------------------------------------------
// Fetch past forecasts that have elapsed, joining with actual daily OUT totals for that exact date
$mapeQuery = $db->query('
    SELECT
        f.product_id,
        p.name AS product_name,
        p.sku,
        f.forecast_date,
        f.predicted_qty,
        COALESCE(actual.daily_out, 0) AS actual_qty
    FROM forecasts f
    JOIN products p ON f.product_id = p.id
    LEFT JOIN (
        SELECT product_id, DATE(moved_at) AS m_date, SUM(quantity) AS daily_out
        FROM stock_movements
        WHERE type = "out"
        GROUP BY product_id, DATE(moved_at)
    ) actual ON actual.product_id = f.product_id AND actual.m_date = f.forecast_date
    WHERE f.forecast_date < CURDATE()
      AND f.forecast_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ORDER BY p.name ASC, f.forecast_date ASC
');
$mapeRows = $mapeQuery->fetchAll();

// Group by product and compute MAPE
$productMape = [];
$allApe = [];

foreach ($mapeRows as $row) {
    $pId = (int)$row['product_id'];
    $actual = (float)$row['actual_qty'];
    $predicted = (float)$row['predicted_qty'];

    if (!isset($productMape[$pId])) {
        $productMape[$pId] = [
            'name'       => $row['product_name'],
            'sku'        => $row['sku'],
            'eval_count' => 0,
            'ape_sum'    => 0,
            'mape'       => 0,
        ];
    }

    // APE = |actual - predicted| / actual * 100 (when actual > 0)
    if ($actual > 0) {
        $ape = (abs($actual - $predicted) / $actual) * 100;
        $productMape[$pId]['ape_sum'] += $ape;
        $productMape[$pId]['eval_count']++;
        $allApe[] = $ape;
    }
}

// Compute averages
$overallMape = count($allApe) > 0 ? array_sum($allApe) / count($allApe) : 0;

foreach ($productMape as $pId => &$item) {
    $item['mape'] = $item['eval_count'] > 0 ? ($item['ape_sum'] / $item['eval_count']) : 0;
}
unset($item);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="header-status-pill">
            <span class="pulse-dot"></span>
            Inventory Intelligence &middot; 3 Operational Audits
        </div>
        <h1 class="page-title">Analytics & Strategic Reports</h1>
        <p class="page-subtitle">Capital tie-up optimization, ABC Pareto revenue classification, and demand forecast accuracy validation.</p>
    </div>
</div>

<div class="tabs-container">
    <!-- Modern Tabs Navigation -->
    <div class="tabs-nav">
        <button class="tab-btn active" data-tab="tab-deadstock">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
            Dead Stock Analysis
        </button>
        <button class="tab-btn" data-tab="tab-abc">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 2a14.5 14.5 0 0 0 0 20 10 10 0 0 0 0-20"></path><path d="M2 12h20"></path></svg>
            ABC Pareto Classification
        </button>
        <button class="tab-btn" data-tab="tab-mape">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
            Forecast Accuracy (MAPE)
        </button>
    </div>

    <!-- ============================================================= -->
    <!-- TAB 1: DEAD STOCK ANALYSIS -->
    <!-- ============================================================= -->
    <div id="tab-deadstock" class="tab-content active">
        <div class="dashboard-grid">
            <div class="stat-card danger">
                <div class="stat-top">
                    <div class="stat-icon-wrap">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                    </div>
                    <span class="stat-chip danger">Trapped Capital</span>
                </div>
                <div>
                    <div class="stat-val" style="color: var(--danger);"><?= formatCurrency($totalDeadCapital) ?></div>
                    <div class="stat-label">Tied up in Stagnant Stock (60d)</div>
                </div>
            </div>

            <div class="stat-card warning">
                <div class="stat-top">
                    <div class="stat-icon-wrap">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    </div>
                    <span class="stat-chip" style="background: var(--warning-light); color: var(--warning-hover);">Zero Activity</span>
                </div>
                <div>
                    <div class="stat-val"><?= $totalDeadItems ?> SKUs</div>
                    <div class="stat-label">Completely Stagnant (0 Sales)</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-icon-wrap">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"></polyline><polyline points="17 18 23 18 23 12"></polyline></svg>
                    </div>
                    <span class="stat-chip">Low Velocity</span>
                </div>
                <div>
                    <div class="stat-val"><?= $totalSlowItems ?> SKUs</div>
                    <div class="stat-label">Slow Moving (&lt; 5 units / 60d)</div>
                </div>
            </div>
        </div>

        <div class="card" style="padding: 0; overflow: hidden;">
            <div class="card-title" style="padding: 16px 20px 0;">
                <span>Products Ranked by Movement Activity (Last 60 Days)</span>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Product Name</th>
                            <th>SKU</th>
                            <th>Category</th>
                            <th>Current Stock</th>
                            <th>Unit Price</th>
                            <th>Capital Tied Up</th>
                            <th>Units Out (60d)</th>
                            <th>Last Sold Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deadStockData as $d): ?>
                            <?php
                                $outCount = (int)$d['out_qty_60d'];
                                $stock = (int)$d['stock'];
                                $isDead = ($outCount === 0 && $stock > 0);
                                $isSlow = ($outCount < 5 && $stock > 0 && !$isDead);
                            ?>
                            <tr class="<?= $isDead ? 'highlight-danger' : ($isSlow ? 'highlight-warning' : '') ?>">
                                <td><strong style="color: var(--dark);"><?= e($d['name']) ?></strong></td>
                                <td><span class="sku-pill"><?= e($d['sku']) ?></span></td>
                                <td><span class="badge badge-secondary"><?= e($d['category'] ?: '-') ?></span></td>
                                <td><?= number_format($stock) ?></td>
                                <td><?= formatCurrency($d['unit_price']) ?></td>
                                <td><strong><?= formatCurrency($d['capital_tied_up']) ?></strong></td>
                                <td><strong><?= number_format($outCount) ?></strong> units</td>
                                <td style="color: var(--muted); font-size: 0.85rem;"><?= formatDate($d['last_out_date'], 'd M Y') ?></td>
                                <td>
                                    <?php if ($isDead): ?>
                                        <span class="badge badge-danger"><span class="badge-dot"></span> Dead Stock</span>
                                    <?php elseif ($isSlow): ?>
                                        <span class="badge badge-warning"><span class="badge-dot"></span> Slow Moving</span>
                                    <?php else: ?>
                                        <span class="badge badge-success"><span class="badge-dot"></span> Active</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- TAB 2: ABC ANALYSIS -->
    <!-- ============================================================= -->
    <div id="tab-abc" class="tab-content">
        <div class="dashboard-grid">
            <div class="stat-card success">
                <div class="stat-top">
                    <div class="stat-icon-wrap">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    </div>
                    <span class="stat-chip" style="background: var(--success-light); color: var(--success-hover);">Top 80% Revenue</span>
                </div>
                <div>
                    <div class="stat-val"><?= formatCurrency($classAVal) ?></div>
                    <div class="stat-label">Class A Items (<?= $classACount ?> High-Value SKUs)</div>
                </div>
            </div>

            <div class="stat-card warning">
                <div class="stat-top">
                    <div class="stat-icon-wrap">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                    </div>
                    <span class="stat-chip" style="background: var(--warning-light); color: var(--warning-hover);">Next 15% Revenue</span>
                </div>
                <div>
                    <div class="stat-val"><?= formatCurrency($classBVal) ?></div>
                    <div class="stat-label">Class B Items (<?= $classBCount ?> Moderate SKUs)</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-icon-wrap">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
                    </div>
                    <span class="stat-chip">Bottom 5% Revenue</span>
                </div>
                <div>
                    <div class="stat-val"><?= formatCurrency($classCVal) ?></div>
                    <div class="stat-label">Class C Items (<?= $classCCount ?> Low-Value SKUs)</div>
                </div>
            </div>
        </div>

        <!-- Chart & Distribution -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 24px;">
            <div class="card" style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                <div class="card-title" style="margin-bottom: 16px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"></circle><path d="M12 2a14.5 14.5 0 0 0 0 20 10 10 0 0 0 0-20"></path><path d="M2 12h20"></path></svg>
                    <span>Revenue Contribution</span>
                </div>
                <div style="width: 260px; height: 260px; position: relative;">
                    <canvas id="abcPieChart"></canvas>
                </div>
            </div>
            <div class="card">
                <div class="card-title" style="margin-bottom: 12px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    <span>Pareto 80/20 Prioritization Guide</span>
                </div>
                <p style="font-size: 0.9rem; color: var(--slate); margin-bottom: 12px;">
                    ABC Analysis categorizes inventory into three priority classes based on revenue throughput over the past 90 days:
                </p>
                <ul style="margin-left: 20px; font-size: 0.875rem; color: var(--slate); line-height: 1.8;">
                    <li><strong style="color: var(--success);">Class A (Top Priority):</strong> Tightest stock control, high-frequency replenishment, frequent reforecasts. Represents ~80% of revenue.</li>
                    <li><strong style="color: var(--warning);">Class B (Moderate):</strong> Periodic review, standard safety buffers. Represents ~15% of throughput.</li>
                    <li><strong style="color: var(--muted);">Class C (Low):</strong> Low monetary contribution (~5% of value). Simple bulk reorders or on-demand ordering to reduce handling costs.</li>
                </ul>
            </div>
        </div>

        <div class="card" style="padding: 0; overflow: hidden;">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>90d Out Qty</th>
                            <th>Unit Price</th>
                            <th>Revenue Contrib.</th>
                            <th>% of Total</th>
                            <th>Cumulative %</th>
                            <th>ABC Class</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($processedAbc as $idx => $p): ?>
                            <tr>
                                <td><strong>#<?= $idx + 1 ?></strong></td>
                                <td><strong><?= e($p['name']) ?></strong></td>
                                <td><span class="sku-pill"><?= e($p['sku']) ?></span></td>
                                <td><?= number_format((int)$p['total_out_90d']) ?></td>
                                <td><?= formatCurrency($p['unit_price']) ?></td>
                                <td><strong><?= formatCurrency($p['revenue_contribution']) ?></strong></td>
                                <td><?= number_format($p['pct_of_total'], 1) ?>%</td>
                                <td><?= number_format($p['cumulative_pct'], 1) ?>%</td>
                                <td>
                                    <?php if ($p['abc_class'] === 'A'): ?>
                                        <span class="badge badge-success"><span class="badge-dot"></span> Class A</span>
                                    <?php elseif ($p['abc_class'] === 'B'): ?>
                                        <span class="badge badge-warning"><span class="badge-dot"></span> Class B</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary"><span class="badge-dot"></span> Class C</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- TAB 3: FORECAST ACCURACY (MAPE) -->
    <!-- ============================================================= -->
    <div id="tab-mape" class="tab-content">
        <div class="dashboard-grid">
            <div class="stat-card <?= $overallMape < 25 ? 'success' : 'warning' ?>">
                <div class="stat-top">
                    <div class="stat-icon-wrap">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
                    </div>
                    <span class="stat-chip <?= $overallMape < 25 ? '' : 'warning' ?>" style="<?= $overallMape < 25 ? 'background: var(--success-light); color: var(--success-hover);' : '' ?>">Benchmark Error</span>
                </div>
                <div>
                    <div class="stat-val"><?= number_format($overallMape, 1) ?>%</div>
                    <div class="stat-label">Overall Average MAPE</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-icon-wrap">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
                    </div>
                    <span class="stat-chip">Actuals Tested</span>
                </div>
                <div>
                    <div class="stat-val"><?= count($allApe) ?> Records</div>
                    <div class="stat-label">Daily Forecast Points Tested</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-icon-wrap">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    </div>
                    <span class="stat-chip">Active Series</span>
                </div>
                <div>
                    <div class="stat-val"><?= count($productMape) ?> SKUs</div>
                    <div class="stat-label">Product Models Evaluated</div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-title">
                <span>Mean Absolute Percentage Error (MAPE) by Product</span>
            </div>
            <p style="font-size: 0.85rem; color: var(--muted); margin-bottom: 16px;">
                MAPE measures the average percentage divergence between statistical forecasts and actual consumption: <code>MAPE = &Sigma;(|Actual - Forecast| / Actual) / N</code>. Lower is better.
            </p>
            <div class="chart-container" style="height: 300px;">
                <canvas id="mapeBarChart"></canvas>
            </div>
        </div>

        <div class="card" style="padding: 0; overflow: hidden;">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Forecast Points Tested</th>
                            <th>Product MAPE (%)</th>
                            <th>Accuracy Rating</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($productMape)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 32px; color: var(--muted);">No past forecasts available for accuracy evaluation yet. Run forecast.py to begin capturing predictive points.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($productMape as $row): ?>
                                <?php
                                    $evalCount = (int)$row['eval_count'];
                                    if ($evalCount === 0) {
                                        $rating = 'Pending Sales Data';
                                        $badge = 'badge-secondary';
                                        $mapeText = 'N/A';
                                    } else {
                                        $m = $row['mape'];
                                        $mapeText = number_format($m, 2) . '%';
                                        if ($m < 20) {
                                            $rating = 'Excellent (< 20%)';
                                            $badge = 'badge-success';
                                        } elseif ($m < 35) {
                                            $rating = 'Good (20-35%)';
                                            $badge = 'badge-info';
                                        } elseif ($m < 50) {
                                            $rating = 'Fair (35-50%)';
                                            $badge = 'badge-warning';
                                        } else {
                                            $rating = 'Poor (> 50%)';
                                            $badge = 'badge-danger';
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><strong style="color: var(--dark);"><?= e($row['name']) ?></strong></td>
                                    <td><span class="sku-pill"><?= e($row['sku']) ?></span></td>
                                    <td><?= $evalCount ?> days</td>
                                    <td><strong style="font-family: var(--font-mono);"><?= $mapeText ?></strong></td>
                                    <td><span class="badge <?= $badge ?>"><span class="badge-dot"></span> <?= $rating ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // 1. ABC Pie Chart
    const pieCanvas = document.getElementById('abcPieChart');
    if (pieCanvas) {
        new Chart(pieCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Class A (80%)', 'Class B (15%)', 'Class C (5%)'],
                datasets: [{
                    data: [<?= round($classAVal, 2) ?>, <?= round($classBVal, 2) ?>, <?= round($classCVal, 2) ?>],
                    backgroundColor: ['#16a34a', '#f59e0b', '#64748b'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }

    // 2. MAPE Bar Chart (only plot products with at least 1 evaluated historical day)
    const mapeCanvas = document.getElementById('mapeBarChart');
    if (mapeCanvas) {
        <?php
            $chartMapeItems = array_values(array_filter($productMape, function($i) { return $i['eval_count'] > 0; }));
        ?>
        const mapeLabels = <?= json_encode(array_map(function($i) { return $i['sku']; }, $chartMapeItems)) ?>;
        const mapeData = <?= json_encode(array_map(function($i) { return round($i['mape'], 1); }, $chartMapeItems)) ?>;

        new Chart(mapeCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: mapeLabels,
                datasets: [{
                    label: 'MAPE Error Rate (%)',
                    data: mapeData,
                    backgroundColor: mapeData.map(v => v < 20 ? '#16a34a' : (v < 35 ? '#0284c7' : (v < 50 ? '#f59e0b' : '#dc2626'))),
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Percentage Error (%)' }
                    }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
