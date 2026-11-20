<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin();

$pageTitle = 'Suppliers';

$db = getDB();

// Handle Delete via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!isAdmin()) {
        setFlash('error', 'Access denied: Only administrators can delete suppliers.');
        redirect('suppliers.php');
    }
    if (!validateCsrf()) {
        setFlash('error', 'CSRF validation failed.');
        redirect('suppliers.php');
    }
    $deleteId = (int)($_POST['id'] ?? 0);
    if ($deleteId > 0) {
        // Check if supplier has assigned products
        $chk = $db->prepare('SELECT COUNT(*) FROM products WHERE supplier_id = ?');
        $chk->execute([$deleteId]);
        $prodCount = (int)$chk->fetchColumn();

        // Check if supplier has open purchase orders
        $chkPO = $db->prepare('SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ? AND status IN ("suggested", "approved", "sent")');
        $chkPO->execute([$deleteId]);
        $poCount = (int)$chkPO->fetchColumn();

        if ($prodCount > 0) {
            setFlash('error', "Cannot delete supplier: There are $prodCount product(s) linked to this supplier. Reassign them first.");
        } elseif ($poCount > 0) {
            setFlash('error', "Cannot delete supplier: There are $poCount active purchase order(s) pending with this vendor.");
        } else {
            $stmt = $db->prepare('DELETE FROM suppliers WHERE id = ?');
            $stmt->execute([$deleteId]);
            setFlash('success', 'Supplier deleted successfully.');
        }
    }
    redirect('suppliers.php');
}

$search = trim($_GET['search'] ?? '');
$page = (int)($_GET['page'] ?? 1);

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(s.name LIKE ? OR s.contact_email LIKE ? OR s.phone LIKE ?)';
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereClause = implode(' AND ', $where);

// Count total
$countStmt = $db->prepare("SELECT COUNT(*) FROM suppliers s WHERE $whereClause");
$countStmt->execute($params);
$totalSuppliers = (int)$countStmt->fetchColumn();

$pagination = paginate($totalSuppliers, ITEMS_PER_PAGE, $page);

// Fetch suppliers with product count
$query = "
    SELECT s.*, COUNT(p.id) AS products_count
    FROM suppliers s
    LEFT JOIN products p ON s.id = p.supplier_id
    WHERE $whereClause
    GROUP BY s.id
    ORDER BY s.id DESC
    LIMIT " . (int)$pagination['per_page'] . " OFFSET " . (int)$pagination['offset'];

$stmt = $db->prepare($query);
$stmt->execute($params);
$suppliers = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="header-status-pill">
            <span class="pulse-dot"></span>
            Vendor Network &middot; <?= number_format($totalSuppliers) ?> Active Suppliers
        </div>
        <h1 class="page-title">Suppliers Directory</h1>
        <p class="page-subtitle">Track fulfillment lead times, vendor contacts, and assigned products.</p>
    </div>
    <?php if (isAdmin()): ?>
    <a href="supplier_form.php" class="btn btn-primary">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Add New Supplier
    </a>
    <?php endif; ?>
</div>

<form method="GET" action="suppliers.php" class="filter-bar">
    <div style="flex: 1; min-width: 250px;">
        <input type="text" name="search" class="form-control" placeholder="Search suppliers by name, email, or phone..." value="<?= e($search) ?>">
    </div>
    <button type="submit" class="btn btn-secondary">Search</button>
    <?php if ($search !== ''): ?>
        <a href="suppliers.php" class="btn btn-outline" style="color: var(--slate); border-color: var(--border);">Reset</a>
    <?php endif; ?>
</form>

<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Supplier Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Lead Time</th>
                    <th>Products Supplied</th>
                    <th>Address</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($suppliers)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 32px; color: var(--muted);">No suppliers found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($suppliers as $s): ?>
                        <tr>
                            <td><strong><?= e($s['name']) ?></strong></td>
                            <td><?= e($s['contact_email'] ?: '-') ?></td>
                            <td><?= e($s['phone'] ?: '-') ?></td>
                            <td>
                                <span class="badge badge-info"><?= e($s['lead_time_days']) ?> days</span>
                            </td>
                            <td>
                                <span class="badge badge-secondary"><?= e($s['products_count']) ?> product(s)</span>
                            </td>
                            <td>
                                <span style="font-size: 0.85rem; color: var(--muted);"><?= e($s['address'] ?: '-') ?></span>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <?php if (isAdmin()): ?>
                                    <a href="supplier_form.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
                                    <form method="POST" action="suppliers.php" style="display: inline-block;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger btn-confirm" data-confirm="Delete supplier '<?= e($s['name']) ?>'?">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span style="color: var(--muted); font-size: 0.8rem;">Read-Only</span>
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
            <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>" class="page-link <?= $i === $pagination['current_page'] ? 'active' : '' ?>">
                <?= $i ?>
            </a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
