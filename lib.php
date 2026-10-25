<?php
declare(strict_types=1);

function cfg(): array {
    static $c = null;
    if ($c === null) {
        $f = __DIR__ . '/config.php';
        if (!is_file($f)) { http_response_code(500); exit('config.php is missing on the server.'); }
        $c = require $f;
    }
    return $c;
}

function db(): PDO {
    static $p = null;
    if ($p === null) {
        $c = cfg();
        $p = new PDO(
            "mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4",
            $c['db_user'], $c['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $p;
}

function start_session(): void {
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
    session_set_cookie_params([
        'lifetime' => 0, 'path' => $path,
        'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Strict',
    ]);
    session_name('mealsid');
    session_start();
}

function out(array $d, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($d);
    exit;
}

function create_tables(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        pass_hash VARCHAR(255) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT "admin",
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS app_state (
        id TINYINT PRIMARY KEY,
        data LONGTEXT NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS state_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        data LONGTEXT NOT NULL,
        saved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        at_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (ip)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

// True if $new is missing anything that exists in $old (used to block deleting for non-admin users).
function lost(mixed $old, mixed $new): bool {
    if (!is_array($old)) return false;
    if (!is_array($new)) return true;
    foreach ($old as $k => $v) {
        if (!array_key_exists($k, $new)) return true;
        if (lost($v, $new[$k])) return true;
    }
    return false;
}
