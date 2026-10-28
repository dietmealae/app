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
        ver INT NOT NULL DEFAULT 0,
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

// Adds the version column on servers that were set up before versions existed.
function ensure_ver(PDO $pdo): void {
    try { $pdo->query('SELECT ver FROM app_state LIMIT 1'); }
    catch (PDOException $e) { $pdo->exec('ALTER TABLE app_state ADD COLUMN ver INT NOT NULL DEFAULT 0'); }
}

// Tables for customers, orders, meals, payments (Phase 3). Safe to call on every request.
function ensure_crm(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        phone VARCHAR(40) NOT NULL DEFAULT "",
        notes TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS addresses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        label VARCHAR(60) NOT NULL DEFAULT "",
        area VARCHAR(80) NOT NULL DEFAULT "",
        address VARCHAR(255) NOT NULL DEFAULT "",
        map_url VARCHAR(500) NOT NULL DEFAULT "",
        notes VARCHAR(255) NOT NULL DEFAULT "",
        INDEX (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        address_id INT NULL,
        category CHAR(2) NOT NULL,
        meal_type CHAR(1) NOT NULL,
        plan_days SMALLINT NOT NULL,
        start_date DATE NOT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0,
        status VARCHAR(12) NOT NULL DEFAULT "active",
        notes VARCHAR(500) NULL,
        created_by VARCHAR(50) NOT NULL DEFAULT "",
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS order_meals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        serve_date DATE NOT NULL,
        meal CHAR(1) NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT "scheduled",
        moved_from DATE NULL,
        INDEX (order_id),
        INDEX (serve_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        paid_on DATE NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        method VARCHAR(20) NOT NULL DEFAULT "cash",
        note VARCHAR(255) NULL,
        created_by VARCHAR(50) NOT NULL DEFAULT "",
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS holidays (
        hdate DATE PRIMARY KEY,
        note VARCHAR(100) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
        skey VARCHAR(40) PRIMARY KEY,
        sval VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS drivers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        phone VARCHAR(40) NOT NULL DEFAULT ""
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // schema_v2: delivery persons, per-meal address, postponement destination
    $v = $pdo->query('SELECT sval FROM settings WHERE skey = "schema"')->fetchColumn();
    if ($v === false || (int)$v < 2) {
        $cols = [
            ['order_meals', 'address_id', 'INT NULL'],
            ['order_meals', 'driver_id', 'INT NULL'],
            ['order_meals', 'route_seq', 'INT NULL'],
            ['order_meals', 'moved_to', 'DATE NULL'],
            ['orders', 'driver_id', 'INT NULL'],
        ];
        foreach ($cols as [$t, $c, $def]) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $st->execute([$t, $c]);
            if ((int)$st->fetchColumn() === 0) $pdo->exec("ALTER TABLE $t ADD COLUMN $c $def");
        }
        $pdo->exec('INSERT INTO settings (skey, sval) VALUES ("schema", "2") ON DUPLICATE KEY UPDATE sval = "2"');
    }
}
