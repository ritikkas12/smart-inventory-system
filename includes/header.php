<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/helpers.php';

$currentPageName = basename($_SERVER['SCRIPT_NAME']);
$currentUser = getCurrentUser();
$flash = getFlash();

// Generate user initials for avatar
$initials = 'U';
if ($currentUser && !empty($currentUser['username'])) {
    $initials = strtoupper(substr($currentUser['username'], 0, 2));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?><?= e(APP_NAME) ?></title>
    
    <!-- Google Fonts: Plus Jakarta Sans & Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <!-- Stylesheet -->
    <link rel="stylesheet" href="assets/css/style.css">
    
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<?php if (isLoggedIn()): ?>
<header class="navbar">
    <div class="nav-container">
        <a class="nav-brand" href="index.php">
            <div class="brand-logo">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                    <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                    <line x1="12" y1="22.08" x2="12" y2="12"></line>
                </svg>
            </div>
            <div class="brand-text">
                <span class="brand-title"><?= e(APP_NAME) ?></span>
                <span class="brand-badge">PRO</span>
            </div>
        </a>

        <nav class="nav-menu">
            <a href="index.php" class="nav-item <?= $currentPageName === 'index.php' ? 'active' : '' ?>">
                <svg class="nav-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                Dashboard
            </a>
            <a href="products.php" class="nav-item <?= in_array($currentPageName, ['products.php', 'product_form.php']) ? 'active' : '' ?>">
                <svg class="nav-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"></path><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"></path><path d="m3.3 7 8.7 5 8.7-5"></path><path d="M12 22V12"></path></svg>
                Products
            </a>
            <a href="suppliers.php" class="nav-item <?= in_array($currentPageName, ['suppliers.php', 'supplier_form.php']) ? 'active' : '' ?>">
                <svg class="nav-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                Suppliers
            </a>
            <a href="stock_movement.php" class="nav-item <?= $currentPageName === 'stock_movement.php' ? 'active' : '' ?>">
                <svg class="nav-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path></svg>
                Stock In/Out
            </a>
            <a href="purchase_orders.php" class="nav-item <?= $currentPageName === 'purchase_orders.php' ? 'active' : '' ?>">
                <svg class="nav-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"></path><path d="M3 6h18"></path><path d="M16 10a4 4 0 0 1-8 0"></path></svg>
                Orders
            </a>
            <?php if (isAdmin()): ?>
            <a href="reports.php" class="nav-item <?= $currentPageName === 'reports.php' ? 'active' : '' ?>">
                <svg class="nav-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                Analytics
            </a>
            <?php endif; ?>
        </nav>

        <div class="nav-user">
            <div class="user-profile">
                <div class="user-avatar <?= isAdmin() ? 'admin' : 'staff' ?>"><?= e($initials) ?></div>
                <div class="user-meta">
                    <span class="user-name"><?= e($currentUser['username']) ?></span>
                    <span class="user-role-badge <?= isAdmin() ? 'admin' : 'staff' ?>"><?= isAdmin() ? 'Admin' : 'Staff' ?></span>
                </div>
            </div>
            <a href="logout.php" class="btn-logout" title="Sign Out">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
            </a>
        </div>
    </div>
</header>
<?php endif; ?>

<main class="container">
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type'] === 'error' ? 'danger' : $flash['type']) ?> alert-dismissible">
            <div class="alert-icon">
                <?php if ($flash['type'] === 'success'): ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                <?php elseif ($flash['type'] === 'error'): ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                <?php else: ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <?php endif; ?>
            </div>
            <div class="alert-message"><?= e($flash['message']) ?></div>
            <button type="button" class="alert-close" onclick="this.parentElement.remove()">&times;</button>
        </div>
    <?php endif; ?>
