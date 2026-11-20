<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin();

$pageTitle = 'Products';

$db = getDB();

// Handle Delete via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!isAdmin()) {
        setFlash('error', 'Access denied: Only administrators can delete products.');
        redirect('products.php');
    }
    if (!validateCsrf()) {
        setFlash('error', 'CSRF validation failed.');
        redirect('products.php');
    }
    $deleteId = (int)($_POST['id'] ?? 0);
    if ($deleteId > 0) {
        $stmt = $db->prepare('DELETE FROM products WHERE id = ?');
        $stmt->execute([$deleteId]);
        setFlash('success', 'Product deleted successfully.');
    }
    redirect('products.php');
}

// Search and Category filters
$search = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');
$stockFilter = trim($_GET['stock_status'] ?? '');
$page = (int)($_GET['page'] ?? 1);

// Build query
$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(p.sku LIKE ? OR p.name LIKE ? OR p.description LIKE ?)';
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($category !== '') {
    $where[] = 'p.category = ?';
    $params[] = $category;
}

if ($stockFilter === 'low') {
    $where[] = 'p.stock <= p.reorder_point';
} elseif ($stockFilter === 'out') {
    $where[] = 'p.stock <= 0';
}

$whereClause = implode(' AND ', $where);

// Count for pagination
$countStmt = $db->prepare("SELECT COUNT(*) FROM products p WHERE $whereClause");
$countStmt->execute($params);
$totalProducts = (int)$countStmt->fetchColumn();

$pagination = paginate($totalProducts, ITEMS_PER_PAGE, $page);

// Fetch categories for filter dropdown
$catStmt = $db->query('SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != "" ORDER BY category');
$categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch products with supplier join
$query = "
    SELECT p.*, s.name AS supplier_name, s.lead_time_days
    FROM products p
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    WHERE $whereClause
    ORDER BY p.id DESC
    LIMIT " . (int)$pagination['per_page'] . " OFFSET " . (int)$pagination['offset'];

$stmt = $db->prepare($query);
$stmt->execute($params);
$products = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="header-status-pill">
            <span class="pulse-dot"></span>
            Catalog Inventory &middot; <?= number_format($totalProducts) ?> Total SKUs
        </div>
        <h1 class="page-title">Products Directory</h1>
        <p class="page-subtitle">Manage inventory stock thresholds, unit costs, and supplier links.</p>
    </div>
    <a href="product_form.php" class="btn btn-primary">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Add New Product
    </a>
</div>

<!-- Filter Bar -->
<form method="GET" action="products.php" class="filter-bar">
    <div style="flex: 2; min-width: 220px;">
        <input type="text" name="search" class="form-control" placeholder="Search by SKU, title, or keyword..." value="<?= e($search) ?>">
    </div>
    <div style="flex: 1; min-width: 160px;">
        <select name="category" class="form-control">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= e($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div style="flex: 1; min-width: 160px;">
        <select name="stock_status" class="form-control">
            <option value="">All Stock Levels</option>
            <option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>Low Stock (≤ Reorder)</option>
            <option value="out" <?= $stockFilter === 'out' ? 'selected' : '' ?>>Out of Stock (0)</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary">Filter</button>
    <?php if ($search !== '' || $category !== '' || $stockFilter !== ''): ?>
        <a href="products.php" class="btn btn-outline">Reset</a>
    <?php endif; ?>
</form>

<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Product Title</th>
                    <th>Category</th>
                    <th>Unit Price</th>
                    <th>Current Stock</th>
                    <th>Reorder Point</th>
                    <th>Safety Stock</th>
                    <th>Supplier</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px; color: var(--muted);">No products found matching the search criteria.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $p): ?>
                        <?php
                            $isOutOfStock = $p['stock'] <= 0;
                            $isCritical = $p['stock'] <= $p['safety_stock'];
                            $isLow = $p['stock'] <= $p['reorder_point'];

                            $rowClass = '';
                            if ($isOutOfStock || $isCritical) {
                                $rowClass = 'highlight-danger';
                            } elseif ($isLow) {
                                $rowClass = 'highlight-warning';
                            }
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td><span class="sku-pill"><?= e($p['sku']) ?></span></td>
                            <td>
                                <strong style="color: var(--dark); font-size: 0.95rem;"><?= e($p['name']) ?></strong>
                                <?php if (!empty($p['description'])): ?>
                                    <div style="font-size: 0.8rem; color: var(--muted); max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= e($p['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-secondary"><?= e($p['category'] ?: 'General') ?></span></td>
                            <td><strong style="color: var(--dark);"><?= formatCurrency($p['unit_price']) ?></strong></td>
                            <td>
                                <?php if ($isOutOfStock): ?>
                                    <span class="badge badge-danger"><span class="badge-dot"></span> 0 (Out of stock)</span>
                                <?php elseif ($isCritical): ?>
                                    <span class="badge badge-danger"><span class="badge-dot"></span> <?= e($p['stock']) ?> (Critical)</span>
                                <?php elseif ($isLow): ?>
                                    <span class="badge badge-warning"><span class="badge-dot"></span> <?= e($p['stock']) ?> (Low)</span>
                                <?php else: ?>
                                    <span class="badge badge-success"><span class="badge-dot"></span> <?= e($p['stock']) ?> (Healthy)</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= e($p['reorder_point']) ?> units</strong></td>
                            <td style="color: var(--muted);"><?= e($p['safety_stock']) ?> units</td>
                            <td>
                                <span style="font-weight: 600;"><?= e($p['supplier_name'] ?: 'None') ?></span>
                                <?php if ($p['lead_time_days']): ?>
                                    <div style="font-size: 0.725rem; color: var(--muted);"><?= e($p['lead_time_days']) ?>d lead time</div>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="product_form.php?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
                                <?php if (isAdmin()): ?>
                                    <form method="POST" action="products.php" style="display: inline-block;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger btn-confirm" data-confirm="Delete product '<?= e($p['name']) ?>'?">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if ($pagination['total_pages'] > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
            <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>&stock_status=<?= urlencode($stockFilter) ?>" class="page-link <?= $i === $pagination['current_page'] ? 'active' : '' ?>">
                <?= $i ?>
            </a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
