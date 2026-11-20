<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

if (isLoggedIn()) {
    redirect('index.php');
}

$pageTitle = 'Sign In';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'Please enter both your username and password.';
        } elseif (loginUser($username, $password)) {
            setFlash('success', "Welcome back, $username!");
            redirect('index.php');
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <div class="brand-logo" style="width: 52px; height: 52px; margin: 0 auto 16px; border-radius: var(--radius-md);">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                    <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                    <line x1="12" y1="22.08" x2="12" y2="12"></line>
                </svg>
            </div>
            <h1>Welcome Back</h1>
            <p style="color: var(--muted); font-size: 0.9rem; margin-top: 4px;">Sign in to access your inventory workspace</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin-bottom: 20px;">
                <div class="alert-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                </div>
                <div class="alert-message"><?= e($error) ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <?= csrfField() ?>
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" class="form-control" placeholder="admin or staff" value="<?= e($_POST['username'] ?? '') ?>" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 8px; padding: 12px;">
                Sign In to Workspace &rarr;
            </button>
        </form>

        <div style="margin-top: 28px; padding-top: 20px; border-top: 1px solid var(--border-light); font-size: 0.8rem; color: var(--muted); text-align: center;">
            <div style="font-weight: 700; color: var(--slate-600); margin-bottom: 8px;">Demo Accounts:</div>
            <div style="display: flex; justify-content: center; gap: 8px; flex-wrap: wrap;">
                <span class="sku-pill" style="cursor: pointer;" onclick="document.getElementById('username').value='admin'; document.getElementById('password').value='password';">admin / password</span>
                <span class="sku-pill" style="cursor: pointer;" onclick="document.getElementById('username').value='staff'; document.getElementById('password').value='password';">staff / password</span>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
