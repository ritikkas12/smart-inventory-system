<?php
require_once __DIR__ . '/config.php';

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Friendly error message for users if DB not yet configured
            die('<div style="font-family:sans-serif;padding:30px;background:#fef2f2;color:#991b1b;border:1px solid #f87171;border-radius:8px;max-width:600px;margin:50px auto;">' .
                '<h3>Database Connection Error</h3>' .
                '<p>' . htmlspecialchars($e->getMessage()) . '</p>' .
                '<p>Make sure MySQL is running and the database <code>' . DB_NAME . '</code> has been imported with <code>schema.sql</code> and <code>seed.sql</code>.</p>' .
                '</div>');
        }
    }
    return $pdo;
}
