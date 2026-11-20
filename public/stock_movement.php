<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin();

$pageTitle = 'Stock Movements';

$db = getDB();
$currentUser = getCurrentUser();

// Handle new Stock Movement submission
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_movement') {
    if (!validateCsrf()) {
        $errors[] = 'CSRF security token invalid. Please refresh the page.';
    } else {
        $productId = (int)($_POST['product_id'] ?? 0);
        $type = trim($_POST['type'] ?? '');
        $quantity = (int)($_POST['quantity'] ?? 0);
        $reference = trim($_POST['reference'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        // Validations
        if ($productId <= 0) {
            $errors[] = 'Please select a valid product.';
        }
        if (!in_array($type, ['in', 'out'], true)) {
            $errors[] = 'Movement type must be either "in" (restock) or "out" (usage/sale).';
        }
        if ($quantity <= 0) {
            $errors[] = 'Quantity must be a positive number greater than 0.';
        }

        if (empty($errors)) {
            // DATABASE TRANSACTION WITH ROW LOCKING
            try {
                $db->beginTransaction();

                // 1. Lock the product row with FOR UPDATE to prevent race conditions
                $lockStmt = $db->prepare('SELECT id, sku, name, stock FROM products WHERE id = ? FOR UPDATE');
                $lockStmt->execute([$productId]);
                $prod = $lockStmt->fetch();

                if (!$prod) {
                    throw new Exception('Product not found.');
                }

                $currentStock = (int)$prod['stock'];

                // 2. Strict check: Prevent negative stock
                if ($type === 'out' && $currentStock < $quantity) {
                    throw new Exception("Insufficient stock for '{$prod['name']}'. Current stock is {$currentStock}, cannot decrement {$quantity}.");
                }

                // 3. Compute new stock level
                $newStock = ($type === 'in') ? ($currentStock + $quantity) : ($currentStock - $quantity);

                // 4. Update product stock
                $updateStmt = $db->prepare('UPDATE products SET stock = ? WHERE id = ?');
                $updateStmt->execute([$newStock, $productId]);

                // 5. Insert movement log entry
                $insertStmt = $db->prepare('
                    INSERT INTO stock_movements (product_id, type, quantity, reference, notes, user_id, moved_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ');
                $insertStmt->execute([
                    $productId,
                    $type,
                    $quantity,
                    $reference ?: ($type === 'in' ? 'MANUAL-IN' : 'MANUAL-OUT'),
                    $notes,
                    $currentUser['id'] ?? null
                ]);

                // 6. Commit transaction safely
                $db->commit();

                $direction = ($type === 'in') ? 'added to' : 'deducted from';
                setFlash('success', "Stock successfully {$direction} '{$prod['name']}'. New stock: {$newStock} units.");
                redirect('stock_movement.php');
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $errors[] = $e->getMessage();
            }
        }
    }
}

// Filters for movement history
$filterProduct = (int)($_GET['product_id'] ?? 0);
$filterType = trim($_GET['type'] ?? '');
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo = trim($_GET['date_to'] ?? '');
$page = (int)($_GET['page'] ?? 1);

$where = ['1=1'];
$params = [];

if ($filterProduct > 0) {
    $where[] = 'sm.product_id = ?';
    $params[] = $filterProduct;
}

if (in_array($filterType, ['in', 'out'], true)) {
    $where[] = 'sm.type = ?';
    $params[] = $filterType;
}

if ($filterDateFrom !== '') {
    $where[] = 'DATE(sm.moved_at) >= ?';
    $params[] = $filterDateFrom;
}

if ($filterDateTo !== '') {
    $where[] = 'DATE(sm.moved_at) <= ?';
    $params[] = $filterDateTo;
}

$whereClause = implode(' AND ', $where);

// Total movements count
$countStmt = $db->prepare("SELECT COUNT(*) FROM stock_movements sm WHERE $whereClause");
$countStmt->execute($params);
$totalMovements = (int)$countStmt->fetchColumn();

$pagination = paginate($totalMovements, ITEMS_PER_PAGE, $page);

// Fetch movements log
$query = "
    SELECT sm.*, p.name AS product_name, p.sku, u.username
    FROM stock_movements sm
    JOIN products p ON sm.product_id = p.id
    LEFT JOIN users u ON sm.user_id = u.id
    WHERE $whereClause
    ORDER BY sm.moved_at DESC, sm.id DESC
    LIMIT " . (int)$pagination['per_page'] . " OFFSET " . (int)$pagination['offset'];

$stmt = $db->prepare($query);
$stmt->execute($params);
$movements = $stmt->fetchAll();

// Fetch products list for dropdown
$productsList = $db->query('SELECT id, sku, name, stock FROM products ORDER BY name ASC')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="header-status-pill">
            <span class="pulse-dot"></span>
            Transaction Safety Active &middot; <?= number_format($totalMovements) ?> Logged Entries
        </div>
        <h1 class="page-title">Stock Movements &amp; Audit Trail</h1>
        <p class="page-subtitle">Real-time receipts, usage dispatches, and ACID-locked inventory adjustments.</p>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul style="margin-left: 20px;">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Record New Movement Form -->
<div class="card">
    <h2 class="card-title">Record Stock Movement (In / Out)</h2>
    <form method="POST" action="stock_movement.php">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="record_movement">

        <div class="form-row">
            <div class="form-group" style="flex: 2;">
                <label for="product_id">Product *</label>
                <select name="product_id" id="product_id" class="form-control" required>
                    <option value="">-- Choose Product --</option>
                    <?php foreach ($productsList as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= ($filterProduct === (int)$p['id']) ? 'selected' : '' ?>>
                            <?= e($p['name']) ?> (SKU: <?= e($p['sku']) ?>) - Current Stock: <?= e($p['stock']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="flex: 1;">
                <label for="type">Movement Type *</label>
                <select name="type" id="type" class="form-control" required>
                    <option value="in">🟢 Stock IN (Receipt / Return)</option>
                    <option value="out">🔴 Stock OUT (Sale / Dispatch / Damage)</option>
                </select>
            </div>

            <div class="form-group" style="flex: 1;">
                <label for="quantity">Quantity *</label>
                <input type="number" name="quantity" id="quantity" class="form-control" min="1" placeholder="e.g. 25" required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group" style="flex: 1;">
                <label for="reference">Reference / Order #</label>
                <input type="text" name="reference" id="reference" class="form-control" placeholder="e.g. INV-2024-001, PO-1234, ADJ-01">
            </div>

            <div class="form-group" style="flex: 2;">
                <label for="notes">Movement Reason / Notes</label>
                <input type="text" name="notes" id="notes" class="form-control" placeholder="e.g. Customer order dispatch, damaged box write-off">
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Commit Stock Movement</button>
    </form>
</div>

<!-- History Filter Bar -->
<form method="GET" action="stock_movement.php" class="filter-bar">
    <div style="flex: 2; min-width: 200px;">
        <select name="product_id" class="form-control">
            <option value="">All Products</option>
            <?php foreach ($productsList as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $filterProduct === (int)$p['id'] ? 'selected' : '' ?>>
                    <?= e($p['name']) ?> (<?= e($p['sku']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div style="flex: 1; min-width: 130px;">
        <select name="type" class="form-control">
            <option value="">All Types (IN & OUT)</option>
            <option value="in" <?= $filterType === 'in' ? 'selected' : '' ?>>IN only</option>
            <option value="out" <?= $filterType === 'out' ? 'selected' : '' ?>>OUT only</option>
        </select>
    </div>

    <div style="flex: 1; min-width: 140px;">
        <input type="date" name="date_from" class="form-control" value="<?= e($filterDateFrom) ?>" placeholder="From Date">
    </div>

    <div style="flex: 1; min-width: 140px;">
        <input type="date" name="date_to" class="form-control" value="<?= e($filterDateTo) ?>" placeholder="To Date">
    </div>

    <button type="submit" class="btn btn-secondary">Filter</button>
    <?php if ($filterProduct || $filterType || $filterDateFrom || $filterDateTo): ?>
        <a href="stock_movement.php" class="btn btn-outline" style="color: var(--slate); border-color: var(--border);">Reset</a>
    <?php endif; ?>
</form>

<!-- Movement Log Table -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div class="card-title" style="padding: 16px 20px 0; margin-bottom: 8px;">
        <span>Historical Movement Log (<?= number_format($totalMovements) ?> entries)</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Product</th>
                    <th>Type</th>
                    <th>Quantity</th>
                    <th>Reference</th>
                    <th>Notes</th>
                    <th>User</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($movements)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 32px; color: var(--muted);">No movement logs found matching the filter.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($movements as $m): ?>
                        <tr>
                            <td style="white-space: nowrap; font-size: 0.825rem; color: var(--muted);"><?= formatDate($m['moved_at']) ?></td>
                            <td>
                                <strong style="color: var(--dark);"><?= e($m['product_name']) ?></strong>
                                <div><span class="sku-pill"><?= e($m['sku']) ?></span></div>
                            </td>
                            <td>
                                <?php if ($m['type'] === 'in'): ?>
                                    <span class="badge badge-success"><span class="badge-dot"></span> Stock IN</span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><span class="badge-dot"></span> Stock OUT</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong style="font-size: 1rem; font-family: var(--font-mono); color: <?= $m['type'] === 'in' ? 'var(--success)' : 'var(--danger)' ?>;">
                                    <?= $m['type'] === 'in' ? '+' : '-' ?><?= number_format((int)$m['quantity']) ?>
                                </strong>
                            </td>
                            <td><span style="font-family: var(--font-mono); font-size: 0.8rem;"><?= e($m['reference'] ?: '-') ?></span></td>
                            <td><span style="font-size: 0.825rem; color: var(--muted);"><?= e($m['notes'] ?: '-') ?></span></td>
                            <td><span style="font-size: 0.825rem; font-weight: 600;"><?= e($m['username'] ?: 'System') ?></span></td>
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
            <a href="?page=<?= $i ?>&product_id=<?= $filterProduct ?>&type=<?= urlencode($filterType) ?>&date_from=<?= urlencode($filterDateFrom) ?>&date_to=<?= urlencode($filterDateTo) ?>" class="page-link <?= $i === $pagination['current_page'] ? 'active' : '' ?>">
                <?= $i ?>
            </a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
