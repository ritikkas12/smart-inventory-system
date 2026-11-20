<?php
// Enable output buffering to prevent "headers already sent" issues
if (ob_get_level() === 0) {
    ob_start();
}

// Prevent session started multiple times
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'smart_inventory');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

// App settings
define('APP_NAME', 'Smart Inventory');
define('APP_URL', getenv('APP_URL') ?: 'http://localhost:8080');
define('ITEMS_PER_PAGE', 15);

// Safety stock service level Z-score (95% service level = 1.65)
define('SERVICE_LEVEL_Z', 1.65);

// Review period in days for auto-replenishment calculation
define('REVIEW_PERIOD_DAYS', 7);

// Default timezone
date_default_timezone_set('Asia/Kolkata');
