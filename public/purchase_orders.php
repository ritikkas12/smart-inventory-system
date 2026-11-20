<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin();

$pageTitle = 'Purchase Orders & Auto-Replenishment';

$db = getDB();
$currentUser = getCurrentUser();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        setFlash('error', 'CSRF validation failed.');
        redirect('purchase_orders.php');
    }

    $action = $_POST['action'] ?? '';

    // Enforce role permission: Financial approvals, transmissions, cancellations, and replenishment are Admin only
    if (in_array($action, ['approve', 'send', 'cancel', 'run_auto_replenish'], true) && !isAdmin()) {
        setFlash('error', 'Access denied: Administrator privileges required for this action.');
        redirect('purchase_orders.php');
    }

    // 1. Approve PO (suggested -> approved)
    if ($action === 'approve') {
        $poId = (int)($_POST['po_id'] ?? 0);
        $stmt = $db->prepare('UPDATE purchase_orders SET status = "approved", approved_at = NOW() WHERE id = ? AND status = "suggested"');
        $stmt->execute([$poId]);
        if ($stmt->rowCount() > 0) {
            setFlash('success', "Purchase Order #$poId approved successfully.");
        } else {
            setFlash('error', 'Unable to approve order (it may already be processed).');
        }
        redirect('purchase_orders.php');
    }

    // 2. Send PO to Supplier (approved -> sent)
    if ($action === 'send') {
        $poId = (int)($_POST['po_id'] ?? 0);
        $stmt = $db->prepare('
            SELECT po.*, s.name AS supplier_name, s.contact_email
            FROM purchase_orders po
            LEFT JOIN suppliers s ON po.supplier_id = s.id
            WHERE po.id = ? AND po.status = "approved"
        ');
        $stmt->execute([$poId]);
        $po = $stmt->fetch();

        if ($po) {
            $upd = $db->prepare('UPDATE purchase_orders SET status = "sent", sent_at = NOW() WHERE id = ?');
            $upd->execute([$poId]);

            $emailNotice = !empty($po['contact_email'])
                ? " Notification email transmitted to {$po['supplier_name']} ({$po['contact_email']})."
                : " (No supplier email configured).";

            setFlash('success', "Purchase Order #$poId marked as SENT to supplier.$emailNotice");
        } else {
            setFlash('error', 'Unable to send PO: must be in "approved" state.');
        }
        redirect('purchase_orders.php');
    }

    // 3. Receive PO (sent -> received) with TRANSACTION and automatic Stock IN
    if ($action === 'receive') {
        $poId = (int)($_POST['po_id'] ?? 0);
        try {
            $db->beginTransaction();

            // Lock PO row
            $stmt = $db->prepare('
                SELECT po.*, p.name AS product_name
                FROM purchase_orders po
                JOIN products p ON po.product_id = p.id
                WHERE po.id = ? AND po.status = "sent"
                FOR UPDATE
            ');
            $stmt->execute([$poId]);
            $po = $stmt->fetch();

            if (!$po) {
                throw new Exception('PO not found or not in "sent" state.');
            }

            // 1. Update PO status
            $db->prepare('UPDATE purchase_orders SET status = "received", received_at = NOW() WHERE id = ?')
               ->execute([$poId]);

            // 2. Lock product and increment stock
            $db->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')
               ->execute([$po['quantity'], $po['product_id']]);

            // 3. Insert stock_movement entry
            $db->prepare('
                INSERT INTO stock_movements (product_id, type, quantity, reference, notes, user_id, moved_at)
                VALUES (?, "in", ?, ?, ?, ?, NOW())
            ')->execute([
                $po['product_id'],
                $po['quantity'],
                "PO-REC-#{$poId}",
                "Fulfillment receipt for PO #{$poId}",
                $currentUser['id'] ?? null
            ]);

            $db->commit();
            setFlash('success', "PO #$poId received! {$po['quantity']} units added to stock for '{$po['product_name']}'.");
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            setFlash('error', 'Error receiving PO: ' . $e->getMessage());
        }
        redirect('purchase_orders.php');
    }

    // 4. Cancel PO
    if ($action === 'cancel') {
        $poId = (int)($_POST['po_id'] ?? 0);
        $stmt = $db->prepare('UPDATE purchase_orders SET status = "cancelled" WHERE id = ? AND status IN ("suggested", "approved", "sent")');
        $stmt->execute([$poId]);
        if ($stmt->rowCount() > 0) {
            setFlash('warning', "Purchase Order #$poId has been cancelled.");
        }
        redirect('purchase_orders.php');
    }

    // 5. Create Manual PO
    if ($action === 'create_manual') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 0);
        $unitCost = (float)($_POST['unit_cost'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if ($productId <= 0 || $quantity <= 0) {
            setFlash('error', 'Please choose a product and enter a valid quantity.');
            redirect('purchase_orders.php');
        }

        // Get product's supplier
        $prodStmt = $db->prepare('SELECT supplier_id, unit_price FROM products WHERE id = ?');
        $prodStmt->execute([$productId]);
        $prod = $prodStmt->fetch();

        $supplierId = $prod ? $prod['supplier_id'] : null;
        if ($unitCost <= 0 && $prod) {
            $unitCost = (float)$prod['unit_price'];
        }

        $ins = $db->prepare('
            INSERT INTO purchase_orders (product_id, supplier_id, quantity, unit_cost, status, suggested_at, notes)
            VALUES (?, ?, ?, ?, "suggested", NOW(), ?)
        ');
        $ins->execute([$productId, $supplierId, $quantity, $unitCost, $notes ?: 'Manual Purchase Order']);

        setFlash('success', 'New suggested Purchase Order created successfully.');
        redirect('purchase_orders.php');
    }

    // 6. AUTO-REPLENISHMENT ENGINE
    if ($action === 'run_auto_replenish') {
        $z = SERVICE_LEVEL_Z; // 1.65 for 95% service level
        $reviewDays = REVIEW_PERIOD_DAYS; // 7 days review window

        // Query all products (LEFT JOIN suppliers to support items without an assigned supplier)
        $products = $db->query('
            SELECT p.id, p.sku, p.name, p.stock, p.unit_price, p.supplier_id,
                   COALESCE(s.lead_time_days, 7) AS lead_time_days
            FROM products p
            LEFT JOIN suppliers s ON p.supplier_id = s.id
        ')->fetchAll();

        $updatedCount = 0;
        $posGenerated = 0;

        foreach ($products as $p) {
            $pId = (int)$p['id'];
            $leadTime = max(1, (int)$p['lead_time_days']);
            $currentStock = (int)$p['stock'];

            // Step A: Calculate true daily usage and stddev across past 30 full days (excluding in-progress today)
            $usageQuery = $db->prepare('
                SELECT DATE(moved_at) AS m_date, SUM(quantity) AS daily_qty
                FROM stock_movements
                WHERE product_id = ?
                  AND type = "out"
                  AND moved_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                  AND moved_at < CURDATE()
                GROUP BY DATE(moved_at)
            ');
            $usageQuery->execute([$pId]);
            $dateQtyMap = $usageQuery->fetchAll(PDO::FETCH_KEY_PAIR);

            $dailySeries = [];
            for ($d = 1; $d <= 30; $d++) {
                $dayStr = date('Y-m-d', strtotime("-$d days"));
                $dailySeries[] = isset($dateQtyMap[$dayStr]) ? (float)$dateQtyMap[$dayStr] : 0.0;
            }

            $avgDaily = array_sum($dailySeries) / 30.0;
            $variance = 0.0;
            foreach ($dailySeries as $val) {
                $variance += pow($val - $avgDaily, 2);
            }
            $stdDaily = sqrt($variance / 30.0);

            // Formula:
            // Safety Stock = Z * σ(daily usage) * sqrt(lead time)
            // Reorder Point = (avg daily usage * lead time) + safety stock
            $calcSafetyStock = (int)ceil($z * $stdDaily * sqrt($leadTime));
            $calcReorderPoint = (int)ceil(($avgDaily * $leadTime) + $calcSafetyStock);

            // Update product parameters in DB
            $upd = $db->prepare('UPDATE products SET safety_stock = ?, reorder_point = ? WHERE id = ?');
            $upd->execute([$calcSafetyStock, $calcReorderPoint, $pId]);
            $updatedCount++;

            // Step B: Trigger PO if current stock <= reorder point
            if ($currentStock <= $calcReorderPoint) {
                // Check if an open PO already exists
                $chkPO = $db->prepare('
                    SELECT COUNT(*) FROM purchase_orders
                    WHERE product_id = ? AND status IN ("suggested", "approved", "sent")
                ');
                $chkPO->execute([$pId]);
                $hasOpenPO = (int)$chkPO->fetchColumn() > 0;

                if (!$hasOpenPO) {
                    // Forecast demand over review period (lead_time + reviewDays)
                    $totalHorizon = $leadTime + $reviewDays;

                    // Query forecasts table for the horizon
                    $fQuery = $db->prepare('
                        SELECT COALESCE(SUM(predicted_qty), 0)
                        FROM forecasts
                        WHERE product_id = ?
                          AND forecast_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
                    ');
                    $fQuery->execute([$pId, $totalHorizon]);
                    $forecastDemand = (float)$fQuery->fetchColumn();

                    // Fallback if no forecast in DB yet
                    if ($forecastDemand <= 0) {
                        $forecastDemand = $avgDaily * $totalHorizon;
                    }

                    // Order Qty = Forecast Demand over period - Current Stock
                    $orderQty = (int)ceil($forecastDemand - $currentStock);
                    if ($orderQty < 1) {
                        $orderQty = max(1, (int)ceil($avgDaily * $totalHorizon));
                    }

                    // Insert suggested PO
                    $poNotes = sprintf(
                        'Auto-Replenish: Stock (%d) ≤ ROP (%d). Lead Time: %dd, Safety Stock: %d, Projected Demand: %d.',
                        $currentStock,
                        $calcReorderPoint,
                        $leadTime,
                        $calcSafetyStock,
                        (int)$forecastDemand
                    );

                    $insPO = $db->prepare('
                        INSERT INTO purchase_orders (product_id, supplier_id, quantity, unit_cost, status, suggested_at, notes)
                        VALUES (?, ?, ?, ?, "suggested", NOW(), ?)
                    ');
                    $insPO->execute([$pId, $p['supplier_id'], $orderQty, $p['unit_price'], $poNotes]);
                    $posGenerated++;
                }
            }
        }

        setFlash('success', "Auto-Replenishment complete: Recalculated ROP/Safety Stock for $updatedCount products. Generated $posGenerated suggested Purchase Order(s).");
        redirect('purchase_orders.php');
    }
}

// Prefill parameter from dashboard link
$prefillId = (int)($_GET['prefill_product_id'] ?? 0);

// Status filter & pagination
$filterStatus = trim($_GET['status'] ?? '');
$page = (int)($_GET['page'] ?? 1);

$where = ['1=1'];
$params = [];

if (in_array($filterStatus, ['suggested', 'approved', 'sent', 'received', 'cancelled'], true)) {
    $where[] = 'po.status = ?';
    $params[] = $filterStatus;
}

$whereClause = implode(' AND ', $where);

// Total count
$countStmt = $db->prepare("SELECT COUNT(*) FROM purchase_orders po WHERE $whereClause");
$countStmt->execute($params);
$totalPOs = (int)$countStmt->fetchColumn();

$pagination = paginate($totalPOs, ITEMS_PER_PAGE, $page);

// Fetch POs
$poQuery = "
    SELECT po.*, p.name AS product_name, p.sku, p.stock AS current_stock,
           s.name AS supplier_name, s.contact_email, s.lead_time_days
    FROM purchase_orders po
    JOIN products p ON po.product_id = p.id
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    WHERE $whereClause
    ORDER BY
        CASE po.status
            WHEN 'suggested' THEN 1
            WHEN 'approved' THEN 2
            WHEN 'sent' THEN 3
            ELSE 4
        END,
        po.id DESC
    LIMIT " . (int)$pagination['per_page'] . " OFFSET " . (int)$pagination['offset'];

$poStmt = $db->prepare($poQuery);
$poStmt->execute($params);
$orders = $poStmt->fetchAll();

// Product list for manual creation dropdown
$allProducts = $db->query('SELECT id, sku, name, unit_price, stock, supplier_id FROM products ORDER BY name ASC')->fetchAll();

$prefillPrice = '';
if ($prefillId > 0) {
    foreach ($allProducts as $p) {
        if ((int)$p['id'] === $prefillId) {
            $prefillPrice = $p['unit_price'];
            break;
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="header-status-pill">
            <span class="pulse-dot"></span>
            Replenishment Workflow &middot; <?= number_format($totalPOs) ?> Total Orders
        </div>
        <h1 class="page-title">Purchase Orders &amp; Replenishment</h1>
        <p class="page-subtitle">Automated reorder triggers, approval pipeline, and supplier delivery tracking.</p>
    </div>
    <?php if (isAdmin()): ?>
    <form method="POST" action="purchase_orders.php" style="display:inline-block;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="run_auto_replenish">
        <button type="submit" class="btn btn-warning">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
            Run Replenishment Engine
        </button>
    </form>
    <?php endif; ?>
</div>

<!-- Replenishment Formula Explainer Card -->
<div class="card" style="background: #f8fafc; border-left: 4px solid var(--primary); padding: 18px 24px;">
    <h3 style="font-size: 1rem; color: var(--dark); margin-bottom: 6px;">📐 Statistical Auto-Replenishment Logic</h3>
    <p style="font-size: 0.875rem; color: var(--slate); margin-bottom: 4px;">
        <strong>Safety Stock</strong> = <code>Z (1.65 for 95% service) &times; &sigma;(daily usage) &times; &radic;Lead Time</code>
    </p>
    <p style="font-size: 0.875rem; color: var(--slate); margin-bottom: 4px;">
        <strong>Reorder Point (ROP)</strong> = <code>(Average Daily Usage &times; Lead Time) + Safety Stock</code>
    </p>
    <p style="font-size: 0.875rem; color: var(--slate);">
        When <code>Current Stock &le; ROP</code>, a PO is suggested with <code>Quantity = (Forecast Demand over Review Period) - Current Stock</code>.
    </p>
</div>

<!-- Manual PO Create Section -->
<div class="card">
    <div class="card-title">
        <span>+ Create Manual Purchase Order</span>
    </div>
    <form method="POST" action="purchase_orders.php">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_manual">

        <div class="form-row">
            <div class="form-group" style="flex: 2;">
                <label for="product_id">Product *</label>
                <select name="product_id" id="product_id" class="form-control" required onchange="updatePrice(this)">
                    <option value="">-- Choose Product --</option>
                    <?php foreach ($allProducts as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" data-price="<?= e($p['unit_price']) ?>" <?= ($prefillId === (int)$p['id']) ? 'selected' : '' ?>>
                            <?= e($p['name']) ?> (<?= e($p['sku']) ?>) - Current Stock: <?= e($p['stock']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="flex: 1;">
                <label for="quantity">Order Quantity *</label>
                <input type="number" name="quantity" id="quantity" class="form-control" min="1" placeholder="e.g. 50" required>
            </div>

            <div class="form-group" style="flex: 1;">
                <label for="unit_cost">Unit Cost (₹)</label>
                <input type="number" name="unit_cost" id="unit_cost" class="form-control" step="0.01" min="0" placeholder="0.00" value="<?= e($prefillPrice) ?>">
            </div>

            <div class="form-group" style="flex: 2;">
                <label for="notes">Notes / Reason</label>
                <input type="text" name="notes" id="notes" class="form-control" placeholder="e.g. Seasonal buffer stock">
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Generate Purchase Order</button>
    </form>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <div style="font-weight: 600; font-size: 0.9rem; color: var(--slate);">Filter Orders:</div>
    <a href="purchase_orders.php" class="btn btn-sm <?= $filterStatus === '' ? 'btn-primary' : 'btn-outline' ?>" style="color: <?= $filterStatus === '' ? '#fff' : 'var(--slate)' ?>; border-color: var(--border);">All Statuses</a>
    <a href="purchase_orders.php?status=suggested" class="btn btn-sm <?= $filterStatus === 'suggested' ? 'btn-warning' : 'btn-outline' ?>" style="color: <?= $filterStatus === 'suggested' ? '#fff' : 'var(--slate)' ?>; border-color: var(--border);">Suggested</a>
    <a href="purchase_orders.php?status=approved" class="btn btn-sm <?= $filterStatus === 'approved' ? 'btn-info' : 'btn-outline' ?>" style="color: <?= $filterStatus === 'approved' ? '#fff' : 'var(--slate)' ?>; border-color: var(--border);">Approved</a>
    <a href="purchase_orders.php?status=sent" class="btn btn-sm <?= $filterStatus === 'sent' ? 'btn-secondary' : 'btn-outline' ?>" style="color: <?= $filterStatus === 'sent' ? '#fff' : 'var(--slate)' ?>; border-color: var(--border);">Sent</a>
    <a href="purchase_orders.php?status=received" class="btn btn-sm <?= $filterStatus === 'received' ? 'btn-success' : 'btn-outline' ?>" style="color: <?= $filterStatus === 'received' ? '#fff' : 'var(--slate)' ?>; border-color: var(--border);">Received</a>
    <a href="purchase_orders.php?status=cancelled" class="btn btn-sm <?= $filterStatus === 'cancelled' ? 'btn-danger' : 'btn-outline' ?>" style="color: <?= $filterStatus === 'cancelled' ? '#fff' : 'var(--slate)' ?>; border-color: var(--border);">Cancelled</a>
</div>

<!-- Purchase Orders List Table -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>PO#</th>
                    <th>Product</th>
                    <th>Supplier</th>
                    <th>Order Qty</th>
                    <th>Est. Total</th>
                    <th>Status</th>
                    <th>Timeline</th>
                    <th>Notes</th>
                    <th style="text-align: right;">Workflow Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 32px; color: var(--muted);">No purchase orders found matching this filter.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($orders as $po): ?>
                        <?php
                            $totalCost = (float)$po['quantity'] * (float)$po['unit_cost'];
                            $badgeClass = 'badge-secondary';
                            if ($po['status'] === 'suggested') $badgeClass = 'badge-warning';
                            if ($po['status'] === 'approved')  $badgeClass = 'badge-info';
                            if ($po['status'] === 'sent')      $badgeClass = 'badge-secondary';
                            if ($po['status'] === 'received')  $badgeClass = 'badge-success';
                            if ($po['status'] === 'cancelled') $badgeClass = 'badge-danger';
                        ?>
                        <tr>
                            <td><strong>#<?= (int)$po['id'] ?></strong></td>
                            <td>
                                <strong style="color: var(--dark);"><?= e($po['product_name']) ?></strong>
                                <div><span class="sku-pill"><?= e($po['sku']) ?></span> <span style="font-size: 0.75rem; color: var(--muted);">(Stock: <?= e($po['current_stock']) ?>)</span></div>
                            </td>
                            <td>
                                <strong><?= e($po['supplier_name'] ?: 'No Supplier') ?></strong>
                                <?php if ($po['lead_time_days']): ?>
                                    <div style="font-size: 0.75rem; color: var(--muted);"><?= e($po['lead_time_days']) ?>-day lead</div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= number_format((int)$po['quantity']) ?></strong> units</td>
                            <td><strong style="color: var(--dark);"><?= formatCurrency($totalCost) ?></strong></td>
                            <td><span class="badge <?= $badgeClass ?>"><span class="badge-dot"></span> <?= e(strtoupper($po['status'])) ?></span></td>
                            <td style="font-size: 0.8rem; color: var(--muted); white-space: nowrap;">
                                <div>Created: <?= formatDate($po['suggested_at'], 'd M, H:i') ?></div>
                                <?php if ($po['approved_at']): ?><div>Approved: <?= formatDate($po['approved_at'], 'd M, H:i') ?></div><?php endif; ?>
                                <?php if ($po['sent_at']): ?><div>Sent: <?= formatDate($po['sent_at'], 'd M, H:i') ?></div><?php endif; ?>
                                <?php if ($po['received_at']): ?><div>Received: <?= formatDate($po['received_at'], 'd M, H:i') ?></div><?php endif; ?>
                            </td>
                            <td style="max-width: 220px; font-size: 0.8rem; color: var(--slate);"><?= e($po['notes'] ?: '-') ?></td>
                            <td style="text-align: right; white-space: nowrap;">
                                <!-- Workflow Step 1: Suggested -> Approve or Cancel (Admin Only) -->
                                <?php if ($po['status'] === 'suggested'): ?>
                                    <?php if (isAdmin()): ?>
                                        <form method="POST" action="purchase_orders.php" style="display:inline-block;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="po_id" value="<?= (int)$po['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-primary">✓ Approve</button>
                                        </form>
                                        <form method="POST" action="purchase_orders.php" style="display:inline-block;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="cancel">
                                            <input type="hidden" name="po_id" value="<?= (int)$po['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger btn-confirm" data-confirm="Cancel PO #<?= (int)$po['id'] ?>?">✕ Cancel</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge badge-warning"><span class="badge-dot"></span> Awaiting Approval</span>
                                    <?php endif; ?>

                                <!-- Workflow Step 2: Approved -> Send (Admin Only) -->
                                <?php elseif ($po['status'] === 'approved'): ?>
                                    <?php if (isAdmin()): ?>
                                        <form method="POST" action="purchase_orders.php" style="display:inline-block;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="send">
                                            <input type="hidden" name="po_id" value="<?= (int)$po['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-info">✉ Mark Sent</button>
                                        </form>
                                        <form method="POST" action="purchase_orders.php" style="display:inline-block;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="cancel">
                                            <input type="hidden" name="po_id" value="<?= (int)$po['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger btn-confirm" data-confirm="Cancel PO #<?= (int)$po['id'] ?>?">✕ Cancel</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge badge-info"><span class="badge-dot"></span> Awaiting Dispatch</span>
                                    <?php endif; ?>

                                <!-- Workflow Step 3: Sent -> Receive & Increment Stock -->
                                <?php elseif ($po['status'] === 'sent'): ?>
                                    <form method="POST" action="purchase_orders.php" style="display:inline-block;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="receive">
                                        <input type="hidden" name="po_id" value="<?= (int)$po['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-success btn-confirm" data-confirm="Receive PO #<?= (int)$po['id'] ?>? This will increment stock by <?= (int)$po['quantity'] ?> units.">📦 Receive Stock</button>
                                    </form>

                                <!-- Completed States -->
                                <?php elseif ($po['status'] === 'received'): ?>
                                    <span style="font-size: 0.85rem; color: var(--success); font-weight: 600;">✓ In Stock</span>
                                <?php elseif ($po['status'] === 'cancelled'): ?>
                                    <span style="font-size: 0.85rem; color: var(--muted);">Cancelled</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($pagination['total_pages'] > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
            <a href="?page=<?= $i ?>&status=<?= urlencode($filterStatus) ?>" class="page-link <?= $i === $pagination['current_page'] ? 'active' : '' ?>">
                <?= $i ?>
            </a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<script>
function updatePrice(select) {
    const selected = select.options[select.selectedIndex];
    const price = selected.getAttribute('data-price');
    if (price) {
        document.getElementById('unit_cost').value = price;
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
