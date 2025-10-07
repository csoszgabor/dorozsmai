<?php
// inc/bootstrap.php
declare(strict_types=1);
session_start();

// ===========================
// Alapbeállítások
// ===========================
define('APP_ROOT', dirname(__DIR__));
define('BASE_URL', rtrim((isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME'])
, '/'));

define('DB_PATH', APP_ROOT . '/data/app.sqlite');

// ===========================
// SQLite kapcsolat
// ===========================
try {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON;");
} catch (Throwable $e) {
    http_response_code(500);
    exit('<b>Adatbázis-hiba:</b> ' . htmlspecialchars($e->getMessage()));
}

// ===========================
// Segédfüggvények
// ===========================
function fetchAll(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function is_new_badge(array $row): bool {
    if (!empty($row['is_new'])) return true;
    if (!empty($row['created_at']) && strtotime($row['created_at']) > strtotime('-21 days')) {
        return true;
    }
    return false;
}
