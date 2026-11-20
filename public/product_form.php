<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin();

$db = getDB();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;
$pageTitle = $isEdit ? 'Edit Product' : 'Add New Product';

$product = [
    'sku'           => '',
    'name'          => '',
    'description'   => '',
    'category'      => '',
    'unit_price'    => '0.00',
    'stock'         => 0,
    'reorder_point' => 10,
    'safety_stock'  => 5,
    'supplier_id'   => '',
];

if ($isEdit) {
    $stmt = $db->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $fetched = $stmt->fetch();
    if (!$fetched) {
        setFlash('error', 'Product not found.');
        redirect('products.php');
    }
    $product = $fetched;
}

// Fetch suppliers for dropdown
$suppliersStmt = $db->query('SELECT id, name, lead_time_days FROM suppliers ORDER BY name ASC');
$suppliers = $suppliersStmt->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $sku = strtoupper(trim($_POST['sku'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $unitPrice = (float)($_POST['unit_price'] ?? 0);
        $reorderPoint = (int)($_POST['reorder_point'] ?? 0);
        $safetyStock = (int)($_POST['safety_stock'] ?? 0);
        $supplierId = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
        $initialStock = !$isEdit ? max(0, (int)($_POST['initial_stock'] ?? 0)) : $product['stock'];

        // Validation
        if ($sku === '') {
            $errors[] = 'SKU is required.';
        }
        if ($name === '') {
            $errors[] = 'Product name is required.';
        }
        if ($unitPrice < 0) {
            $errors[] = 'Unit price must be non-negative.';
        }
        if ($reorderPoint < 0) {
            $errors[] = 'Reorder point must be 0 or higher.';
        }
        if ($safetyStock < 0) {
            $errors[] = 'Safety stock must be 0 or higher.';
        }
        if ($reorderPoint < $safetyStock) {
            $errors[] = 'Reorder point cannot be lower than safety stock (ROP = Lead Time Demand + Safety Stock).';
        }

        // Check SKU uniqueness
        $skuCheckQuery = 'SELECT id FROM products WHERE sku = ?' . ($isEdit ? ' AND id != ?' : '');
        $skuParams = $isEdit ? [$sku, $id] : [$sku];
        $skuStmt = $db->prepare($skuCheckQuery);
        $skuStmt->execute($skuParams);
        if ($skuStmt->fetch()) {
            $errors[] = "A product with SKU '$sku' already exists.";
        }

        if (empty($errors)) {
            if ($isEdit) {
                $stmt = $db->prepare('
                    UPDATE products
                    SET sku = ?, name = ?, description = ?, category = ?, unit_price = ?,
                        reorder_point = ?, safety_stock = ?, supplier_id = ?
                    WHERE id = ?
                ');
                $stmt->execute([$sku, $name, $description, $category, $unitPrice, $reorderPoint, $safetyStock, $supplierId, $id]);
                setFlash('success', 'Product updated successfully.');
                redirect('products.php');
            } else {
                $db->beginTransaction();
                try {
                    $stmt = $db->prepare('
                        INSERT INTO products (sku, name, description, category, unit_price, stock, reorder_point, safety_stock, supplier_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ');
                    $stmt->execute([$sku, $name, $description, $category, $unitPrice, $initialStock, $reorderPoint, $safetyStock, $supplierId]);
                    $newId = (int)$db->lastInsertId();

                    // If initial stock was given, record movement
                    if ($initialStock > 0) {
                        $currentUser = getCurrentUser();
                        $mvStmt = $db->prepare('
                            INSERT INTO stock_movements (product_id, type, quantity, reference, notes, user_id)
                            VALUES (?, "in", ?, "INIT-STOCK", "Initial stock on product creation", ?)
                        ');
                        $mvStmt->execute([$newId, $initialStock, $currentUser['id'] ?? null]);
                    }

                    $db->commit();
                    setFlash('success', 'Product created successfully.');
                    redirect('products.php');
                } catch (Exception $e) {
                    $db->rollBack();
                    $errors[] = 'Failed to create product: ' . $e->getMessage();
                }
            }
        }

        // Keep post values for form re-render on error
        $product['sku'] = $sku;
        $product['name'] = $name;
        $product['description'] = $description;
        $product['category'] = $category;
        $product['unit_price'] = $unitPrice;
        $product['reorder_point'] = $reorderPoint;
        $product['safety_stock'] = $safetyStock;
        $product['supplier_id'] = $supplierId;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><?= $isEdit ? 'Edit Product' : 'Add New Product' ?></h1>
    <a href="products.php" class="btn btn-secondary">&larr; Back to Products</a>
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

<div class="card" style="max-width: 800px; margin: 0 auto;">
    <form method="POST" action="product_form.php<?= $isEdit ? '?id=' . $id : '' ?>">
        <?= csrfField() ?>

        <div class="form-row">
            <div class="form-group">
                <label for="sku">SKU (Stock Keeping Unit) *</label>
                <input type="text" id="sku" name="sku" class="form-control" placeholder="e.g. ELEC-WLS-MOU" value="<?= e($product['sku']) ?>" required>
                <div class="form-help">Unique uppercase identifier</div>
            </div>

            <div class="form-group">
                <label for="category">Category</label>
                <input type="text" id="category" name="category" class="form-control" placeholder="e.g. Electronics, Office Supplies" value="<?= e($product['category']) ?>" list="categories-list">
                <datalist id="categories-list">
                    <option value="Electronics">
                    <option value="Office Supplies">
                    <option value="Cleaning">
                    <option value="Hardware">
                    <option value="Networking">
                </datalist>
            </div>
        </div>

        <div class="form-group">
            <label for="name">Product Name *</label>
            <input type="text" id="name" name="name" class="form-control" placeholder="e.g. Wireless Ergonomic Mouse" value="<?= e($product['name']) ?>" required>
        </div>

        <div class="form-group">
            <label for="description">Description</label>
            <textarea id="description" name="description" class="form-control" rows="3" placeholder="Detailed product specifications, notes, packaging details..."><?= e($product['description']) ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="unit_price">Unit Price (₹) *</label>
                <input type="number" id="unit_price" name="unit_price" class="form-control" step="0.01" min="0" value="<?= e($product['unit_price']) ?>" required>
            </div>

            <div class="form-group">
                <label for="supplier_id">Assigned Supplier</label>
                <select id="supplier_id" name="supplier_id" class="form-control">
                    <option value="">-- No Supplier Assigned --</option>
                    <?php foreach ($suppliers as $sup): ?>
                        <option value="<?= (int)$sup['id'] ?>" <?= ((string)$product['supplier_id'] === (string)$sup['id']) ? 'selected' : '' ?>>
                            <?= e($sup['name']) ?> (Lead time: <?= e($sup['lead_time_days']) ?> days)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="safety_stock">Safety Stock (Buffer) *</label>
                <input type="number" id="safety_stock" name="safety_stock" class="form-control" min="0" value="<?= e($product['safety_stock']) ?>" required>
                <div class="form-help">Calculated automatically by replenishment engine, or set manually</div>
            </div>

            <div class="form-group">
                <label for="reorder_point">Reorder Point (Threshold) *</label>
                <input type="number" id="reorder_point" name="reorder_point" class="form-control" min="0" value="<?= e($product['reorder_point']) ?>" required>
                <div class="form-help">When stock ≤ this, PO is triggered: (avg usage × lead time) + safety stock</div>
            </div>

            <?php if (!$isEdit): ?>
                <div class="form-group">
                    <label for="initial_stock">Initial Stock Quantity</label>
                    <input type="number" id="initial_stock" name="initial_stock" class="form-control" min="0" value="0">
                    <div class="form-help">Will generate an initial 'IN' movement</div>
                </div>
            <?php else: ?>
                <div class="form-group">
                    <label>Current Stock (Read-only)</label>
                    <input type="text" class="form-control" value="<?= e($product['stock']) ?> units" disabled style="background:#f1f5f9;">
                    <div class="form-help"><a href="stock_movement.php">Adjust via Stock In/Out</a></div>
                </div>
            <?php endif; ?>
        </div>

        <div style="margin-top: 24px; display: flex; gap: 12px;">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Create Product' ?></button>
            <a href="products.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
