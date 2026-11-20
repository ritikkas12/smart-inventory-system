<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

// Authentication check
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Please log in.']);
    exit;
}

$productId = (int)($_GET['product_id'] ?? 0);
if ($productId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid product_id parameter.']);
    exit;
}

$db = getDB();

// 1. Fetch Product
$prodStmt = $db->prepare('SELECT id, sku, name, stock, reorder_point, safety_stock FROM products WHERE id = ?');
$prodStmt->execute([$productId]);
$product = $prodStmt->fetch();

if (!$product) {
    http_response_code(404);
    echo json_encode(['error' => 'Product not found.']);
    exit;
}

// 2. Fetch Actual Usage (Daily OUT) over the last 30 days
$actualStmt = $db->prepare('
    SELECT DATE(moved_at) AS date, SUM(quantity) AS quantity
    FROM stock_movements
    WHERE product_id = ?
      AND type = "out"
      AND moved_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(moved_at)
    ORDER BY date ASC
');
$actualStmt->execute([$productId]);
$actualMovements = $actualStmt->fetchAll();

// Fill in missing dates with 0 so the timeline chart is continuous
$actualMap = [];
foreach ($actualMovements as $row) {
    $actualMap[$row['date']] = (float)$row['quantity'];
}

$continuousActuals = [];
$startDate = new DateTime('today - 30 days');
$endDate = new DateTime('today');

while ($startDate <= $endDate) {
    $dStr = $startDate->format('Y-m-d');
    $continuousActuals[] = [
        'date'     => $dStr,
        'quantity' => $actualMap[$dStr] ?? 0.0,
    ];
    $startDate->modify('+1 day');
}

// 3. Fetch Forecasts from today onwards (next 14 days)
$forecastStmt = $db->prepare('
    SELECT forecast_date AS date, predicted_qty, model_used
    FROM forecasts
    WHERE product_id = ?
      AND forecast_date >= CURDATE()
    ORDER BY forecast_date ASC
    LIMIT 14
');
$forecastStmt->execute([$productId]);
$forecasts = $forecastStmt->fetchAll();

echo json_encode([
    'product'  => $product,
    'actual'   => $continuousActuals,
    'forecast' => $forecasts,
], JSON_UNESCAPED_SLASHES);
