<?php
require_once __DIR__ . '/config.php';

function e(?string $string): string {
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void {
    header("Location: $url");
    exit;
}

function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = [
        'type'    => $type, // success, error, warning, info
        'message' => $message,
    ];
}

function getFlash(): ?array {
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function formatCurrency($amount): string {
    return '₹' . number_format((float)$amount, 2);
}

function formatDate(?string $datetime, string $format = 'd M Y, h:i A'): string {
    if (!$datetime) {
        return '-';
    }
    $ts = strtotime($datetime);
    return $ts ? date($format, $ts) : '-';
}

function paginate(int $totalItems, int $perPage, int $currentPage): array {
    $totalPages = max(1, (int)ceil($totalItems / $perPage));
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset = ($currentPage - 1) * $perPage;

    return [
        'total'        => $totalItems,
        'per_page'     => $perPage,
        'current_page' => $currentPage,
        'total_pages'  => $totalPages,
        'offset'       => $offset,
    ];
}
