<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
requireAdmin();

$db = getDB();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;
$pageTitle = $isEdit ? 'Edit Supplier' : 'Add New Supplier';

$supplier = [
    'name'           => '',
    'contact_email'  => '',
    'phone'          => '',
    'address'        => '',
    'lead_time_days' => 7,
];

if ($isEdit) {
    $stmt = $db->prepare('SELECT * FROM suppliers WHERE id = ?');
    $stmt->execute([$id]);
    $fetched = $stmt->fetch();
    if (!$fetched) {
        setFlash('error', 'Supplier not found.');
        redirect('suppliers.php');
    }
    $supplier = $fetched;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['contact_email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $leadTime = (int)($_POST['lead_time_days'] ?? 7);

        if ($name === '') {
            $errors[] = 'Supplier name is required.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if ($leadTime < 1) {
            $errors[] = 'Lead time must be at least 1 day.';
        }

        if (empty($errors)) {
            if ($isEdit) {
                $stmt = $db->prepare('
                    UPDATE suppliers
                    SET name = ?, contact_email = ?, phone = ?, address = ?, lead_time_days = ?
                    WHERE id = ?
                ');
                $stmt->execute([$name, $email, $phone, $address, $leadTime, $id]);
                setFlash('success', 'Supplier updated successfully.');
            } else {
                $stmt = $db->prepare('
                    INSERT INTO suppliers (name, contact_email, phone, address, lead_time_days)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $stmt->execute([$name, $email, $phone, $address, $leadTime]);
                setFlash('success', 'Supplier created successfully.');
            }
            redirect('suppliers.php');
        }

        $supplier['name'] = $name;
        $supplier['contact_email'] = $email;
        $supplier['phone'] = $phone;
        $supplier['address'] = $address;
        $supplier['lead_time_days'] = $leadTime;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><?= $isEdit ? 'Edit Supplier' : 'Add New Supplier' ?></h1>
    <a href="suppliers.php" class="btn btn-secondary">&larr; Back to Suppliers</a>
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

<div class="card" style="max-width: 700px; margin: 0 auto;">
    <form method="POST" action="supplier_form.php<?= $isEdit ? '?id=' . $id : '' ?>">
        <?= csrfField() ?>

        <div class="form-group">
            <label for="name">Supplier / Vendor Name *</label>
            <input type="text" id="name" name="name" class="form-control" placeholder="e.g. Apex Electronics Ltd" value="<?= e($supplier['name']) ?>" required>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="contact_email">Contact Email</label>
                <input type="email" id="contact_email" name="contact_email" class="form-control" placeholder="sales@supplier.com" value="<?= e($supplier['contact_email']) ?>">
            </div>

            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input type="text" id="phone" name="phone" class="form-control" placeholder="+1-555-0100" value="<?= e($supplier['phone']) ?>">
            </div>
        </div>

        <div class="form-group">
            <label for="lead_time_days">Supplier Lead Time (Days) *</label>
            <input type="number" id="lead_time_days" name="lead_time_days" class="form-control" min="1" max="180" value="<?= e($supplier['lead_time_days']) ?>" required>
            <div class="form-help">Average calendar days between order dispatch and arrival at warehouse. Used for safety stock and replenishment calculations.</div>
        </div>

        <div class="form-group">
            <label for="address">Address / Location</label>
            <textarea id="address" name="address" class="form-control" rows="3" placeholder="Physical location, warehouse address or distribution center..."><?= e($supplier['address']) ?></textarea>
        </div>

        <div style="margin-top: 24px; display: flex; gap: 12px;">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Create Supplier' ?></button>
            <a href="suppliers.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
