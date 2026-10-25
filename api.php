<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
start_session();

$a = $_GET['a'] ?? '';
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if ($post && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) out(['ok' => false], 415);
$body = json_decode(file_get_contents('php://input') ?: '', true);
$authed = !empty($_SESSION['uid']);

try {
    switch ($a) {
        case 'me':
            out($authed ? ['ok' => true, 'user' => $_SESSION['user']] : ['ok' => false]);

        case 'login':
            if (!$post) out(['ok' => false], 405);
            $pdo = db();
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND at_time > (NOW() - INTERVAL 15 MINUTE)');
            $st->execute([$ip]);
            if ((int)$st->fetchColumn() >= 8) out(['ok' => false, 'error' => 'Too many attempts. Try again in 15 minutes.'], 429);
            $u = trim((string)($body['u'] ?? ''));
            $p = (string)($body['p'] ?? '');
            $st = $pdo->prepare('SELECT id, username, pass_hash FROM users WHERE username = ?');
            $st->execute([$u]);
            $r = $st->fetch();
            if ($r && password_verify($p, $r['pass_hash'])) {
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$r['id'];
                $_SESSION['user'] = $r['username'];
                $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
                out(['ok' => true]);
            }
            $pdo->prepare('INSERT INTO login_attempts (ip) VALUES (?)')->execute([$ip]);
            usleep(700000);
            out(['ok' => false, 'error' => 'Wrong username or password.'], 401);

        case 'logout':
            $_SESSION = [];
            session_destroy();
            out(['ok' => true]);

        case 'load':
            if (!$authed) out(['ok' => false], 401);
            $r = db()->query('SELECT data FROM app_state WHERE id = 1')->fetch();
            out(['ok' => true, 'json' => $r ? $r['data'] : null]);

        case 'save':
            if (!$authed || !$post) out(['ok' => false], $authed ? 405 : 401);
            $j = (string)($body['json'] ?? '');
            $d = json_decode($j, true);
            if (!is_array($d) || !isset($d['items']) || !is_array($d['items'])) out(['ok' => false, 'error' => 'Invalid data'], 400);
            if (strlen($j) > 8000000) out(['ok' => false, 'error' => 'Too large'], 413);
            $pdo = db();
            $pdo->prepare('INSERT INTO app_state (id, data) VALUES (1, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')->execute([$j]);
            $recent = (int)$pdo->query('SELECT COUNT(*) FROM state_history WHERE saved_at > (NOW() - INTERVAL 30 MINUTE)')->fetchColumn();
            if ($recent === 0) {
                $pdo->prepare('INSERT INTO state_history (data) VALUES (?)')->execute([$j]);
                $pdo->exec('DELETE FROM state_history WHERE id <= (SELECT m FROM (SELECT COALESCE(MAX(id), 0) - 200 AS m FROM state_history) t)');
            }
            out(['ok' => true]);

        default:
            out(['ok' => false], 404);
    }
} catch (Throwable $e) {
    error_log($e->getMessage());
    out(['ok' => false, 'error' => 'Server error'], 500);
}
