<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'id'       => $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? '',
        'role'     => $_SESSION['user_role'] ?? 'staff',
        'email'    => $_SESSION['user_email'] ?? '',
    ];
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function isAdmin(): bool {
    $user = getCurrentUser();
    return $user && ($user['role'] ?? '') === 'admin';
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        setFlash('error', 'Access denied: Administrator privileges required.');
        redirect('index.php');
    }
}

function loginUser(string $username, string $password): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT id, username, password_hash, role, email FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        // Regenerate session ID and CSRF token to prevent session fixation
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_email'] = $user['email'];
        return true;
    }
    return false;
}

function logoutUser(): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
    header('Location: login.php');
    exit;
}
